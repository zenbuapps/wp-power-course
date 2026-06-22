<?php
/**
 * 0 元課程 / 銷售方案可購買性修正
 *
 * Issue #231 Bug #2：手動將主課程價格設為 0（未開啟「免費課程」開關）
 * 且銷售方案也是 0 元時，銷售方案無法下單。
 *
 * 根因：WooCommerce 原生 WC_Product::is_purchasable() 會在
 * `'' === get_price()` 時回傳 false（價格未定價 → 不可購買）。
 * 當課程 / 方案價格被儲存為空字串或 0 時，WC 可能判定為不可購買，
 * 導致 add-to-cart 與結帳被擋。
 *
 * 修法：掛 `woocommerce_is_purchasable` filter，**僅針對課程商品與銷售方案商品**、
 * 且狀態為 publish、且不可購買的原因為「價格為 0 或空」時，強制放行為可購買。
 * 其餘所有商品一律回傳原值，避免汙染站內其他 0 元商品。
 *
 * 此 filter 只處理「價格」面向，不碰庫存（is_in_stock 仍獨立判斷），
 * 也只會將 false 翻轉為 true（superset），不會把可購買的商品改為不可購買，
 * 因此「主課程非 0 + 方案 0」等既有可行路徑行為不受影響。
 *
 * @see https://github.com/zenbuapps/wp-power-course/issues/231
 */

declare(strict_types=1);

namespace J7\PowerCourse\FrontEnd;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * 強制 0 元課程 / 方案可購買
 */
final class Purchasable {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter( 'woocommerce_is_purchasable', [ $this, 'force_free_course_purchasable' ], 10, 2 );
		// Issue #247（Q2=B）：自動下線（draft）的銷售方案完全無法購買，阻擋新的加入購物車。
		// 購物車內既有項目由 WC 原生 check_cart_item_validity()（依 is_purchasable）自動移除。
		\add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_offline_bundle_add_to_cart' ], 10, 3 );
	}

	/**
	 * 阻擋已下線（非 publish）的銷售方案加入購物車（Issue #247，Q2=B）
	 *
	 * 僅作用於銷售方案商品；其餘商品一律回傳原值，維持 WooCommerce 原生行為。
	 *
	 * @param bool $passed     WooCommerce 判定是否可加入購物車。
	 * @param int  $product_id 欲加入的商品 ID。
	 * @param int  $quantity   數量（本判斷不使用，僅符合 filter 簽章）。
	 *
	 * @return bool 是否允許加入購物車。
	 */
	public function block_offline_bundle_add_to_cart( $passed, $product_id, $quantity ): bool {
		$passed = (bool) $passed;

		// 前面的驗證已不通過就不介入
		if ( ! $passed ) {
			return false;
		}

		$product = \wc_get_product( $product_id );
		if ( ! ( $product instanceof \WC_Product ) ) {
			return $passed;
		}

		// 僅針對銷售方案商品
		$is_bundle = (bool) ( Helper::instance( $product )?->is_bundle_product );
		if ( ! $is_bundle ) {
			return $passed;
		}

		// 已下線（非 publish）→ 阻擋並提示
		if ( 'publish' !== $product->get_status() ) {
			if ( \function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( esc_html__( 'This bundle is no longer available for purchase.', 'power-course' ), 'error' );
			}
			return false;
		}

		return $passed;
	}

	/**
	 * 強制 0 元 / 空價的課程商品與銷售方案商品為可購買
	 *
	 * @param bool  $purchasable WooCommerce 判定的可購買性。
	 * @param mixed $product     商品（WC filter 傳入，理應為 \WC_Product，但防呆仍判斷型別）。
	 *
	 * @return bool 修正後的可購買性。
	 */
	public function force_free_course_purchasable( $purchasable, $product ): bool {
		$purchasable = (bool) $purchasable;

		// 已經可購買就不介入
		if ( $purchasable ) {
			return true;
		}

		// 防呆：非 WC_Product 一律回原值
		if ( ! ( $product instanceof \WC_Product ) ) {
			return $purchasable;
		}

		// 僅限已發佈商品（草稿 / 私密商品維持 WC 原生行為）
		if ( 'publish' !== $product->get_status() ) {
			return $purchasable;
		}

		// 嚴格 scope：僅課程商品或銷售方案商品才放行，避免汙染站內其他 0 元商品
		$is_course = CourseUtils::is_course_product( $product );
		$is_bundle = (bool) ( Helper::instance( $product )?->is_bundle_product );
		if ( ! $is_course && ! $is_bundle ) {
			return $purchasable;
		}

		// 僅在「不可購買的原因為價格為 0 或空」時放行；
		// 若商品因其他原因（如非 publish）不可購買，前面已 return，這裡只處理價格面向。
		$price                  = $product->get_price();
		$is_zero_or_empty_price = ( '' === $price || 0.0 === (float) $price );
		if ( ! $is_zero_or_empty_price ) {
			return $purchasable;
		}

		return true;
	}
}
