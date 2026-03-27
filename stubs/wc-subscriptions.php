<?php
/**
 * WooCommerce Subscriptions stubs for PHPStan
 *
 * @phpstan-ignore-file
 */

class WC_Subscription extends WC_Order {
	/**
	 * @return WC_Order|false
	 */
	public function get_parent() {
		return new WC_Order();
	}

	/**
	 * @param string $order_type
	 * @return array<int, int>
	 */
	public function get_related_orders( $order_type = 'any' ) {
		return [];
	}
}

class WC_Product_Subscription extends WC_Product {
}
