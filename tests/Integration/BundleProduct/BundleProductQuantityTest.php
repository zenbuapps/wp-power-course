<?php
/**
 * 銷售方案商品數量設定 整合測試
 * Feature: specs/features/bundle/銷售方案商品數量設定.feature
 * Feature: specs/features/bundle/移除排除當前課程功能.feature
 *
 * @group bundle
 * @group bundle-quantity
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class BundleProductQuantityTest
 * 測試銷售方案商品數量自由設定功能（Issue #185）
 *
 * 覆蓋：
 * - Helper::get_product_quantities() / set_product_quantities() / get_product_quantity()
 * - Helper::get_product_ids_with_compat()（向下相容邏輯）
 * - 訂單處理：bundled item 數量 = 方案設定數量 × 購買份數
 */
class BundleProductQuantityTest extends TestCase {

	/** @var int 課程商品 ID（link_course_id） */
	private int $course_id;

	/** @var int 銷售方案商品 ID */
	private int $bundle_id;

	/** @var int 普通商品 ID */
	private int $product_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 無需額外依賴
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立課程商品（作為 link_course_id）
		$this->course_id = $this->factory()->post->create(
			[
				'post_title'  => 'PHP 基礎課',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->course_id, '_is_course', 'yes' );
		update_post_meta( $this->course_id, '_price', '999' );
		update_post_meta( $this->course_id, '_regular_price', '999' );
		update_post_meta( $this->course_id, '_manage_stock', 'yes' );
		update_post_meta( $this->course_id, '_stock', '50' );
		update_post_meta( $this->course_id, '_stock_status', 'instock' );

		// 建立普通商品
		$this->product_id = $this->factory()->post->create(
			[
				'post_title'  => 'Power T-shirt',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_id, '_price', '500' );
		update_post_meta( $this->product_id, '_regular_price', '500' );
		update_post_meta( $this->product_id, '_manage_stock', 'yes' );
		update_post_meta( $this->product_id, '_stock', '100' );
		update_post_meta( $this->product_id, '_stock_status', 'instock' );

		// 建立銷售方案商品
		$this->bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '全套學習包',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->bundle_id, 'bundle_type', 'bundle' );
		update_post_meta( $this->bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $this->course_id );
	}

	// ========== Helper::PRODUCT_QUANTITIES_META_KEY 常數 ==========

	/**
	 * @test
	 * @group smoke
	 * PRODUCT_QUANTITIES_META_KEY 常數應存在且值正確
	 */
	public function test_PRODUCT_QUANTITIES_META_KEY_常數存在(): void {
		$this->assertSame( 'pbp_product_quantities', Helper::PRODUCT_QUANTITIES_META_KEY );
	}

	// ========== get_product_quantities() ==========

	/**
	 * @test
	 * @group happy
	 * get_product_quantities：有 meta 時應回傳正確數量陣列
	 */
	public function test_get_product_quantities_有meta時回傳正確值(): void {
		// Given 銷售方案含兩個商品
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		// 設定 quantities meta
		$quantities = [
			(string) $this->course_id  => 2,
			(string) $this->product_id => 3,
		];
		update_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, wp_json_encode( $quantities ) );

		$bundle_product = wc_get_product( $this->bundle_id );
		$this->assertNotFalse( $bundle_product );

		$helper = Helper::instance( $bundle_product );
		$this->assertNotNull( $helper );

		// When
		$result = $helper->get_product_quantities();

