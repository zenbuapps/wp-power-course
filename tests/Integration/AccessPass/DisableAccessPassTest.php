<?php
/**
 * 停用課程權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/停用課程權限包.feature
 * Issue #252：課程通行證（Access Pass）CRUD API
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Crud 類別尚未實作
 *   - Crud::disable 方法不存在
 *
 * 注意：「停用後已購學員仍可觀看」example 依賴 R3 的 Gate（觀看判定）。
 * 本批先不寫該 method，留 R3 補。
 *
 * @group access-pass
 * @group disable
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class DisableAccessPassTest
 * 驗證課程權限包的停用業務邏輯（對映 停用課程權限包.feature 的所有 Rule/Example）
 *
 * 已排除方法（R3 Gate 待補）：
 *   - test_停用後已購學員仍可觀看() → 依賴觀看判定 Gate，R3 再補
 */
class DisableAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 學員 ID */
	private int $student_id;

	/** @var int 課程 ID（100 HTML 入門課）*/
	private int $course_100;

	/** @var int 一次性商品 ID（500 HTML 系列包）*/
	private int $product_500;

	/** @var int active 全站永久權限包（pass_300）*/
	private int $pass_300;

	/** @var int active category 永久權限包（pass_301，已掛商品 500）*/
	private int $pass_301;

	/** @var int 課程分類 term_id（HTML）*/
	private int $term_html;

	/**
	 * 初始化依賴（Crud 使用靜態方法，不需注入）
	 */
	protected function configure_dependencies(): void {
		// Crud::class 尚未實作，Red 階段不初始化
	}

	/**
	 * 每個測試前建立 Background fixture
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立管理員
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_' . uniqid(),
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;

		// Background：建立學員
		$this->student_id = $this->factory()->user->create(
			[
				'user_login' => 'student_' . uniqid(),
				'role'       => 'subscriber',
			]
		);
		$this->ids['Student'] = $this->student_id;

		\wp_set_current_user( $this->admin_id );

		// Background：建立課程分類 term（HTML）
		$html_result     = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_html = \is_wp_error( $html_result ) ? 0 : (int) $html_result['term_id'];

		// Background：建立課程（HTML 入門課，掛 HTML 分類 term，落在 pass_301 的 category 範圍內）
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		\wp_set_object_terms( $this->course_100, $this->term_html, 'product_cat' );
		$this->ids['course_100'] = $this->course_100;

		// Background：建立一次性商品（500 HTML 系列包）
		$this->product_500 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => 'HTML 系列包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_500, '_price', '999' );
		$this->ids['product_500'] = $this->product_500;

		// Background：建立 active 全站永久權限包（pass_300，未掛商品）
		$this->pass_300 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '全站課程權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_300, 'scope_type', 'all' );
		\update_post_meta( $this->pass_300, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_300, 'access_pass_status', 'active' );
		$this->ids['pass_300'] = $this->pass_300;

		// Background：建立 active category 權限包（pass_301，掛商品 500）
		$this->pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => 'HTML 權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_301, 'scope_type', 'category' );
		\update_post_meta( $this->pass_301, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_301, 'access_pass_status', 'active' );
		\add_post_meta( $this->pass_301, 'scope_term_ids', $this->term_html, false );
		$this->ids['pass_301'] = $this->pass_301;

		// Background：商品 500 掛載了課程權限包 pass_301
		\update_post_meta( $this->product_500, 'access_pass_id', $this->pass_301 );
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT 與 product meta
	 */
	public function tear_down(): void {
		\delete_post_meta( $this->product_500, 'access_pass_id' );

		$passes = \get_posts(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $passes as $pass_id ) {
			\wp_force_delete_post( $pass_id );
		}
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Crud::disable 方法存在（Red：預期失敗）
	 */
	public function test_crud_disable_方法存在(): void {
		$this->assertTrue(
			\method_exists( Crud::class, 'disable' ),
			'Crud::disable 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被停用的權限包必須存在
	 *
	 * Example: 停用不存在的權限包時操作失敗（passId=9999）
	 */
	public function test_停用不存在的權限包時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::disable( 9999 );
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 停用後權限包狀態變為 disabled
	 *
	 * Example: 成功停用權限包
	 *   When 管理員停用 pass_300
	 *   Then pass_300 的 status 應為 "disabled"
	 */
	public function test_成功停用後status變為disabled(): void {
		Crud::disable( $this->pass_300 );

		$status = \get_post_meta( $this->pass_300, 'access_pass_status', true );
		$this->assertSame( 'disabled', $status, 'pass_300 的 status 應改為 disabled' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 停用後權限包不可再掛載到新商品
	 *
	 * Example: 停用的權限包無法掛載到新商品
	 *   Given pass_300 已停用
	 *   When 管理員試圖將 pass_300 掛載到商品 501
	 *   Then 操作失敗
	 *
	 * 備注：此 example 驗證「停用後 attach 操作被拒絕」，與 AttachAccessPassTest::test_掛載disabled權限包時失敗 互補。
	 */
	public function test_停用後不可再掛到新商品(): void {
		// Given：先建立新商品
		$product_new = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '新商品 G',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $product_new, '_price', '599' );

		// Given：先停用 pass_300
		Crud::disable( $this->pass_300 );

		// Then：停用後 status 應為 disabled
		$this->assertSame(
			'disabled',
			\get_post_meta( $this->pass_300, 'access_pass_status', true ),
			'前置確認：pass_300 應已停用'
		);

		// When/Then：再嘗試掛載應失敗
		$this->expectException( \RuntimeException::class );

		// 依賴 Crud::attach_to_product（同樣尚未實作，Red 雙重失敗）
		\J7\PowerCourse\Resources\AccessPass\Service\Crud::attach_to_product( $this->pass_300, $product_new );
	}

	// ========== 已補充方法（R3 Gate — Issue #252 本批補入）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 停用後已購用戶的觀看權限仍保留
	 *
	 * Example: 停用權限包後已購學員仍可觀看範圍內課程
	 *   Given 學員 "Student" 已透過商品 500 取得課程權限包 pass_301（持有關係已在 pc_user_access_pass）
	 *   When 管理員停用 pass_301
	 *   Then 學員 "Student" 對課程 100 的觀看權限應為「可觀看」
	 *
	 * 與「刪除」的差異：停用（disable）≠ 刪除（delete）。
	 *   停用：status 改為 "disabled"，已購學員持有關係保留，觀看權限不收回。
	 *   刪除：pass CPT 與持有列均移除，已購學員失去觀看權限。
	 *
	 * 預期失敗原因（Red 階段）：
	 *   - Gate::user_has_valid_pass_for_course 尚未實作
	 *   - Crud::disable 尚未實作
	 *   - 停用後 Gate 是否仍辨識「disabled 但持有」的觀看關係尚未建立
	 */
	public function test_停用後已購學員仍可觀看(): void {
		// 確認 Gate 類別存在（若 Gate 尚未實作，此測試會在此 fail）
		if ( ! \class_exists( \J7\PowerCourse\Resources\AccessPass\Service\Gate::class ) ) {
			$this->fail( 'Gate::class 不存在，無法驗證停用後觀看判定行為' );
		}

		// Given：Student 持有 pass_301（模擬已透過商品 500 購買取得）
		\J7\PowerCourse\Resources\AccessPass\Service\Repository::insert_or_update(
			$this->student_id,
			$this->pass_301,
			null,
			null
		);

		// 確認停用前 Student 可觀看 course_100（前置確認）
		$before = \J7\PowerCourse\Resources\AccessPass\Service\Gate::user_has_valid_pass_for_course(
			$this->student_id,
			$this->course_100
		);
		$this->assertTrue( $before, '前置確認：停用前 Student 應可觀看 course_100（透過 pass_301）' );

		// When：管理員停用 pass_301
		Crud::disable( $this->pass_301 );

		// Then：pass_301 status 應為 "disabled"
		$status = \get_post_meta( $this->pass_301, 'access_pass_status', true );
		$this->assertSame( 'disabled', $status, 'pass_301 應已停用（status=disabled）' );

		// Then：Student 對課程 100 的觀看權限應仍為「可觀看」
		// 業務規則：停用保留已購學員的觀看權，不等於刪除
		$after = \J7\PowerCourse\Resources\AccessPass\Service\Gate::user_has_valid_pass_for_course(
			$this->student_id,
			$this->course_100
		);
		$this->assertTrue(
			$after,
			'停用後已購學員仍應可觀看範圍內課程（停用 ≠ 刪除，持有關係保留）'
		);
	}
}
