<?php
/**
 * 通行證範圍正向展開 整合測試
 *
 * Issue #252 補完：前台「我的通行證」要回答「這張通行證涵蓋哪些課」，
 * 而 Gate 只能回答反向的「這門課有沒有被涵蓋」。Service\Scope 負責正向展開。
 *
 * 關鍵不變式：Scope 與 Gate 必須對同一組 (pass, course) 得到一致的結論，
 * 否則會出現「前台沒列出、實際卻看得到」（或反之）的分歧。
 *
 * @group access-pass
 * @group scope
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Resources\AccessPass\Service\Scope;

/**
 * Class ScopeExpandTest
 */
class ScopeExpandTest extends TestCase {

	/** @var int 父分類 term */
	private int $parent_term;

	/** @var int 子分類 term */
	private int $child_term;

	/** @var int 掛父分類的課程 */
	private int $course_in_parent;

	/** @var int 掛子分類的課程 */
	private int $course_in_child;

	/** @var int 無分類的課程 */
	private int $course_no_term;

	/** @var int 非課程商品（_is_course=no） */
	private int $non_course_product;

	/** @var int 草稿狀態的課程 */
	private int $draft_course;

	/**
	 * 初始化依賴（Scope 為靜態 Service，不需注入）
	 */
	protected function configure_dependencies(): void {
	}

	/** 建立分類階層與各類課程 */
	public function set_up(): void {
		parent::set_up();

		$parent = \wp_insert_term( '程式設計', 'product_cat' );
		$this->parent_term = \is_wp_error( $parent ) ? 0 : (int) $parent['term_id'];

		$child = \wp_insert_term(
			'前端',
			'product_cat',
			[ 'parent' => $this->parent_term ]
		);
		$this->child_term = \is_wp_error( $child ) ? 0 : (int) $child['term_id'];

		$this->course_in_parent = $this->create_course( [ 'post_title' => '程式設計概論' ] );
		\wp_set_object_terms( $this->course_in_parent, [ $this->parent_term ], 'product_cat' );

		$this->course_in_child = $this->create_course( [ 'post_title' => 'React 實戰' ] );
		\wp_set_object_terms( $this->course_in_child, [ $this->child_term ], 'product_cat' );

		$this->course_no_term = $this->create_course( [ 'post_title' => '無分類課程' ] );

		$this->non_course_product = $this->create_course(
			[
				'post_title' => '一般商品',
				'_is_course' => 'no',
			]
		);

		$this->draft_course = $this->create_course(
			[
				'post_title'  => '未發布課程',
				'post_status' => 'draft',
			]
		);
	}

	/** 清理持有表與 Gate 快取 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Gate::flush_cache();

		parent::tear_down();
	}

	/**
	 * 建立通行證 CPT 並回傳 Model
	 *
	 * @param array<string, mixed> $args scope_type / term_ids / course_ids
	 * @return AccessPass
	 */
	private function create_pass_model( array $args ): AccessPass {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => $args['name'] ?? '範圍測試通行證',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', $args['scope_type'] ?? 'all' );
		\update_post_meta( $pass_id, 'limit_type', 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', 'active' );

		foreach ( (array) ( $args['term_ids'] ?? [] ) as $term_id ) {
			\add_post_meta( $pass_id, 'scope_term_ids', $term_id, false );
		}
		foreach ( (array) ( $args['course_ids'] ?? [] ) as $course_id ) {
			\add_post_meta( $pass_id, 'scope_course_ids', $course_id, false );
		}

		$model = AccessPass::instance( $pass_id );
		$this->assertInstanceOf( AccessPass::class, $model );

		// 每張新通行證都清一次 count transient，避免測試間 id 重用造成髒快取
		Scope::flush_count_cache( $pass_id );

		return $model;
	}

	// ========== Smoke ==========

	/**
	 * @test
	 * @group smoke
	 * Rule: Scope 的兩個公開方法存在
	 */
	public function test_scope_方法存在(): void {
		$this->assertTrue( \method_exists( Scope::class, 'get_course_ids' ), 'Scope::get_course_ids 不存在' );
		$this->assertTrue( \method_exists( Scope::class, 'count_courses' ), 'Scope::count_courses 不存在' );
	}

	// ========== all 範圍 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: all 範圍涵蓋所有已發布的課程商品
	 */
	public function test_all範圍_涵蓋所有已發布課程(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'all' ] );

		$ids = Scope::get_course_ids( $pass, 100, 0 );

