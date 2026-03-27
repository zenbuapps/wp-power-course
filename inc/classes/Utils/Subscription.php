<?php
/**
 * WooCommerce Subscription 相關工具類別
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * WC Subscription meta 欄位讀取、正規化與刪除的靜態工具類別
 */
abstract class Subscription {

	/** @var string 訂閱週期預設值 */
	const DEFAULT_PERIOD = 'month';

	/** @var string 試用期間單位預設值 */
	const DEFAULT_TRIAL_PERIOD = 'day';

	/** @var int 訂閱週期間隔預設值 */
	const DEFAULT_PERIOD_INTERVAL = 1;

	/** @var int 訂閱長度預設值（0 = 不限制） */
	const DEFAULT_LENGTH = 0;

	/**
	 * WC Subscription 的 meta 欄位名稱列表
	 *
	 * @return array<int, string>
	 */
	public static function get_fields(): array {
		return [
			'_subscription_price',
			'_subscription_period_interval',
			'_subscription_period',
			'_subscription_length',
			'_subscription_sign_up_fee',
			'_subscription_trial_length',
			'_subscription_trial_period',
		];
	}

	/**
	 * 從 WC_Product 讀取並正規化所有訂閱相關 meta 欄位
	 *
	 * @param \WC_Product $product WooCommerce 商品物件
	 * @return array<string, mixed> 已正規化的訂閱 meta 欄位陣列
	 */
	public static function get_normalized_meta( \WC_Product $product ): array {
		$subscription_price           = $product->get_meta( '_subscription_price' );
		$subscription_period_interval = $product->get_meta( '_subscription_period_interval' );
		$subscription_period          = $product->get_meta( '_subscription_period' );
		$subscription_length          = $product->get_meta( '_subscription_length' );
		$subscription_sign_up_fee     = $product->get_meta( '_subscription_sign_up_fee' );
		$subscription_trial_length    = $product->get_meta( '_subscription_trial_length' );
		$subscription_trial_period    = $product->get_meta( '_subscription_trial_period' );

		return [
			'_subscription_price'           => is_numeric( $subscription_price ) ? (float) $subscription_price : null,
			'_subscription_period_interval' => is_numeric( $subscription_period_interval ) ? (int) $subscription_period_interval : self::DEFAULT_PERIOD_INTERVAL,
			'_subscription_period'          => $subscription_period ?: self::DEFAULT_PERIOD,
			'_subscription_length'          => is_numeric( $subscription_length ) ? (int) $subscription_length : self::DEFAULT_LENGTH,
			'_subscription_sign_up_fee'     => is_numeric( $subscription_sign_up_fee ) ? (float) $subscription_sign_up_fee : null,
			'_subscription_trial_length'    => is_numeric( $subscription_trial_length ) ? (int) $subscription_trial_length : null,
			'_subscription_trial_period'    => $subscription_trial_period ?: self::DEFAULT_TRIAL_PERIOD,
		];
	}

	/**
	 * 刪除商品上所有訂閱相關 meta 欄位
	 *
	 * @param \WC_Product $product WooCommerce 商品物件
	 * @return void
	 */
	public static function delete_meta( \WC_Product $product ): void {
		foreach ( self::get_fields() as $field ) {
			$product->delete_meta_data( $field );
		}
	}

	/**
	 * 驗證 WC_Subscription 類別是否存在
	 *
	 * @return \WP_Error|true 類別不存在時回傳 WP_Error，否則回傳 true
	 */
	public static function validate_class(): \WP_Error|bool {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			return new \WP_Error(
				'subscription_class_not_found',
				'WC_Subscription 訂閱商品類別不存在，請確認是否安裝 WooCommerce Subscriptions',
				[ 'status' => 400 ]
			);
		}

		return true;
	}
}
