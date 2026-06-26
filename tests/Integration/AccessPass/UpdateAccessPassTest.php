<?php
/**
 * 更新課程權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/更新課程權限包.feature
 * Issue #252：課程通行證（Access Pass）CRUD API
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Crud 類別尚未實作
 *   - Crud::update 方法不存在
 *
 * @group access-pass
 * @group update
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class UpdateAccessPassTest
 * 驗證課程權限包的更新業務邏輯（對映 更新課程權限包.feature 的所有 Rule/Example）
 */
class UpdateAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 課程 ID（100 HTML 入門課）*/
	private int $course_100;

	/** @var int 課程 ID（101 HTML 進階課）*/
	private int $course_101;

	/** @var int 課程分類 term_id（HTML）*/
	private int $term_html;

	/** @var int 課程分類 term_id（PHP）*/
	private int $term_php;

	/** @var int 預建 category 範圍權限包 ID（pass 300 equivalent）*/
	private int $pass_300;

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
		\wp_set_current_user( $this->admin_id );

		// Background：建立課程
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		$this->course_101 = $this->create_course( [ 'post_title' => 'HTML 進階課' ] );

		// Background：建立分類 term
		$html_result      = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_html  = \is_wp_error( $html_result ) ? 0 : (int) $html_result['term_id'];

		$php_result       = \wp_insert_term( 'PHP', 'product_cat' );
		$this->term_php   = \is_wp_error( $php_result ) ? 0 : (int) $php_result['term_id'];

		// Background：建立預設 category 範圍永久權限包（對應 feature 的 passId=300）
		// 直接插入 CPT + meta 以繞過 Crud（Crud 尚未實作）
		$this->pass_300 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => 'HTML 入門課程權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_300, 'scope_type', 'category' );
		\update_post_meta( $this->pass_300, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_300, 'access_pass_status', 'active' );
		\add_post_meta( $this->pass_300, 'scope_term_ids', $this->term_html, false );
		$this->ids['pass_300'] = $this->pass_300;
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT
	 */
	public function tear_down(): void {
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
	 * 確認 Crud::update 方法存在（Red：預期失敗）
	 */
	public function test_crud_update_方法存在(): void {
		$this->assertTrue(
			\method_exists( Crud::class, 'update' ),
			'Crud::update 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被更新的權限包必須存在
	 *
	 * Example: 更新不存在的權限包時操作失敗（passId=9999）
	 */
	public function test_更新不存在的權限包時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::update(
			9999,
			[ 'name' => '改名測試' ]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- name 若提供則不可為空字串
	 *
	 * Example: 將名稱更新為空字串時操作失敗，錯誤訊息含 "name"
	 */
	public function test_更新名稱為空字串時失敗(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/name/i' );

		Crud::update(
			$this->pass_300,
			[ 'name' => '' ]
		);
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功更新權限包名稱
	 *
	 * Example: 成功更新權限包名稱
	 *   When 管理員更新 pass_300，name → "HTML 全系列課程權限"
	 *   Then pass_300 的 name 應為 "HTML 全系列課程權限"
	 */
	public function test_成功更新權限包名稱(): void {
		Crud::update(
			$this->pass_300,
			[ 'name' => 'HTML 全系列課程權限' ]
		);

		$post = \get_post( $this->pass_300 );
		$this->assertInstanceOf( \WP_Post::class, $post, '應可取得 WP_Post' );
		$this->assertSame( 'HTML 全系列課程權限', $post->post_title, '名稱應已更新' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功變更範圍類型與範圍內容（category → specific）
	 *
	 * Example: 將分類範圍改為特定課程範圍
	 *   When 管理員更新 pass_300，scope_type → "specific"，course_ids → [100]
	 *   Then pass_300 的 scope_type 應為 "specific"，course_ids 應包含 course_100
	 */
	public function test_成功將category範圍改為specific範圍(): void {
		Crud::update(
			$this->pass_300,
			[
				'scope_type' => 'specific',
				'course_ids' => [ $this->course_100 ],
			]
		);

		$this->assertSame( 'specific', \get_post_meta( $this->pass_300, 'scope_type', true ), 'scope_type 應改為 specific' );

		$stored_course_ids = \get_post_meta( $this->pass_300, 'scope_course_ids', false );
		$this->assertContains( $this->course_100, \array_map( 'intval', $stored_course_ids ), 'course_100 應存在' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功縮小分類範圍（移除一個分類）
	 *
	 * Example: 縮小分類範圍（[10, 20] → [10]）
	 *   Given 雙分類包 pass_301，term_ids=[term_html, term_php]
	 *   When 管理員更新 pass_301，term_ids → [term_html]
	 *   Then pass_301 的 term_ids 只剩 [term_html]
	 */
	public function test_成功縮小分類範圍(): void {
		// Given：建立雙分類包（term_html + term_php）
		$pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '雙分類包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $pass_301, 'scope_type', 'category' );
		\update_post_meta( $pass_301, 'limit_type', 'unlimited' );
		\update_post_meta( $pass_301, 'access_pass_status', 'active' );
		\add_post_meta( $pass_301, 'scope_term_ids', $this->term_html, false );
		\add_post_meta( $pass_301, 'scope_term_ids', $this->term_php, false );

		// When：縮小為只有 term_html
		Crud::update(
			$pass_301,
			[
				'term_ids' => [ $this->term_html ],
			]
		);

		// Then：只剩 term_html
		$stored_term_ids = \get_post_meta( $pass_301, 'scope_term_ids', false );
		$stored_int      = \array_map( 'intval', $stored_term_ids );
		$this->assertContains( $this->term_html, $stored_int, 'term_html 應存在' );
		$this->assertNotContains( $this->term_php, $stored_int, 'term_php 應被移除' );
		$this->assertCount( 1, $stored_int, '應只剩一個分類 term' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功變更期限模式為固定期限 N 天
	 *
	 * Example: 將永久權限包改為固定期限 90 天
	 *   When 管理員更新 pass_300，limit_type → "fixed"，limit_value → 90，limit_unit → "day"
	 *   Then pass_300 的 limit_type=fixed，limit_value=90
	 */
	public function test_成功將永久改為固定期限90天(): void {
		Crud::update(
			$this->pass_300,
			[
				'limit_type'  => 'fixed',
				'limit_value' => 90,
				'limit_unit'  => 'day',
			]
		);

		$this->assertSame( 'fixed', \get_post_meta( $this->pass_300, 'limit_type', true ), 'limit_type 應改為 fixed' );
		$this->assertSame( '90', (string) \get_post_meta( $this->pass_300, 'limit_value', true ), 'limit_value 應為 90' );
		$this->assertSame( 'day', \get_post_meta( $this->pass_300, 'limit_unit', true ), 'limit_unit 應為 day' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功變更期限模式為指定日期到期（assigned）
	 *
	 * Example: 將永久權限包改為指定日期到期
	 *   When 管理員更新 pass_300，limit_type → "assigned"，limit_value → 絕對 timestamp，limit_unit → "timestamp"
	 *   Then pass_300 的 limit_type=assigned，limit_value=該 timestamp
	 */
	public function test_成功將永久改為指定日期到期(): void {
		$assigned_ts = \strtotime( '2030-12-31 23:59:59' );

		Crud::update(
			$this->pass_300,
			[
				'limit_type'  => 'assigned',
				'limit_value' => $assigned_ts,
				'limit_unit'  => 'timestamp',
			]
		);

		$this->assertSame( 'assigned', \get_post_meta( $this->pass_300, 'limit_type', true ), 'limit_type 應改為 assigned' );
		$this->assertSame( (string) $assigned_ts, (string) \get_post_meta( $this->pass_300, 'limit_value', true ), 'limit_value 應為指定 timestamp' );
		$this->assertSame( 'timestamp', \get_post_meta( $this->pass_300, 'limit_unit', true ), 'limit_unit 應為 timestamp' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 若提供則必須為四態之一
	 *
	 * Example: 更新時帶入非法 limit_type（含舊值 limited）操作失敗
	 */
	public function test_更新時limit_type非法時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::update(
			$this->pass_300,
			[ 'limit_type' => 'limited' ] // 舊值已不合法
		);
	}
}
