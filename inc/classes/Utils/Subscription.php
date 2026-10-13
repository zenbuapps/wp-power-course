<?php
/**
 * WooCommerce Subscription 相關工具類別
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * WC Subscription meta 欄位讀取、正規化與刪除，以及訂單所屬訂閱解析的靜態工具類別
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

	/** @var array<int, string> 訂單 → 訂閱的關聯類型，依解析優先序排列（見 get_subscription_id_for_order） */
	const ORDER_RELATION_TYPES = [ 'parent', 'renewal', 'switch' ];

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
	 * 驗證訂閱功能是否可用（預設偵測 WooCommerce Subscriptions 的 WC_Subscription 類別）
	 *
	 * @return \WP_Error|true 訂閱功能不可用時回傳 WP_Error，否則回傳 true
	 */
	public static function validate_class(): \WP_Error|bool {
		/**
		 * Filters 訂閱功能是否可用。
		 * 供其他訂閱方案介接，或供測試在不定義 WC_Subscription class 的情況下模擬已安裝。
		 *
		 * @param bool $is_available 預設值為 WC_Subscription class 是否存在
		 */
		$is_available = (bool) \apply_filters( 'power_course_subscription_available', \class_exists( 'WC_Subscription' ) );

		if ( ! $is_available ) {
			return new \WP_Error(
				'subscription_class_not_found',
				__( 'WC_Subscription class does not exist, please ensure WooCommerce Subscriptions is installed', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * 找出這張訂單付款對應的訂閱 ID（跟隨訂閱 follow_subscription 的到期表達式來源）
	 *
	 * 開通掛在 woocommerce_order_status_{trigger}，續訂單 / 升降級單進入開通狀態時同樣會觸發，
	 * 所以不能只認 parent（首期單）：續訂單只有 renewal 關聯，只查 parent 必然查無，
	 * 呼叫端 fallback 後課程變永久、通行證變失效（Issue #280）。
	 *
	 * 依 ORDER_RELATION_TYPES 的順序逐一查詢，**第一個查得到訂閱的關聯類型決定答案**：
	 * - 恰好一張 → 回傳該訂閱 ID
	 * - 多張 → 無法判斷是哪一張，回 null（沿用修復前 parent 查到多張的行為）
	 *
	 * parent 排第一：升降級若另開新訂閱，該訂單是新訂閱的 parent、同時是舊訂閱的 switch，應綁新訂閱。
	 * 不含 resubscribe：重新訂閱單對舊訂閱是 resubscribe、對新訂閱是 parent，綁新訂閱，parent 已涵蓋。
	 *
	 * @param \WC_Order|null $order 訂單
	 * @return int|null 訂閱 ID；查無或無法判斷時回 null
	 */
	public static function get_subscription_id_for_order( ?\WC_Order $order ): ?int {
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		foreach ( self::ORDER_RELATION_TYPES as $relation_type ) {
			$subscription_ids = self::get_related_subscription_ids( $order, $relation_type );
			if ( ! $subscription_ids ) {
				continue;
			}

			return 1 === \count( $subscription_ids ) ? $subscription_ids[0] : null;
		}

		return null;
	}

	/**
	 * 取得訂單在指定關聯類型下對應的訂閱 ID
	 *
	 * @param \WC_Order $order         訂單
	 * @param string    $relation_type 關聯類型 'parent' | 'renewal' | 'switch'
	 * @return array<int, int> 去重後的訂閱 ID
	 */
	private static function get_related_subscription_ids( \WC_Order $order, string $relation_type ): array {
		$subscription_ids = [];

		if ( \function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			$subscriptions = \wcs_get_subscriptions_for_order( $order, [ 'order_type' => $relation_type ] );
			foreach ( \is_array( $subscriptions ) ? $subscriptions : [] as $subscription ) {
				if ( $subscription instanceof \WC_Subscription ) {
					$subscription_ids[] = (int) $subscription->get_id();
				}
			}
		}

		/**
		 * Filters 訂單在指定關聯類型下對應的訂閱 ID。
		 * 供其他訂閱方案介接，或供測試在未安裝 WooCommerce Subscriptions 時模擬訂單與訂閱的關聯。
		 *
		 * @param array<int, int> $subscription_ids 訂閱 ID
		 * @param \WC_Order       $order            訂單
		 * @param string          $relation_type    關聯類型 'parent' | 'renewal' | 'switch'
		 */
		$subscription_ids = \apply_filters( 'power_course_order_subscription_ids', $subscription_ids, $order, $relation_type );

		// filter 回傳值不可信：只收正整數，並去重
		$normalized_ids = [];
		foreach ( \is_array( $subscription_ids ) ? $subscription_ids : [] as $subscription_id ) {
			if ( \is_numeric( $subscription_id ) && (int) $subscription_id > 0 ) {
				$normalized_ids[] = (int) $subscription_id;
			}
		}

		return \array_values( \array_unique( $normalized_ids ) );
	}
}
