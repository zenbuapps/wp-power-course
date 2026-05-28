<?php
/**
 * 0 元銷售方案結帳 整合測試
 * Issue #231 Bug #2：手動 0 元主課程 + 0 元銷售方案時，銷售方案無法下單
 *
 * --- Spike 說明（重要）---
 * 本 CI runner 為全新 checkout，無 MySQL / WordPress 測試套件 / Powerhouse 私有外掛，
 * 無法在此環境執行 live spike。故根因確認以「WooCommerce 原生 is_purchasable() 行為」
 * 靜態推導：WC_Product::is_purchasable() 會在 `'' === get_price()` 時回傳 false，
 * 此即 add-to-cart / 結帳被擋的閘門（plan H1/H2）。
 *
 * 修法採 superset filter（FrontEnd\Purchasable）：僅針對「課程 / 方案 + publish + 價格 0 或空」
 * 將 is_purchasable 由 false 翻轉為 true，同時涵蓋 `'0'` 與 `''` 兩種價格表示法，
 * 且只翻 false→true，不影響既有可行路徑（AC8）與站內其他 0 元商品（防汙染）。
 *
 * 驗收標準：
 * - AC4：0 元主課程 + 0 元方案 → 可購買（add-to-cart 不被擋）
 * - AC5：0 元方案訂單完成後 → 自動授予課程授權（沿用既有 Resources\Order hook）
 * - AC8：主課程非 0 + 方案 0 → 行為不受影響
 * - 防汙染：非課程 / 非方案的 0 元商品不被 filter 介入
 *
 * @group bundle
 * @group free-course
 * @group order
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class FreeBundleCheckoutTest
 * 測試 0 元 / 空價課程與銷售方案的可購買性與授權流程
 */
class FreeBundleCheckoutTest extends TestCase {

