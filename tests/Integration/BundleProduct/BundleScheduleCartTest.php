<?php
/**
 * 銷售方案自動下線 — 購物車失效與訂單不受影響 整合測試（Issue #247，Q2=B）
 *
 * 對應規格：specs/features/bundle/銷售方案自動上下線.feature
 *   Rule: 自動下線那一刻，方案完全無法購買；購物車內未結帳的該方案項目一併失效
 *   Rule: 自動下線不影響「已成立」的訂單，既有訂單照常存在
 *
 * Q2=B（最嚴格）：
 * - 下線後（draft）新顧客無法將方案加入購物車（woocommerce_add_to_cart_validation 阻擋）
 * - 下線後 draft 方案天生不可購買（is_purchasable() = false）→ WC 原生會移除購物車內項目
 * - 已成立訂單不掃描購物車、不變更狀態 → 照常存在
 *
 * @group bundle
 * @group issue-247
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\BundleProduct\Service\Schedule;
use J7\PowerCourse\FrontEnd\Purchasable;

/**
 * Class BundleScheduleCartTest
 */
class BundleScheduleCartTest extends TestCase {

	protected function configure_dependencies(): void {
		// 確保 Purchasable 的 add_to_cart 阻擋 filter 已掛載
		Purchasable::instance();
	}

	/**
	 * 建立一個銷售方案（bundle 商品），含合法定價以排除「0 元放行」干擾
	 *
	 * @param string $status 文章狀態
	 * @param int    $offline 自動下線時間
	 * @return int bundle id
	 */
	private function create_bundle( string $status, int $offline = 0 ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '雙11限時方案',
				'post_status' => $status,
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_id, 'bundle_type', 'single_course' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, '100' );
		\update_post_meta( $bundle_id, '_price', '399' );
		\update_post_meta( $bundle_id, '_regular_price', '399' );
		if ( $offline ) {
			\update_post_meta( $bundle_id, Helper::SCHEDULE_OFFLINE_META_KEY, $offline );
		}
		\clean_post_cache( $bundle_id );
		return $bundle_id;
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線後新顧客無法將方案加入購物車(): void {
		$bundle_id = $this->create_bundle( 'publish', time() - 600 );

		// 輪詢後方案轉草稿
		Schedule::run_schedule();
		$this->assertSame( 'draft', \get_post_status( $bundle_id ), '方案應已下線為 draft' );

		// 模擬加入購物車驗證：通過 woocommerce_add_to_cart_validation filter
		$passed = \apply_filters( 'woocommerce_add_to_cart_validation', true, $bundle_id, 1 );

		$this->assertFalse( (bool) $passed, '下線（draft）的方案應無法加入購物車' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_未下線方案可正常加入購物車(): void {
		$bundle_id = $this->create_bundle( 'publish' );

		$passed = \apply_filters( 'woocommerce_add_to_cart_validation', true, $bundle_id, 1 );

		$this->assertTrue( (bool) $passed, '發佈中的方案應可正常加入購物車' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線後方案天生不可購買_購物車項目將被WC移除(): void {
		$bundle_id = $this->create_bundle( 'publish', time() - 600 );

		Schedule::run_schedule();

		$product = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );
		$this->assertFalse(
			$product->is_purchasable(),
			'draft 方案不可購買，WC 原生 check_cart_item_validity 會移除購物車內項目'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線前已成立的訂單不受影響(): void {
		$bundle_id = $this->create_bundle( 'publish', time() - 600 );

		// 建立一筆已成立訂單（含該方案）
		$order = new \WC_Order();
		$product = \wc_get_product( $bundle_id );
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->save();
		$order_id = $order->get_id();

		// 輪詢自動下線
		Schedule::run_schedule();
		$this->assertSame( 'draft', \get_post_status( $bundle_id ), '方案應已下線' );

		// 訂單照常存在且狀態不變
		$reloaded = \wc_get_order( $order_id );
		$this->assertNotFalse( $reloaded, '訂單應照常存在' );
		$this->assertSame( 'completed', $reloaded->get_status(), '訂單狀態不應因方案下線而改變' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_非bundle商品的加入購物車驗證不受影響(): void {
		// 一般草稿商品（非 bundle）不應被本功能阻擋
		$product_id = $this->factory()->post->create(
			[
				'post_title'  => '一般商品',
				'post_status' => 'draft',
				'post_type'   => 'product',
			]
		);

		$passed = \apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, 1 );

		$this->assertTrue( (bool) $passed, '非 bundle 商品不應被本功能阻擋（維持 WC 原生行為）' );
	}
}
