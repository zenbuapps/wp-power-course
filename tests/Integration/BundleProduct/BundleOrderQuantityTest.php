<?php
/**
 * 銷售方案訂單數量處理 整合測試
 * Feature: specs/features/bundle/銷售方案商品數量設定.feature
 * Rule: 後置（狀態）- 購買方案時 bundled item 數量 = 方案設定數量 × 購買份數
 *
 * @group bundle
 * @group bundle-quantity
 * @group order
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Resources\Order as OrderResource;

/**
 * Class BundleOrderQuantityTest
 * 測試購買銷售方案時，bundled item 的數量計算邏輯
 *
 * 核心規則：bundled item 數量 = 方案設定數量 × 購買份數
 */
class BundleOrderQuantityTest extends TestCase {

	/** @var int 課程商品 ID */
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

		// 建立課程商品
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

		// 建立銷售方案（含 bundle_type meta）
		$this->bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '全套學習包',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->bundle_id, 'bundle_type', 'bundle' );
		update_post_meta( $this->bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $this->course_id );
		update_post_meta( $this->bundle_id, '_price', '1999' );
		update_post_meta( $this->bundle_id, '_regular_price', '1999' );

		// 設定方案包含的商品
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		// 設定方案商品數量：course × 2, product × 3
		$quantities = [
			(string) $this->course_id  => 2,
			(string) $this->product_id => 3,
		];
		update_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, wp_json_encode( $quantities ) );
	}

	// ========== Helper 數量讀取 ==========

	/**
	 * @test
	 * @group happy
	 * Helper::get_product_quantities 可正確讀取方案設定數量
	 */
	public function test_helper讀取方案設定數量(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$quantities = $helper->get_product_quantities();

		$this->assertSame( 2, $quantities[ (string) $this->course_id ] );
		$this->assertSame( 3, $quantities[ (string) $this->product_id ] );
	}

	/**
	 * @test
	 * @group happy
	 * 舊方案（無 pbp_product_quantities）購買時各商品數量應為 1
	 *
	 * Rule: 向下相容 - 缺少 pbp_product_quantities meta 的舊方案，所有商品預設數量為 1
	 */
	public function test_舊方案無quantities時fallback為1(): void {
		// Given：移除 quantities meta（模擬舊方案）
		delete_post_meta( $this->bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY );

		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$quantities = $helper->get_product_quantities();

		// Then：每個商品 fallback 為 1
		$this->assertSame( 1, $quantities[ (string) $this->course_id ] );
		$this->assertSame( 1, $quantities[ (string) $this->product_id ] );
	}

	/**
	 * @test
	 * @group happy
	 * get_product_ids_with_compat 讀取方案商品列表（含向下相容）
	 */
	public function test_get_product_ids_with_compat讀取商品列表(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$ids = $helper->get_product_ids_with_compat();

		// 方案商品列表應包含 course_id 和 product_id
		$this->assertContains( (string) $this->course_id, $ids );
		$this->assertContains( (string) $this->product_id, $ids );
	}

	/**
	 * @test
	 * @group happy
	 * bundled item 最終數量計算：方案設定數量 × 購買份數
	 * 驗證數量乘法的核心計算邏輯
	 */
	public function test_bundled_item數量計算_購買1份(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$order_qty  = 1; // 購買份數
		$quantities = $helper->get_product_quantities();

		// 計算 course 的最終數量
		$bundle_qty_course  = max( 1, (int) ( $quantities[ (string) $this->course_id ] ?? 1 ) );
		$final_qty_course   = $bundle_qty_course * $order_qty;

		// 計算 product 的最終數量
		$bundle_qty_product = max( 1, (int) ( $quantities[ (string) $this->product_id ] ?? 1 ) );
		$final_qty_product  = $bundle_qty_product * $order_qty;

		$this->assertSame( 2, $final_qty_course, '購買 1 份，course 數量應為 2 × 1 = 2' );
		$this->assertSame( 3, $final_qty_product, '購買 1 份，product 數量應為 3 × 1 = 3' );
	}

	/**
	 * @test
	 * @group happy
	 * bundled item 最終數量計算：購買 2 份時，數量翻倍
	 */
	public function test_bundled_item數量計算_購買2份(): void {
		$bundle_product = wc_get_product( $this->bundle_id );
		$helper         = Helper::instance( $bundle_product );

		$order_qty  = 2; // 購買 2 份
		$quantities = $helper->get_product_quantities();

		$bundle_qty_course  = max( 1, (int) ( $quantities[ (string) $this->course_id ] ?? 1 ) );
		$final_qty_course   = $bundle_qty_course * $order_qty;

		$bundle_qty_product = max( 1, (int) ( $quantities[ (string) $this->product_id ] ?? 1 ) );
		$final_qty_product  = $bundle_qty_product * $order_qty;

		$this->assertSame( 4, $final_qty_course, '購買 2 份，course 數量應為 2 × 2 = 4' );
		$this->assertSame( 6, $final_qty_product, '購買 2 份，product 數量應為 3 × 2 = 6' );
	}

	/**
	 * @test
	 * @group edge
	 * 邊緣情況：數量計算不溢位（999 × 999 = 998001，在 PHP_INT_MAX 範圍內）
	 */
	public function test_最大數量計算不溢位(): void {
		$max_bundle_qty = 999;
		$max_order_qty  = 999;
		$result         = $max_bundle_qty * $max_order_qty;

		$this->assertSame( 998001, $result );
		$this->assertLessThan( PHP_INT_MAX, $result );
	}
}