	/** @var int 顧客用戶 ID */
	private int $customer_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 依賴 Bootstrap 已註冊的 FrontEnd\Purchasable 與 Resources\Order hooks
	}

	/**
	 * 每個測試前建立顧客
	 */
	public function set_up(): void {
		parent::set_up();

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_login' => 'buyer_' . uniqid(),
				'user_email' => 'buyer_' . uniqid() . '@test.com',
			]
		);
	}

	/**
	 * 建立課程商品
	 *
	 * @param string $price 價格（'0' | '' | '999' ...）
	 * @return int 課程商品 ID
	 */
	private function make_course( string $price ): int {
		$course_id = $this->factory()->post->create(
			[
				'post_title'  => '課程_' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $course_id, '_is_course', 'yes' );
		update_post_meta( $course_id, '_price', $price );
		update_post_meta( $course_id, '_regular_price', $price );

		return $course_id;
	}

	/**
	 * 建立銷售方案商品（連結指定課程）
	 *
	 * @param int    $course_id 連結課程 ID
	 * @param string $price     方案價格（'0' | '' ...）
	 * @return int 方案商品 ID
	 */
	private function make_bundle( int $course_id, string $price ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '方案_' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );
		update_post_meta( $bundle_id, '_price', $price );
		update_post_meta( $bundle_id, '_regular_price', $price );
		add_post_meta( $bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $course_id );

		return $bundle_id;
	}

	// ========== AC4：0 元 / 空價課程與方案可購買 ==========

	/**
	 * @test
	 * @group happy
	 * AC4：0 元主課程 + 0 元方案 → 方案商品可購買
	 */
	public function test_0元方案商品可購買(): void {
		$course_id = $this->make_course( '0' );
		$bundle_id = $this->make_bundle( $course_id, '0' );

		$bundle = wc_get_product( $bundle_id );

		$this->assertInstanceOf( \WC_Product::class, $bundle );
		$this->assertTrue(
			$bundle->is_purchasable(),
			'0 元銷售方案商品應為可購買'
		);
	}

	/**
	 * @test
	 * @group happy
	 * AC4：0 元主課程商品本身也應可購買
	 */
	public function test_0元課程商品可購買(): void {
		$course_id = $this->make_course( '0' );

		$course = wc_get_product( $course_id );

		$this->assertInstanceOf( \WC_Product::class, $course );
		$this->assertTrue(
			$course->is_purchasable(),
			'0 元課程商品應為可購買'
		);
	}

	/**
	 * @test
	 * @group edge
	 * 空價（'' 未定價）方案：WC 原生 is_purchasable() 會因 `'' === get_price()` 回傳 false，
	 * 這是 Bug #2 的核心閘門。filter 修復後應翻轉為 true。
	 *
	 * 此為真正的 Red→Green 斷言：移除 FrontEnd\Purchasable 後此測試會失敗。
	 */
	public function test_空價方案商品經filter修正後可購買(): void {
		$course_id = $this->make_course( '' );
		$bundle_id = $this->make_bundle( $course_id, '' );

		$bundle = wc_get_product( $bundle_id );

		$this->assertInstanceOf( \WC_Product::class, $bundle );
		$this->assertTrue(
			$bundle->is_purchasable(),
			'空價銷售方案經 woocommerce_is_purchasable filter 修正後應為可購買'
		);
	}

	// ========== 防汙染：非課程 / 非方案商品不受影響 ==========

	/**
	 * @test
	 * @group security
	 * 防汙染：一般（非課程、非方案）空價商品不應被 filter 放行，維持 WC 原生「不可購買」。
	 */
	public function test_一般空價商品不受filter影響(): void {
		$product_id = $this->factory()->post->create(
			[
				'post_title'  => '一般商品_' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		// 空價、非課程、非方案
		update_post_meta( $product_id, '_price', '' );
		update_post_meta( $product_id, '_regular_price', '' );

		$product = wc_get_product( $product_id );

		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertFalse(
			$product->is_purchasable(),
			'一般空價商品不屬於課程 / 方案，filter 不應介入，應維持不可購買'
		);
	}

	// ========== AC8：既有可行路徑不受影響 ==========

	/**
	 * @test
	 * @group happy
	 * AC8 回歸：主課程非 0（999）+ 方案 0 → 兩者皆可購買，行為不受影響。
	 */
	public function test_非0主課程加0元方案維持可購買(): void {
		$course_id = $this->make_course( '999' );
		$bundle_id = $this->make_bundle( $course_id, '0' );

		$course = wc_get_product( $course_id );
		$bundle = wc_get_product( $bundle_id );

		$this->assertTrue( $course->is_purchasable(), '非 0 課程本就可購買，行為不變' );
		$this->assertTrue( $bundle->is_purchasable(), '0 元方案可購買，行為不變' );
	}

	// ========== AC5：0 元方案訂單完成後自動授予課程授權 ==========

	/**
	 * @test
	 * @group happy
	 * AC5：購買 0 元方案，訂單完成後自動授予方案內課程的授權。
	 *
	 * 流程：建立訂單 → 加入方案商品 → 觸發 woocommerce_new_order（Resources\Order 將方案內
	 * 課程補成 0 元 order item）→ 訂單狀態改為 completed（觸發授權 hook）→ 斷言顧客取得課程授權。
	 */
	public function test_0元方案訂單完成後自動授予課程授權(): void {
		$course_id = $this->make_course( '0' );
		$bundle_id = $this->make_bundle( $course_id, '0' );

		// 顧客尚未擁有課程
		$this->assert_user_has_no_course_access( $this->customer_id, $course_id );

		// 建立訂單並加入方案商品
		$order = wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->add_product( wc_get_product( $bundle_id ), 1 );
		$order->save();

		// 模擬結帳：觸發 woocommerce_new_order，讓 Resources\Order 把方案內課程補成 order item
		do_action( 'woocommerce_new_order', $order->get_id(), $order );

		// 重新取得訂單（方案內課程 item 已補上）並完成訂單，觸發授權 hook
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );

		// Then 顧客應自動取得課程授權
		$this->assert_user_has_course_access( $this->customer_id, $course_id );
	}
}
