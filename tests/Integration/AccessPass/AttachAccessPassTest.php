<?php
/**
 * 掛載權限包到商品 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/掛載權限包到商品.feature
 * Issue #252：課程通行證（Access Pass）CRUD API
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Crud 類別尚未實作
 *   - Crud::attach_to_product 方法不存在
 *
 * 架構說明：
 *   1 商品掛 1 包（1:1）：商品端用 product meta "access_pass_id" 記錄掛載關係。
 *   「逐課綁定 bind_courses_data」與「權限包 access_pass_id」兩者並存、效果並集（不互斥）。
 *
 * @group access-pass
 * @group attach
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class AttachAccessPassTest
 * 驗證課程權限包掛載到商品的業務邏輯（對映 掛載權限包到商品.feature 的所有 Rule/Example）
 */
class AttachAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 課程 ID（100 HTML 入門課）*/
	private int $course_100;

	/** @var int 一次性商品 ID（500 全站通行證 simple）*/
	private int $product_500;

	/** @var int 訂閱商品 ID（510 月費暢看 subscription）*/
	private int $product_510;

	/** @var int active 全站永久權限包（300）*/
	private int $pass_300;

	/** @var int active follow_subscription 權限包（301）*/
	private int $pass_301;

	/** @var int disabled 已停用權限包（302）*/
	private int $pass_302;

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

		// Background：建立一次性商品（simple）
		$this->product_500 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '全站通行證',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_500, '_price', '1999' );
		\update_post_meta( $this->product_500, '_regular_price', '1999' );
		\update_post_meta( $this->product_500, '_product_type', 'simple' ); // 僅用於 meta 識別，WC type 透過 term 管理
		$this->ids['product_500'] = $this->product_500;

		// Background：建立訂閱商品（subscription）
		$this->product_510 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '月費暢看',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_510, '_price', '299' );
		\update_post_meta( $this->product_510, '_product_type', 'subscription' );
		$this->ids['product_510'] = $this->product_510;

		// Background：建立 active 全站永久權限包（pass_300）
		$this->pass_300 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '全站課程權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_300, 'scope_type', 'all' );
		\update_post_meta( $this->pass_300, 'limit_mode', 'permanent' );
		\update_post_meta( $this->pass_300, 'access_pass_status', 'active' );
		$this->ids['pass_300'] = $this->pass_300;

		// Background：建立 active follow_subscription 權限包（pass_301）
		$this->pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '訂閱全站權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_301, 'scope_type', 'all' );
		\update_post_meta( $this->pass_301, 'limit_mode', 'follow_subscription' );
		\update_post_meta( $this->pass_301, 'access_pass_status', 'active' );
		$this->ids['pass_301'] = $this->pass_301;

		// Background：建立 disabled 舊版權限包（pass_302）
		$this->pass_302 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '舊版權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_302, 'scope_type', 'all' );
		\update_post_meta( $this->pass_302, 'limit_mode', 'permanent' );
		\update_post_meta( $this->pass_302, 'access_pass_status', 'disabled' );
		$this->ids['pass_302'] = $this->pass_302;
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT 與 product meta
	 */
	public function tear_down(): void {
		// 清除商品 access_pass_id meta
		\delete_post_meta( $this->product_500, 'access_pass_id' );
		\delete_post_meta( $this->product_510, 'access_pass_id' );

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
	 * 確認 Crud::attach_to_product 方法存在（Red：預期失敗）
	 */
	public function test_crud_attach_to_product_方法存在(): void {
		$this->assertTrue(
			\method_exists( Crud::class, 'attach_to_product' ),
			'Crud::attach_to_product 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被掛載的商品必須存在
	 *
	 * Example: 掛載到不存在的商品時操作失敗（product_id=9999）
	 */
	public function test_掛載到不存在的商品時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::attach_to_product( $this->pass_300, 9999 );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被掛載的權限包必須存在
	 *
	 * Example: 掛載不存在的權限包時操作失敗（pass_id=9999）
	 */
	public function test_掛載不存在的權限包時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::attach_to_product( 9999, $this->product_500 );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被掛載的權限包必須為啟用中（active）
	 *
	 * Example: 掛載已停用的權限包時操作失敗（pass_302 status=disabled）
	 */
	public function test_掛載disabled權限包時失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::attach_to_product( $this->pass_302, $this->product_500 );
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功將權限包掛載到一次性商品，寫入 access_pass_id meta
	 *
	 * Example: 成功掛載權限包到一次性商品（pass_300 → product_500）
	 */
	public function test_成功掛載到一次性商品(): void {
		Crud::attach_to_product( $this->pass_300, $this->product_500 );

		$stored = (int) \get_post_meta( $this->product_500, 'access_pass_id', true );
		$this->assertSame( $this->pass_300, $stored, '商品 500 的 access_pass_id meta 應為 pass_300' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功將跟隨訂閱權限包掛載到訂閱商品
	 *
	 * Example: 成功掛載跟隨訂閱權限包到訂閱商品（pass_301 → product_510）
	 */
	public function test_成功掛載follow_subscription權限包到訂閱商品(): void {
		Crud::attach_to_product( $this->pass_301, $this->product_510 );

		$stored = (int) \get_post_meta( $this->product_510, 'access_pass_id', true );
		$this->assertSame( $this->pass_301, $stored, '商品 510 的 access_pass_id meta 應為 pass_301' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 1 商品只掛 1 包，重新掛載會覆蓋既有掛載
	 *
	 * Example: 重新掛載權限包覆蓋舊的掛載
	 *   Given 商品 500 的 access_pass_id meta 為 pass_301
	 *   When 管理員掛載 pass_300 → product_500
	 *   Then 商品 500 的 access_pass_id meta 應為 pass_300（覆蓋 pass_301）
	 */
	public function test_重新掛載覆蓋舊掛載(): void {
		// Given：先掛 pass_301
		\update_post_meta( $this->product_500, 'access_pass_id', $this->pass_301 );

		// When：改掛 pass_300
		Crud::attach_to_product( $this->pass_300, $this->product_500 );

		// Then：應覆蓋為 pass_300
		$stored = (int) \get_post_meta( $this->product_500, 'access_pass_id', true );
		$this->assertSame( $this->pass_300, $stored, '應覆蓋為 pass_300' );
	}

	// ========== 邊緣案例（Edge Tests）==========

	/**
	 * @test
	 * @group edge
	 * Rule: 後置（狀態）- 權限包與逐課綁定並存，兩者皆保留
	 *
	 * Example: 商品同時設定逐課綁定與權限包
	 *   Given 商品 500 已設定 bind_courses_data 綁定課程 100
	 *   When 管理員掛載 pass_300 → product_500
	 *   Then access_pass_id = pass_300，bind_courses_data 仍含課程 100（兩者並存）
	 */
	public function test_權限包與bind_courses_data並存(): void {
		// Given：先設定 bind_courses_data（模擬逐課綁定）
		$bind_data = \wp_json_encode( [ $this->course_100 => [ 'expire_mode' => 'unlimited' ] ] );
		\update_post_meta( $this->product_500, 'bind_courses_data', $bind_data );

		// When：掛載權限包
		Crud::attach_to_product( $this->pass_300, $this->product_500 );

		// Then：access_pass_id 應正確
		$stored_pass = (int) \get_post_meta( $this->product_500, 'access_pass_id', true );
		$this->assertSame( $this->pass_300, $stored_pass, 'access_pass_id 應為 pass_300' );

		// Then：bind_courses_data 應仍存在（不被覆蓋）
		$stored_bind = \get_post_meta( $this->product_500, 'bind_courses_data', true );
		$this->assertNotEmpty( $stored_bind, 'bind_courses_data 應仍存在，不應被覆蓋' );
		$this->assertStringContainsString( (string) $this->course_100, $stored_bind, 'bind_courses_data 仍應含課程 100' );
	}
}