		$this->assertContains( $this->course_in_parent, $ids );
		$this->assertContains( $this->course_in_child, $ids );
		$this->assertContains( $this->course_no_term, $ids );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: all 範圍不含非課程商品，也不含未發布的課程
	 *
	 * 前台清單呈現的是學員「實際看得到」的課，草稿 / 一般商品不該出現。
	 */
	public function test_all範圍_排除非課程商品與草稿(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'all' ] );

		$ids = Scope::get_course_ids( $pass, 100, 0 );

		$this->assertNotContains( $this->non_course_product, $ids, '非課程商品不應入列' );
		$this->assertNotContains( $this->draft_course, $ids, '草稿課程不應入列' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: all 是動態範圍——日後新上架的課程自動涵蓋
	 */
	public function test_all範圍_日後新增的課程自動涵蓋(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'all' ] );
		$before = Scope::get_course_ids( $pass, 100, 0 );

		$new_course = $this->create_course( [ 'post_title' => '全新上架課' ] );

		$after = Scope::get_course_ids( $pass, 100, 0 );

		$this->assertNotContains( $new_course, $before );
		$this->assertContains( $new_course, $after, 'all 範圍應自動涵蓋新課' );
	}

	// ========== category 範圍 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 父分類範圍涵蓋子分類底下的課程（與 Gate 的子分類展開一致）
	 */
	public function test_category範圍_父分類涵蓋子分類課程(): void {
		$pass = $this->create_pass_model(
			[
				'scope_type' => 'category',
				'term_ids'   => [ $this->parent_term ],
			]
		);

		$ids = Scope::get_course_ids( $pass, 100, 0 );

		$this->assertContains( $this->course_in_parent, $ids, '父分類本身的課應涵蓋' );
		$this->assertContains( $this->course_in_child, $ids, '子分類的課應一併涵蓋' );
		$this->assertNotContains( $this->course_no_term, $ids, '無分類的課不應涵蓋' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: Scope 的展開結果與 Gate 的反向判定必須一致
	 *
	 * 兩邊分歧會造成「前台沒列出、教室卻看得到」這種最難察覺的錯誤。
	 */
	public function test_category範圍_Scope與Gate判定一致(): void {
		$pass = $this->create_pass_model(
			[
				'scope_type' => 'category',
				'term_ids'   => [ $this->parent_term ],
			]
		);

		$user_id = $this->factory()->user->create();
		Repository::insert_or_update( $user_id, $pass->id, null, '0' );

		$expanded = Scope::get_course_ids( $pass, 100, 0 );

		foreach ( [ $this->course_in_parent, $this->course_in_child, $this->course_no_term ] as $course_id ) {
			$this->assertSame(
				\in_array( $course_id, $expanded, true ),
				Gate::user_has_valid_pass_for_course( $user_id, $course_id ),
				"課程 #{$course_id} 在 Scope 與 Gate 的判定不一致"
			);
		}
	}

	/**
	 * @test
	 * @group error
	 * Rule: category 範圍沒設定任何 term 時涵蓋 0 門課（而非誤判為全站）
	 */
	public function test_category範圍_未設定term時涵蓋0門(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'category' ] );

		$this->assertSame( [], Scope::get_course_ids( $pass, 100, 0 ) );
		$this->assertSame( 0, Scope::count_courses( $pass ) );
	}

	// ========== specific 範圍 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: specific 範圍只涵蓋固定清單，且不隨新課擴張
	 */
	public function test_specific範圍_只涵蓋固定清單且不擴張(): void {
		$pass = $this->create_pass_model(
			[
				'scope_type' => 'specific',
				'course_ids' => [ $this->course_in_parent, $this->course_no_term ],
			]
		);

		$new_course = $this->create_course( [ 'post_title' => 'specific 之後才上架的課' ] );

		$ids = Scope::get_course_ids( $pass, 100, 0 );

		$this->assertContains( $this->course_in_parent, $ids );
		$this->assertContains( $this->course_no_term, $ids );
		$this->assertNotContains( $this->course_in_child, $ids, '清單外的課不應涵蓋' );
		$this->assertNotContains( $new_course, $ids, 'specific 不隨新課擴張' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: specific 範圍沒設定任何課程時涵蓋 0 門
	 */
	public function test_specific範圍_未設定課程時涵蓋0門(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'specific' ] );

		$this->assertSame( [], Scope::get_course_ids( $pass, 100, 0 ) );
		$this->assertSame( 0, Scope::count_courses( $pass ) );
	}

	// ========== 計數與分頁 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: count_courses 與實際展開的筆數一致
	 */
	public function test_count_courses_與展開筆數一致(): void {
		$pass = $this->create_pass_model(
			[
				'scope_type' => 'category',
				'term_ids'   => [ $this->parent_term ],
			]
		);

		$this->assertSame(
			\count( Scope::get_course_ids( $pass, 100, 0 ) ),
			Scope::count_courses( $pass )
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 分頁參數生效——limit 限制筆數、offset 位移
	 */
	public function test_get_course_ids_分頁生效(): void {
		$pass = $this->create_pass_model( [ 'scope_type' => 'all' ] );

		$all         = Scope::get_course_ids( $pass, 100, 0 );
		$first_page  = Scope::get_course_ids( $pass, 2, 0 );
		$second_page = Scope::get_course_ids( $pass, 2, 2 );

		$this->assertGreaterThanOrEqual( 3, \count( $all ), '前置：至少要有 3 門課才驗得出分頁' );
		$this->assertCount( 2, $first_page, 'limit=2 應只回 2 筆' );
		$this->assertSame( \array_slice( $all, 2, 2 ), $second_page, 'offset=2 應接續第一頁' );
	}
}
