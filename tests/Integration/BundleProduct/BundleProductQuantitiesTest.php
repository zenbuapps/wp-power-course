<?php
/**
 * 銷售方案商品數量 整合測試
 * Feature: specs/features/bundle/管理銷售方案.feature（pbp_product_quantities 相關規則）
 * Feature: specs/features/bundle/銷售方案數量顯示.feature（API 回傳格式）
 *
 * @group bundle
 * @group quantities
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class BundleProductQuantitiesTest
 * 測試銷售方案商品數量功能
 *
 * 聚焦「業務核心邏輯」：
 * - Helper::get_product_quantities() / set_product_quantities() / get_quantity_for_product()
 * - 庫存扣減乘積計算
 * - 向後相容（舊資料預設 1）
 */
class BundleProductQuantitiesTest extends TestCase {

	/** @var int 課程商品 ID */
	private int $course_id;

	/** @var int T-shirt 商品 ID */
	private int $tshirt_id;

	/** @var int 銷售方案 ID */
	private int $bundle_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// Helper 為靜態方法，不需額外 repository/service
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立課程商品
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
				'price'      => '999',
			]
		);

		// 建立 T-shirt 簡單商品
		$this->tshirt_id = $this->factory()->post->create(
			[
				'post_title'  => 'T-shirt',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $this->tshirt_id, '_price', '500' );
		\update_post_meta( $this->tshirt_id, '_regular_price', '500' );
		\update_post_meta( $this->tshirt_id, '_stock_status', 'instock' );
		\update_post_meta( $this->tshirt_id, '_manage_stock', 'yes' );
		\update_post_meta( $this->tshirt_id, '_stock', '100' );

		// 建立銷售方案商品
		$this->bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '合購方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $this->bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $this->bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $this->course_id );
	}

	// ========== Smoke Tests：常數與方法存在性 ==========

	/**
	 * @test
	 * @group smoke
	 *
	 * 確認 Helper 類別有 PRODUCT_QUANTITIES_META_KEY 常數
	 */
	public function test_Helper_有PRODUCT_QUANTITIES_META_KEY常數(): void {
		$this->assertTrue(
			defined( 'J7\PowerCourse\BundleProduct\Helper::PRODUCT_QUANTITIES_META_KEY' ),
			'Helper 類別應定義 PRODUCT_QUANTITIES_META_KEY 常數'
		);
		$this->assertSame(
			'pbp_product_quantities',
			Helper::PRODUCT_QUANTITIES_META_KEY,
			'PRODUCT_QUANTITIES_META_KEY 應為 pbp_product_quantities'
		);
	}

	/**
	 * @test
	 * @group smoke
	 *
	 * 確認 Helper 類別有 get_product_quantities 方法
	 */
	public function test_Helper_有get_product_quantities方法(): void {
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );
		$this->assertTrue(
			method_exists( $helper, 'get_product_quantities' ),
			'Helper 應有 get_product_quantities() 方法'
		);
	}

	/**
	 * @test
	 * @group smoke
	 *
	 * 確認 Helper 類別有 set_product_quantities 方法
	 */
	public function test_Helper_有set_product_quantities方法(): void {
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );
		$this->assertTrue(
			method_exists( $helper, 'set_product_quantities' ),
			'Helper 應有 set_product_quantities() 方法'
		);
	}

	/**
	 * @test
	 * @group smoke
	 *
	 * 確認 Helper 類別有 get_quantity_for_product 方法
	 */
	public function test_Helper_有get_quantity_for_product方法(): void {
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );
		$this->assertTrue(
			method_exists( $helper, 'get_quantity_for_product' ),
			'Helper 應有 get_quantity_for_product() 方法'
		);
	}

	// ========== Helper 方法測試 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 銷售方案包含商品時，需儲存每個商品的數量（pbp_product_quantities）
	 * Example: 建立含商品數量的銷售方案
	 */
	public function test_set_product_quantities_儲存數量到meta(): void {
		// Given 有一個銷售方案
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper, '應能建立 Helper 實例' );

		// When 設定商品數量
		$helper->set_product_quantities(
			[
				$this->course_id => 2,
				$this->tshirt_id => 3,
			]
		);

		// Then pbp_product_quantities meta 應儲存正確資料
		$stored_json = \get_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, true );
		$this->assertNotEmpty( $stored_json, 'pbp_product_quantities meta 應有資料' );

		$decoded = \json_decode( (string) $stored_json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 2, (int) $decoded[ (string) $this->course_id ] );
		$this->assertSame( 3, (int) $decoded[ (string) $this->tshirt_id ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 銷售方案包含商品時，需儲存每個商品的數量（pbp_product_quantities）
	 * Example: 未指定數量時預設為 1
	 */
	public function test_get_quantity_for_product_無資料時回傳預設1(): void {
		// Given 有一個銷售方案（無 pbp_product_quantities meta）
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper, '應能建立 Helper 實例' );

		// When 查詢某商品的數量（無資料）
		$qty = $helper->get_quantity_for_product( $this->tshirt_id );

		// Then 回傳 1（預設值）
		$this->assertSame( 1, $qty, '無資料時數量應預設為 1' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 銷售方案包含商品時，需儲存每個商品的數量（pbp_product_quantities）
	 * Example: 建立含商品數量的銷售方案（get_product_quantities）
	 */
	public function test_get_product_quantities_回傳已儲存的數量(): void {
		// Given 銷售方案已設定 pbp_product_quantities
		\update_post_meta(
			$this->bundle_id,
			Helper::PRODUCT_QUANTITIES_META_KEY,
			\wp_json_encode(
				[
					(string) $this->course_id => 2,
					(string) $this->tshirt_id => 3,
				]
			)
		);
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 查詢所有商品數量
		$quantities = $helper->get_product_quantities();

		// Then 回傳 [course_id => 2, tshirt_id => 3]
		$this->assertIsArray( $quantities );
		$this->assertSame( 2, $quantities[ (string) $this->course_id ] );
		$this->assertSame( 3, $quantities[ (string) $this->tshirt_id ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 銷售方案數量顯示
	 * Rule: API（回傳）- 舊資料沒有數量時回傳空物件
	 * Example: 舊資料沒有數量時回傳空物件
	 */
	public function test_get_product_quantities_舊資料無數量回傳空陣列(): void {
		// Given 舊格式銷售方案（無 pbp_product_quantities meta）
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 查詢所有商品數量
		$quantities = $helper->get_product_quantities();

		// Then 回傳空陣列 []
		$this->assertIsArray( $quantities );
		$this->assertEmpty( $quantities, '舊資料無數量時應回傳空陣列' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 更新銷售方案時可修改商品數量
	 * Example: 更新銷售方案的商品數量
	 */
	public function test_set_product_quantities_更新已存在的數量(): void {
		// Given 銷售方案已有數量 {course_id: 1, tshirt_id: 1}
		\update_post_meta(
			$this->bundle_id,
			Helper::PRODUCT_QUANTITIES_META_KEY,
			\wp_json_encode(
				[
					(string) $this->course_id => 1,
					(string) $this->tshirt_id => 1,
				]
			)
		);

		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 更新數量為 {course_id: 2, tshirt_id: 3}
		$helper->set_product_quantities(
			[
				$this->course_id => 2,
				$this->tshirt_id => 3,
			]
		);

		// Then pbp_product_quantities 應為 {"course_id": 2, "tshirt_id": 3}
		$updated = $helper->get_product_quantities();
		$this->assertSame( 2, $updated[ (string) $this->course_id ] );
		$this->assertSame( 3, $updated[ (string) $this->tshirt_id ] );
	}

	// ========== 庫存扣減計算測試 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 結帳時每個商品的庫存扣減 = 商品數量 × 銷售方案購買數量
	 * Example: 購買 1 份銷售方案，庫存按商品數量扣減
	 *
	 * 注意：此測試驗證 Helper::get_quantity_for_product() 計算邏輯
	 * 完整 WooCommerce 訂單流程（庫存實際扣減）由 E2E 測試覆蓋
	 */
	public function test_訂單庫存扣減_購買1份按商品數量扣(): void {
		// Given 銷售方案包含商品，數量為 {course_id: 2, tshirt_id: 3}
		\update_post_meta(
			$this->bundle_id,
			Helper::PRODUCT_QUANTITIES_META_KEY,
			\wp_json_encode(
				[
					(string) $this->course_id => 2,
					(string) $this->tshirt_id => 3,
				]
			)
		);
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 購買 1 份銷售方案（bundle_purchase_qty = 1）
		$bundle_purchase_qty = 1;

		// Then 課程的有效扣減數量 = per_product_qty × bundle_purchase_qty
		$course_qty_per_product = $helper->get_quantity_for_product( $this->course_id );
		$tshirt_qty_per_product = $helper->get_quantity_for_product( $this->tshirt_id );
		$expected_course_qty    = $course_qty_per_product * $bundle_purchase_qty;
		$expected_tshirt_qty    = $tshirt_qty_per_product * $bundle_purchase_qty;

		$this->assertSame( 2, $expected_course_qty, '課程庫存扣減應為 2（qty=2 × purchase=1）' );
		$this->assertSame( 3, $expected_tshirt_qty, 'T-shirt 庫存扣減應為 3（qty=3 × purchase=1）' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: 管理銷售方案
	 * Rule: 後置（狀態）- 結帳時每個商品的庫存扣減 = 商品數量 × 銷售方案購買數量
	 * Example: 購買 2 份銷售方案，庫存按乘積扣減
	 */
	public function test_訂單庫存扣減_購買2份按乘積扣(): void {
		// Given 銷售方案包含商品，數量為 {course_id: 2, tshirt_id: 3}
		\update_post_meta(
			$this->bundle_id,
			Helper::PRODUCT_QUANTITIES_META_KEY,
			\wp_json_encode(
				[
					(string) $this->course_id => 2,
					(string) $this->tshirt_id => 3,
				]
			)
		);
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 購買 2 份銷售方案（bundle_purchase_qty = 2）
		$bundle_purchase_qty = 2;

		// Then 課程的有效扣減數量 = per_product_qty × bundle_purchase_qty
		$course_qty_per_product = $helper->get_quantity_for_product( $this->course_id );
		$tshirt_qty_per_product = $helper->get_quantity_for_product( $this->tshirt_id );
		$expected_course_qty    = $course_qty_per_product * $bundle_purchase_qty;
		$expected_tshirt_qty    = $tshirt_qty_per_product * $bundle_purchase_qty;

		$this->assertSame( 4, $expected_course_qty, '課程庫存扣減應為 4（qty=2 × purchase=2）' );
		$this->assertSame( 6, $expected_tshirt_qty, 'T-shirt 庫存扣減應為 6（qty=3 × purchase=2）' );
	}

	// ========== Edge Cases ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Feature: 管理銷售方案
	 * 向後相容：不在 quantities 中的商品，get_quantity_for_product 應回傳 1
	 */
	public function test_不在quantities中的商品回傳預設1(): void {
		// Given 銷售方案只設定了 course_id 的數量，未設定 tshirt_id
		\update_post_meta(
			$this->bundle_id,
			Helper::PRODUCT_QUANTITIES_META_KEY,
			\wp_json_encode(
				[
					(string) $this->course_id => 2,
				]
			)
		);
		$helper = Helper::instance( $this->bundle_id );
		$this->assertNotNull( $helper );

		// When 查詢 tshirt_id 的數量（未設定）
		$qty = $helper->get_quantity_for_product( $this->tshirt_id );

		// Then 回傳預設值 1
		$this->assertSame( 1, $qty, '未設定數量的商品應回傳預設值 1（向後相容）' );
	}
}
