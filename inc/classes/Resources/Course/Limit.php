<?php
/**
 * 課程的觀看限制 Limit
 * 可以指定為 "無期限"、"購買後固定時間"、"指定日期"、"跟隨訂閱"
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

/**
 * Class Limit
 */
class Limit {

	/**
	 * 限制類型
	 *
	 * @var string $limit_type 限制類型 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	 */
	public string $limit_type;

	/**
	 * 限制值
	 *
	 * @var int|null $limit_value 限制值
	 */
	public int|null $limit_value;

	/**
	 * 限制單位
	 *
	 * @var string|null $limit_unit 限制單位 'timestamp' | 'day' | 'month' | 'year'
	 */
	public string|null $limit_unit;

	/**
	 * Constructor
	 *
	 * @param string      $limit_type 限制類型 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	 * @param int|null    $limit_value 限制值
	 * @param string|null $limit_unit 限制單位 'timestamp' | 'day' | 'month' | 'year'
	 */
	public function __construct( string $limit_type, int|null $limit_value, string|null $limit_unit ) {
		$this->set_limit_type($limit_type);
		$this->set_limit_value($limit_value);
		$this->set_limit_unit($limit_unit);
	}

	/**
	 * 計算到期日 expire_date
	 *
	 * @param ?\WC_Order $order 訂單
	 * @return int|string 到期日 timestamp | subscription_{訂閱id}
	 */
	public function calc_expire_date( ?\WC_Order $order ): int|string {

		$expire_date = 0;

		if ('unlimited' === $this->limit_type) {
			return $expire_date;
		}
		if ('assigned' === $this->limit_type) {
			return (int) $this->limit_value; // timestamp
		}
		if ('fixed' === $this->limit_type) {
			$expire_date_timestamp = (int) strtotime("+{$this->limit_value} {$this->limit_unit}");
			// 將 timestamp 轉換為當天的日期，並固定在當天的 15:59:00
			$expire_date_string = date('Y-m-d', $expire_date_timestamp) . ' 15:59:00';
			return (int) strtotime($expire_date_string);
		}

		if (!$order) {
			return $expire_date;
		}

		// 所有條件都判斷完了，剩下的就是 follow_subscription
		// 'follow_subscription' === $limit_type
		if (!class_exists('WC_Subscription')) {
			\J7\WpUtils\Classes\WC::log(
				sprintf(
					/* translators: %d: 訂單 ID */
					esc_html__( 'Failed to calculate expire_date for order %d because WC_Subscription is not available', 'power-course' ),
					$order->get_id()
				),
				'CourseUtils::calc_expire_date'
			);
			return $expire_date;
		}

		$subscriptions = \wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'parent' ] );

		if ( (bool) $subscriptions && count($subscriptions) === 1) {
			$subscription    = reset($subscriptions);
			$subscription_id = $subscription->get_id();
			return "subscription_{$subscription_id}";
		}

		return $expire_date;
	}

	/**
	 * 取得 ExpireDate 實例
	 *
	 * @param ?\WC_Order $order 訂單
	 * @return ExpireDate
	 */
	public function get_expire_date( ?\WC_Order $order ): ExpireDate {
		$expire_date = $this->calc_expire_date($order);
		return new ExpireDate($expire_date);
	}

	/**
	 * 取得限制標籤文字
	 * {類型} {值}
	 * ex: 固定時間 10 天, 指定日期 2024-01-01, 跟隨訂閱, 無限制
	 *
	 * @return object{type:string, value:string}
	 */
	public function get_limit_label(): object {
		$limit_type_label = match ( $this->limit_type ) {
			'fixed'    => esc_html__( 'Fixed duration', 'power-course' ),
			'assigned' => esc_html__( 'Assigned date', 'power-course' ),
			'follow_subscription' => esc_html__( 'Follow subscription', 'power-course' ),
			default    => esc_html__( 'Unlimited', 'power-course' ),
		};

		$limit_value_label = match ( $this->limit_unit ) {
			'timestamp' => strlen( (string) $this->limit_value) !== 10 ? '' : \wp_date( 'Y-m-d H:i', $this->limit_value ),
			'month'  => sprintf(
				/* translators: %d: 月數 */
				esc_html__( '%d months', 'power-course' ),
				(int) $this->limit_value
			),
			'year'   => sprintf(
				/* translators: %d: 年數 */
				esc_html__( '%d years', 'power-course' ),
				(int) $this->limit_value
			),
			default  => $this->limit_value
			? sprintf(
					/* translators: %d: 天數 */
					esc_html__( '%d days', 'power-course' ),
					(int) $this->limit_value
				)
			: '',
		};

		if ( in_array($this->limit_type, [ 'unlimited', 'follow_subscription' ], true) ) {
			$limit_value_label = '';
		}

		/** @var string $limit_value_label */
		return (object) [
			'type'  => $limit_type_label,
			'value' => $limit_value_label,
		];
	}

	/**
	 * 取得限制實例
	 *
	 * @param \WC_Product|int $product 課程或課程ID
	 * @return self
	 * @throws \Exception 如果課程不存在
	 */
	public static function instance( \WC_Product|int $product ): self {
		if (is_numeric($product)) {
			$product = \wc_get_product($product);
			if (!$product) {
				throw new \Exception('Course Product not found');
			}
		}
		$limit_type  = (string) $product->get_meta( 'limit_type' );
		$limit_value = (int) $product->get_meta( 'limit_value' ) ?: null;
		$limit_unit  = (string) $product->get_meta( 'limit_unit' ) ?: null;

		return new self($limit_type, $limit_value, $limit_unit);
	}


	/**
	 * 從 order item 的快照 meta 取得限制實例
	 *
	 * 下單當時 Order::_handle_add_course_item_meta_by_order_item() 會把商品的
	 * limit_type / limit_value / limit_unit 以 `_` 前綴寫成 order item meta，
	 * 意圖就是「用購買當下的條件」——但這份快照長期沒有任何讀取端，
	 * 到期日一律由 self::instance( $product_id ) 從**商品現況**重算。
	 * 後果是站長改了商品期限設定後，舊訂單只要再次進入 trigger 狀態，
	 * 既有學員的到期日就被回溯改寫（LifeCycle 對 expire_date 是無條件覆寫）。
	 *
	 * ⚠️ 本方法只凍結「限制條件」（type / value / unit），**不凍結計算基準點**：
	 * `calc_expire_date()` 對 limit_type = 'fixed' 仍是從「重算當下」往後推 N 天，
	 * 不是從下單日往後推。要連基準點一起凍結是更大的行為變更，不在本次範圍。
	 *
	 * 快照不存在（修復上線前的訂單、非課程商品）時回傳 null，由呼叫端 fallback。
	 *
	 * @param \WC_Order_Item|\WC_Order_Item_Product $item 訂單項目
	 * @return self|null 快照存在時回傳實例，否則 null
	 */
	public static function from_order_item( $item ): ?self {
		if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
			return null;
		}

		// 判準用 meta_exists 而非「值是不是空字串」：寫入端是
		// `foreach ( Limit::get_meta_keys() )` **無條件**寫三個 key，
		// 商品沒設 limit_type 時寫進去的就是空字串。
		// 用空字串當「沒有快照」會把「快照就是未設定」誤判成「沒有快照」。
		// （兩者最終結果相同——Limit::set_limit_type('') 也會 fallback 'unlimited'——
		// 但判準本身要精確，否則下一個人改 set_limit_type 的預設就會踩到。）
		if ( ! $item->meta_exists( '_limit_type' ) ) {
			return null;
		}

		$limit_type = (string) $item->get_meta( '_limit_type' );

		$limit_value = (int) $item->get_meta( '_limit_value' ) ?: null;
		$limit_unit  = (string) $item->get_meta( '_limit_unit' ) ?: null;

		return new self( $limit_type, $limit_value, $limit_unit );
	}

	/**
	 * 取得限制的 meta keys (存在 post meta 中)
	 *
	 * @return array<string>
	 */
	public static function get_meta_keys(): array {
		return [ 'limit_type', 'limit_value', 'limit_unit' ];
	}

	/**
	 * 設定限制類型
	 *
	 * @param string $limit_type 限制類型 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	 */
	private function set_limit_type( string $limit_type ): void {
		$this->limit_type = in_array($limit_type, [ 'unlimited', 'fixed', 'assigned', 'follow_subscription' ], true) ? $limit_type : 'unlimited';
	}

	/**
	 * 設定限制值
	 *
	 * @param int|null $limit_value 限制值
	 */
	private function set_limit_value( int|null $limit_value ): void {
		if (!$limit_value) {
			$this->limit_value = null;
			return;
		}
		$this->limit_value = $limit_value;
	}

	/**
	 * 設定限制單位
	 *
	 * @param string|null $limit_unit 限制單位 'timestamp' | 'day' | 'month' | 'year'
	 * @throws \Exception 如果限制單位無效
	 */
	private function set_limit_unit( string|null $limit_unit ): void {
		if (!$limit_unit) {
			$this->limit_unit = null;
			return;
		}
		if (!in_array($limit_unit, [ 'timestamp', 'day', 'month', 'year' ], true)) {
			\J7\WpUtils\Classes\WC::log($limit_unit, 'set_limit_unit Invalid limit unit');
		}
		$this->limit_unit = $limit_unit;
	}
}
