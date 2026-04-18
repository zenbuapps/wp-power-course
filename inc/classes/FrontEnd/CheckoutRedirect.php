<?php
/**
 * 結帳頁 add-to-cart URL 參數清除
 *
 * 修正使用者在結帳頁按重整後，因 URL 中的 ?add-to-cart=XXX 參數持續存在，
 * 導致 WooCommerce 重複將商品加入購物車的問題。
 *
 * WooCommerce 在 wp_loaded (priority 20) 的 WC_Form_Handler::add_to_cart_action()
 * 已處理完 $_GET['add-to-cart']，template_redirect 在其之後執行，
 * 此時購物車已更新，可安全 302 redirect 到乾淨 URL。
 *
 * @see https://github.com/zenbuapps/wp-power-course/issues/200
 */

declare(strict_types=1);

namespace J7\PowerCourse\FrontEnd;

/**
 * 結帳頁 redirect：清除 add-to-cart URL 參數
 */
final class CheckoutRedirect {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 20 );
	}

	/**
	 * 偵測結帳頁含 add-to-cart 參數時，302 redirect 到乾淨 URL
	 *
	 * WC 在 wp_loaded (priority 20) 已處理完加入購物車，
	 * 此處僅負責清除 URL 參數，避免重整時重複加入。
	 *
	 * @return void
	 */
	public function maybe_redirect(): void {
		// WooCommerce 未安裝或未啟用時不執行
		if ( ! \function_exists( 'is_checkout' ) ) {
			return;
		}

		// 僅結帳頁觸發
		if ( ! \is_checkout() ) {
			return;
		}

		// 無 add-to-cart 參數時不需處理
		if ( ! isset( $_GET['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// 移除 add-to-cart 與 quantity 參數，保留其他參數（如 coupon）
		$clean_url = \remove_query_arg( [ 'add-to-cart', 'quantity' ] );

		\wp_safe_redirect( $clean_url, 302 );
		exit;
	}
}