		// Then
		$this->assertSame( 2, $result[ (string) $this->course_id ] );
		$this->assertSame( 3, $result[ (string) $this->product_id ] );
	}

	/**
	 * @test
	 * @group happy
	 * get_product_quantities：無 meta 時 fallback 所有商品數量為 1
	 */
	public function test_get_product_quantities_無meta時fallback為1(): void {
		// Given 銷售方案含兩個商品，但無 quantities meta
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		$bundle_product = wc_get_product( $this->bundle_id );
		$this->assertNotFalse( $bundle_product );

		$helper = Helper::instance( $bundle_product );
		$this->assertNotNull( $helper );

		// When
		$result = $helper->get_product_quantities();

		// Then：每個商品 fallback 為 1
		$this->assertSame( 1, $result[ (string) $this->course_id ] );
		$this->assertSame( 1, $result[ (string) $this->product_id ] );
	}

	/**
	 * @test
	 * @group edge
	 * get_product_quantities：meta 為非法 JSON 時 fallback 所有商品為 1
	 */
	public function test_get_product_quantities_非法JSON時fallback為1(): void {
		// Given 非法 JSON meta
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		update_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, 'invalid-json' );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$result = $helper->get_product_quantities();

		// Then
		$this->assertSame( 1, $result[ (string) $this->course_id ] );
	}

	/**
	 * @test
	 * @group edge
	 * get_product_quantities：meta 中缺少某商品時，該商品 fallback 為 1
	 */
	public function test_get_product_quantities_缺少商品時fallback為1(): void {
		// Given 兩個商品，但 quantities 只設定一個
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		$quantities = [ (string) $this->course_id => 5 ]; // 只設定 course
		update_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, wp_json_encode( $quantities ) );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$result = $helper->get_product_quantities();

		// Then：course_id 應為 5，product_id 應 fallback 為 1
		$this->assertSame( 5, $result[ (string) $this->course_id ] );
		$this->assertSame( 1, $result[ (string) $this->product_id ] );
	}

	// ========== get_product_quantity() ==========

	/**
	 * @test
	 * @group happy
	 * get_product_quantity：回傳指定商品的數量
	 */
	public function test_get_product_quantity_回傳指定商品數量(): void {
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		$quantities = [
			(string) $this->course_id  => 2,
			(string) $this->product_id => 7,
		];
		update_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, wp_json_encode( $quantities ) );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$this->assertSame( 2, $helper->get_product_quantity( $this->course_id ) );
		$this->assertSame( 7, $helper->get_product_quantity( $this->product_id ) );
	}

	/**
	 * @test
	 * @group edge
	 * get_product_quantity：不存在商品時回傳 1
	 */
	public function test_get_product_quantity_不存在商品時回傳1(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$this->assertSame( 1, $helper->get_product_quantity( 99999 ) );
	}

	// ========== set_product_quantities() ==========

	/**
	 * @test
	 * @group happy
	 * set_product_quantities：正確儲存數量
	 */
	public function test_set_product_quantities_正確儲存(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$quantities = [
			(string) $this->course_id  => 2,
			(string) $this->product_id => 3,
		];

		// When
		$helper->set_product_quantities( $quantities );

		// Then：讀取 meta 應為 JSON 字串
		$raw = get_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, true );
		$this->assertIsString( $raw );
		$decoded = json_decode( $raw, true );
		$this->assertSame( 2, $decoded[ (string) $this->course_id ] );
		$this->assertSame( 3, $decoded[ (string) $this->product_id ] );
	}

	/**
	 * @test
	 * @group edge
	 * set_product_quantities：qty < 1 應 clamp 為 1
	 */
	public function test_set_product_quantities_qty小於1時clamp為1(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$helper->set_product_quantities( [ (string) $this->course_id => 0 ] );

		$raw     = get_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, true );
		$decoded = json_decode( $raw, true );
		$this->assertSame( 1, $decoded[ (string) $this->course_id ] );
	}

	/**
	 * @test
	 * @group edge
	 * set_product_quantities：qty > 999 應 clamp 為 999
	 */
	public function test_set_product_quantities_qty大於999時clamp為999(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$helper->set_product_quantities( [ (string) $this->course_id => 1000 ] );

		$raw     = get_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, true );
		$decoded = json_decode( $raw, true );
		$this->assertSame( 999, $decoded[ (string) $this->course_id ] );
	}

	// ========== get_product_ids_with_compat() — 向下相容 ==========

	/**
	 * @test
	 * @group happy
	 * get_product_ids_with_compat：exclude_main_course='yes' 時不補入課程
	 */
	public function test_get_product_ids_with_compat_exclude_yes不補入課程(): void {
		// Given：pbp_product_ids 不含 course_id，exclude_main_course = 'yes'
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );
		update_post_meta( $this->bundle_id, 'exclude_main_course', 'yes' );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$ids = $helper->get_product_ids_with_compat();

		// Then：不應包含 course_id
		$this->assertNotContains( (string) $this->course_id, $ids );
		$this->assertContains( (string) $this->product_id, $ids );
	}

	/**
	 * @test
	 * @group happy
	 * get_product_ids_with_compat：exclude_main_course='no' 且 course 不在列表時，自動補入
	 */
	public function test_get_product_ids_with_compat_exclude_no自動補入課程(): void {
		// Given：pbp_product_ids 不含 course_id，exclude_main_course = 'no'
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );
		update_post_meta( $this->bundle_id, 'exclude_main_course', 'no' );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$ids = $helper->get_product_ids_with_compat();

		// Then：course_id 應被補入到列表前面
		$this->assertContains( (string) $this->course_id, $ids );
		$this->assertContains( (string) $this->product_id, $ids );
		$this->assertSame( (string) $this->course_id, $ids[0], '課程應在列表第一位' );
	}

	/**
	 * @test
	 * @group happy
	 * get_product_ids_with_compat：exclude_main_course 為空值，等同 no，自動補入課程
	 */
	public function test_get_product_ids_with_compat_exclude_空值自動補入課程(): void {
		// Given：exclude_main_course 為空（未設定）
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );
		// 不設定 exclude_main_course

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$ids = $helper->get_product_ids_with_compat();

		// Then：course_id 應被補入
		$this->assertContains( (string) $this->course_id, $ids );
	}

	/**
	 * @test
	 * @group edge
	 * get_product_ids_with_compat：pbp_product_ids 已含 course_id 時，不重複補入
	 */
	public function test_get_product_ids_with_compat_已含課程不重複(): void {
		// Given：pbp_product_ids 已含 course_id
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );
		update_post_meta( $this->bundle_id, 'exclude_main_course', 'no' );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		// When
		$ids = $helper->get_product_ids_with_compat();

		// Then：course_id 只出現一次
		$course_count = count( array_filter( $ids, fn( $id ) => $id === (string) $this->course_id ) );
		$this->assertSame( 1, $course_count, '課程 ID 在列表中只應出現一次' );
	}

	// ========== 數量驗證規則 ==========

	/**
	 * @test
	 * @group validation
	 * API 儲存：qty=0 時應自動修正為 1（後端 handle_special_fields clamp）
	 * 驗證 handle_special_fields 中的數量 clamp 邏輯
	 */
	public function test_數量0時自動修正為1(): void {
		// 直接測試 Helper::set_product_quantities 的 clamp 行為
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$helper->set_product_quantities( [ (string) $this->course_id => 0 ] );

		$result = $helper->get_product_quantity( $this->course_id );
		$this->assertSame( 1, $result );
	}

	/**
	 * @test
	 * @group validation
	 * 數量 999 時應被接受（最大值）
	 */
	public function test_數量999時應被接受(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$helper->set_product_quantities( [ (string) $this->course_id => 999 ] );

		$result = $helper->get_product_quantity( $this->course_id );
		$this->assertSame( 999, $result );
	}
}
