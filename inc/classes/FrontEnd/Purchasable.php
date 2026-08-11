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
 * 本類別另負責銷售方案的「可販售性」把關（Issue #247、#260、#261）：
 * 加入購物車、購物車 / 結帳頁載入、送單前三個時點，一律以
 * `BundleProduct\Helper::is_visible_on_frontend()` 為判準。
 *
 * @see https://github.com/zenbuapps/wp-power-course/issues/231
 * @see https://github.com/zenbuapps/wp-power-course/issues/261
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
		\add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_offline_bundle_add_to_cart' ], 10, 3 );
		// Issue #261：WC 原生 check_cart_item_validity() 只移除 trash 商品、不看 is_purchasable，
		// 因此「加入購物車後才下線」的方案會留在購物車並順利結帳。此處於購物車 / 結帳頁主動移除。
		\add_action( 'woocommerce_check_cart_items', [ $this, 'remove_unavailable_bundle_from_cart' ] );
		// Issue #261：使用者停在結帳頁不重新整理直接送出的 race，於送單前再擋一層。
		\add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_bundle_on_checkout' ], 10, 2 );
		// Issue #261：區塊購物車 / 結帳（Store API）走另一套驗證，補上同一份判準。
		\add_action( 'woocommerce_store_api_validate_cart_item', [ $this, 'validate_bundle_on_store_api' ], 10, 2 );
	}

	/**
	 * 阻擋已下線 / 尚未到上線時間的銷售方案加入購物車（Issue #247 Q2=B、Issue #260）
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

		// 已下線（非 publish）或尚未到自動上線時間 → 阻擋並提示
		if ( ! Helper::is_visible_on_frontend( $product ) ) {
			if ( \function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice( esc_html__( 'This bundle is no longer available for purchase.', 'power-course' ), 'error' );
			}
			return false;
		}

		return $passed;
	}

	/**
	 * 將已不可販售的銷售方案移出購物車（Issue #261）
	 *
	 * 掛在 `woocommerce_check_cart_items`，購物車頁與結帳頁載入時皆會觸發。
	 *
	 * 為什麼必須自己做：WooCommerce 11 的 `WC_Cart::check_cart_item_validity()`
	 * 只移除 `trash` 狀態的商品，**不檢查 `is_purchasable()`**，`draft` 商品會原封不動
	 * 留在購物車並完成結帳。`is_purchasable()` 在傳統流程只用於 `WC_Cart::add_to_cart()`，
	 * 也就是只擋「加入購物車那一刻」。區塊結帳（Store API）另有 `validate_cart_item()`
	 * 會檢查，故本 bug 只影響傳統 shortcode 購物車 / 結帳。
	 *
	 * 只處理「方案已下線 / 尚未到上線時間」這個 WC 不管的面向；缺貨仍交由 WC 原生
	 * `check_cart_item_stock()` 以錯誤訊息提示，維持既有行為。
	 *
	 * @return void
	 */
	public function remove_unavailable_bundle_from_cart(): void {
		$cart = \function_exists( 'WC' ) ? \WC()->cart : null;
		if ( ! ( $cart instanceof \WC_Cart ) ) {
			return;
		}

		$removed = false;
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! self::is_unavailable_bundle( $product ) ) {
				continue;
			}
			/** @var \WC_Product $product */

			// 第三參數 false：迴圈中不重算，統一於迴圈後算一次
			$cart->set_quantity( $cart_item_key, 0, false );
			$removed = true;

			if ( \function_exists( 'wc_add_notice' ) ) {
				\wc_add_notice(
					sprintf(
						/* translators: %s: 銷售方案名稱 */
						\esc_html__( '"%s" is no longer available for purchase and has been removed from your cart.', 'power-course' ),
						\esc_html( $product->get_name() )
					),
					'error'
				);
			}
		}

		if ( $removed ) {
			$cart->calculate_totals();
		}
	}

	/**
	 * 送單前再驗一次購物車內的銷售方案（Issue #261）
	 *
	 * 掛在 `woocommerce_after_checkout_validation`，避免使用者停在結帳頁
	 * 不重新整理、方案於期間下線後仍直接送出訂單的 race。
	 *
	 * @param mixed $data   結帳表單資料（本驗證不使用，僅符合 hook 簽章）。
	 * @param mixed $errors 錯誤集合，理應為 \WP_Error。
	 *
	 * @return void
	 */
	public function validate_bundle_on_checkout( $data, $errors ): void {
		if ( ! ( $errors instanceof \WP_Error ) ) {
			return;
		}

		$cart = \function_exists( 'WC' ) ? \WC()->cart : null;
		if ( ! ( $cart instanceof \WC_Cart ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'] ?? null;
			if ( ! self::is_unavailable_bundle( $product ) ) {
				continue;
			}
			/** @var \WC_Product $product */

			$errors->add(
				'pc_bundle_unavailable',
				sprintf(
					/* translators: %s: 銷售方案名稱 */
					\esc_html__( '"%s" is no longer available for purchase. Please remove it from your cart and try again.', 'power-course' ),
					\esc_html( $product->get_name() )
				)
			);
		}
	}

	/**
	 * 區塊購物車 / 結帳（Store API）的方案可販售性驗證（Issue #261）
	 *
	 * Store API 原生 `validate_cart_item()` 只看 `is_purchasable()`，
	 * 涵蓋不到「已 publish 但上線時間尚未到點」的方案（Issue #260 的髒狀態）。
	 *
	 * 必須丟 `RouteException`：`CartController::validate_cart_items()` 只捕捉
	 * RouteException 與四個 WC 內建例外，丟一般 \Exception 會直接冒成 500。
	 *
	 * 訊息不做 esc_html：Store API 回傳的是 JSON，escape 後 `"` 會變成字面的 `&quot;`。
	 *
	 * @param mixed $product   商品物件。
	 * @param mixed $cart_item 購物車項目（本驗證不使用，僅符合 hook 簽章）。
	 *
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException 方案已不可販售時中止流程。
	 */
	public function validate_bundle_on_store_api( $product, $cart_item ): void {
		if ( ! self::is_unavailable_bundle( $product ) ) {
			return;
		}

		if ( ! class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			return;
		}

		/** @var \WC_Product $product */
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'pc_bundle_unavailable',
			sprintf(
				/* translators: %s: 銷售方案名稱 */
				\__( '"%s" is no longer available for purchase. Please remove it from your cart and try again.', 'power-course' ),
				$product->get_name()
			),
			409
		);
	}

	/**
	 * 判斷購物車項目是否為「已不可販售的銷售方案」（Issue #261）
	 *
	 * @param mixed $product 購物車項目的商品物件。
	 *
	 * @return bool true = 是銷售方案且已不可在前台販售。
	 */
	private static function is_unavailable_bundle( $product ): bool {
		if ( ! ( $product instanceof \WC_Product ) ) {
			return false;
		}

		$is_bundle = (bool) ( Helper::instance( $product )?->is_bundle_product );
		if ( ! $is_bundle ) {
			return false;
		}

		return ! Helper::is_visible_on_frontend( $product );
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
