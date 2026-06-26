<?php
/**
 * AccessPass CPT 結構測試
 * 驗證 pc_access_pass Custom Post Type 的註冊與設定
 *
 * Feature: specs/entity/erm.dbml （TABLE: access_passes，post_type="pc_access_pass"）
 *
 * @group access-pass
 * @group cpt
 * @group structure
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class CPTStructureTest
 * 驗證課程權限包 CPT（pc_access_pass）的正確註冊與功能設定
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Core\CPT 類別不存在
 *   - pc_access_pass CPT 尚未在 WordPress 中註冊
 */
class CPTStructureTest extends \Tests\Integration\TestCase {

	/**
	 * 初始化依賴（CPT 結構測試不需要額外依賴）
	 */
	protected function configure_dependencies(): void {
		// CPT 應在 WordPress init hook 時已由 Loader 自動註冊
		// 若 production code 尚未實作，CPT::POST_TYPE 常數不存在時下方測試會明確失敗
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_access_pass CPT 應已在 WordPress 中註冊
	 *
	 * 對照 AnnouncementCPTStructureTest::test_pc_announcement_CPT已註冊
	 */
	public function test_pc_access_pass_CPT已註冊(): void {
		$this->assertTrue(
			post_type_exists( CPT::POST_TYPE ),
			'pc_access_pass CPT 應已在 WordPress 中註冊，CPT::POST_TYPE = ' . ( defined( CPT::class . '::POST_TYPE' ) ? CPT::POST_TYPE : '（常數不存在）' )
		);
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * 測試：pc_access_pass CPT 應為非公開（後台管理用，前台不公開）
	 *
	 * 依 erm.dbml Note：post_type = "pc_access_pass"
	 * 站長透過後台 React SPA 管理，不需前台頁面
	 */
	public function test_pc_access_pass_public_為false(): void {
		$obj = get_post_type_object( CPT::POST_TYPE );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertFalse(
			$obj->public,
			'pc_access_pass CPT 應為非公開（public=false）——後台管理型 CPT 不需前台頁面'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_access_pass CPT show_in_rest 應為 true
	 *
	 * 前端 React SPA 透過 REST API 操作 access pass，
	 * show_in_rest=true 是 REST API 支援的前提條件
	 */
	public function test_pc_access_pass_show_in_rest_為true(): void {
		$obj = get_post_type_object( CPT::POST_TYPE );
		$this->assertNotNull( $obj, 'CPT 物件不應為 null' );
		$this->assertTrue(
			$obj->show_in_rest,
			'pc_access_pass CPT show_in_rest 應為 true，以支援 REST API 操作'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：pc_access_pass CPT 應支援 title 與 custom-fields
	 *
	 * - title：存放 access pass 名稱（例：全站課程權限、HTML 入門課程權限）
	 * - custom-fields：存放 scope_type / limit_type / limit_value / limit_unit / access_pass_status 等 postmeta
	 */
	public function test_pc_access_pass_supports必要功能(): void {
		$this->assertTrue(
			post_type_supports( CPT::POST_TYPE, 'title' ),
			'pc_access_pass 應支援 title（權限包名稱）'
		);
		$this->assertTrue(
			post_type_supports( CPT::POST_TYPE, 'custom-fields' ),
			'pc_access_pass 應支援 custom-fields（scope_type / limit_type 等 postmeta）'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：CPT::POST_TYPE 常數應為 'pc_access_pass'
	 *
	 * 確認常數定義正確，避免命名拼字錯誤造成 CPT 未正確識別
	 */
	public function test_POST_TYPE常數值正確(): void {
		$this->assertSame(
			'pc_access_pass',
			CPT::POST_TYPE,
			"CPT::POST_TYPE 應為 'pc_access_pass'"
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：可建立 pc_access_pass post 並設定 scope_type meta
	 *
	 * 驗證 CPT 允許新增 post 並儲存 postmeta（最基本的 CRUD 能力確認）
	 */
	public function test_可建立access_pass並設定scope_type(): void {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '全站課程通行證',
				'post_status' => 'publish',
			]
		);

		$this->assertGreaterThan( 0, $pass_id, 'post ID 應大於 0' );
		update_post_meta( $pass_id, 'scope_type', 'all' );

		$this->assertSame(
			'all',
			get_post_meta( $pass_id, 'scope_type', true ),
			'scope_type meta 應正確儲存'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：可設定 limit_type meta
	 *
	 * 依 erm.dbml access_pass_limit_type：unlimited | fixed | assigned | follow_subscription
	 */
	public function test_可設定limit_type_meta(): void {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '限時課程通行證',
				'post_status' => 'publish',
			]
		);

		update_post_meta( $pass_id, 'limit_type', 'fixed' );
		update_post_meta( $pass_id, 'limit_value', 30 );
		update_post_meta( $pass_id, 'limit_unit', 'day' );

		$this->assertSame( 'fixed', get_post_meta( $pass_id, 'limit_type', true ), 'limit_type 應為 fixed' );
		$this->assertSame( '30', get_post_meta( $pass_id, 'limit_value', true ), 'limit_value 應為 30' );
		$this->assertSame( 'day', get_post_meta( $pass_id, 'limit_unit', true ), 'limit_unit 應為 day' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 測試：停用狀態的 access pass（access_pass_status=disabled）應可儲存
	 *
	 * 依 erm.dbml：停用：不可再掛載到新商品，但已購用戶（pc_user_access_pass）權限保留
	 * 停用以 meta 為準（非 wp_posts.post_status），post 本身仍 publish
	 */
	public function test_停用狀態的access_pass可設定meta(): void {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '已停用通行證',
				'post_status' => 'publish',
			]
		);

		update_post_meta( $pass_id, 'access_pass_status', 'disabled' );
		$this->assertSame(
			'disabled',
			get_post_meta( $pass_id, 'access_pass_status', true ),
			'access_pass_status=disabled 應可正確儲存'
		);
		// post_status 仍為 publish（停用以 meta 為準）
		$this->assertSame( 'publish', get_post_status( $pass_id ), '停用通行證的 post_status 仍應為 publish' );
	}
}
