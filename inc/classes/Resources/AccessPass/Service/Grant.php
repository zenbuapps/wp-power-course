<?php
/**
 * AccessPass Grant（Issue #252）— 訂單開通授予持有關係
 *
 * 訂單達開通條件（course_access_trigger，預設 completed；訂閱走 woocommerce_subscription_payment_complete）時，
 * 若商品掛載了權限包（product meta access_pass_id），授予該使用者「權限包持有關係」（寫入 pc_user_access_pass）。
 *
 * 採 compute-on-read：**絕不**展開課程 id 寫入 avl_course_ids，僅記錄「user 持有 pass X、到期依 limit_type」。
 * 與既有 handle_single_course / handle_bind_courses 並列掛在相同 trigger 時機，不改既有逐課綁定流程（ASM-D1）。
 *
 * 到期計算（對齊 Limit::calc_expire_date 慣例）：
 *   - unlimited           → 0（永久）
 *   - fixed               → strtotime("+{limit_value} {limit_unit}")（購買後 N 天/月/年）
 *   - assigned            → (int) limit_value（指定日期的絕對 Unix timestamp，不經 strtotime）
 *   - follow_subscription → "subscription_{id}"（綁定該訂單對應訂閱）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class Grant
 * 訂單開通：將「使用者持有權限包」關係寫入 pc_user_access_pass（去重 upsert）。
 */
final class Grant {

	/** @var string 商品掛載權限包的 meta key */
	public const PRODUCT_META_KEY = 'access_pass_id';

	/**
	 * 取得「達開通條件」的訂單狀態集合
	 *
	 * = course_access_trigger 設定的狀態 + 一律含 'completed'。completed 是終端付款狀態，
	 * 若在 trigger（如 processing）就開通，抵達 completed 亦應開通。修正 pending→completed 直跳
	 * （REST 直接設 completed / 後台完成 pending 單 / 虛擬商品直達 completed）跳過 trigger 狀態，
	 * 導致課程與權限包完全不授予的靜默漏洞。
	 *
	 * 為 Order::__construct 的 hook 註冊與本類別授予閘門共用的單一真相來源。
	 *
	 * @return array<string> 例：trigger=processing → ['processing','completed']；trigger=completed → ['completed']
	 */
	public static function grant_statuses(): array {
		$trigger = (string) Settings::instance()->course_access_trigger;
		return \array_values( \array_unique( [ $trigger, 'completed' ] ) );
	}

	/**
	 * 訂單達 trigger 狀態時授予持有關係（一次性商品）
	 *
	 * 由 woocommerce_order_status_{trigger}（與 Order::add_meta_to_avl_course 並列）觸發，
	 * 或測試 / 外部直接以 order_id 呼叫。內部以「訂單目前狀態 = 設定的 trigger 狀態」為閘門，
	 * 避免 processing 等未達條件狀態誤授予。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return void
	 */
	public static function on_order_completed( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// 閘門：僅在訂單達到「開通狀態集合」時才授予（見 self::grant_statuses：trigger + completed）
		if ( ! \in_array( $order->get_status(), self::grant_statuses(), true ) ) {
			return;
		}

		self::grant_passes_from_order( $order );
	}

	/**
	 * 訂閱首期付款完成時授予持有關係（訂閱商品）
	 *
	 * 對齊既有 Order::add_course_item_meta_by_subscription：只在 parent order（首期）觸發、續訂不重複。
	 * 跟隨訂閱的到期表達式 = "subscription_{該訂閱 id}"。
	 *
	 * @param \WC_Subscription $subscription 訂閱物件
	 *
	 * @return void
	 */
	public static function on_subscription_payment_complete( \WC_Subscription $subscription ): void {
		$user_id = (int) $subscription->get_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		$subscription_id = (int) $subscription->get_id();

		/** @var array<int, \WC_Order_Item_Product> $items */
		$items = $subscription->get_items();
		foreach ( $items as $item ) {
			$pass_id = self::get_item_pass_id( $item );
			if ( $pass_id <= 0 ) {
				continue;
			}

			$pass = AccessPass::instance( $pass_id );
			if ( ! $pass instanceof AccessPass ) {
				continue;
			}

			// 訂閱商品掛 pass：跟隨訂閱模式綁定該訂閱；其餘模式仍依各自規則計算
			$expire_date = ( 'follow_subscription' === $pass->limit_type )
			? "subscription_{$subscription_id}"
			: self::calc_expire_date( $pass, null );

			self::grant( $user_id, $pass_id, null, $expire_date );
		}
	}

	/**
	 * 授予單筆持有關係（去重 upsert，絕不寫 avl_course_ids）
	 *
	 * @param int         $user_id         學員 user ID
	 * @param int         $pass_id         權限包 post ID
	 * @param int|null    $source_order_id 取得來源 WC 訂單 ID
	 * @param string|null $expire_date     到期表達式（已算好）；null 時由 caller 確保語義為永久
	 *
	 * @return void
	 */
	public static function grant( int $user_id, int $pass_id, ?int $source_order_id, ?string $expire_date ): void {
		if ( $user_id <= 0 || $pass_id <= 0 ) {
			return;
		}

		try {
			Repository::insert_or_update( $user_id, $pass_id, $source_order_id, $expire_date );
			// 持有關係異動：失效該 user 的 Gate request 級快取
			Gate::flush_cache( $user_id );
		} catch ( \Throwable $e ) {
			// best-effort：開通失敗記錄但不中斷其他 item（與既有課程開通一致）
			if ( \class_exists( \J7\WpUtils\Classes\WC::class ) ) {
				\J7\WpUtils\Classes\WC::log(
					$e->getMessage(),
					'AccessPass\\Grant::grant 授予持有關係失敗'
				);
			}
		}
	}

	/**
	 * 從訂單迴圈所有 item，對掛載 pass 的商品授予持有關係
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return void
	 */
	private static function grant_passes_from_order( \WC_Order $order ): void {
		$user_id = (int) $order->get_customer_id();
		if ( $user_id <= 0 ) {
			return;
		}

		/** @var array<int, \WC_Order_Item_Product> $items */
		$items = $order->get_items();
		foreach ( $items as $item ) {
			$pass_id = self::get_item_pass_id( $item );
			if ( $pass_id <= 0 ) {
				continue;
			}

			$pass = AccessPass::instance( $pass_id );
			if ( ! $pass instanceof AccessPass ) {
				continue;
			}

			$expire_date = self::calc_expire_date( $pass, $order );
			self::grant( $user_id, $pass_id, (int) $order->get_id(), $expire_date );
		}
	}

	/**
	 * 從訂單 item 讀取掛載的 access_pass_id（變體優先，fallback 主商品）
	 *
	 * @param \WC_Order_Item|\WC_Order_Item_Product $item 訂單項目
	 *
	 * @return int 掛載的 pass_id；無則回 0
	 */
	private static function get_item_pass_id( $item ): int {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return 0;
		}

		$product_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
		if ( $product_id <= 0 ) {
			return 0;
		}

		$pass_id = (int) \get_post_meta( $product_id, self::PRODUCT_META_KEY, true );

		// 變體無掛載時，fallback 查主商品
		if ( $pass_id <= 0 ) {
			$parent_id = (int) $item->get_product_id();
			if ( $parent_id > 0 && $parent_id !== $product_id ) {
				$pass_id = (int) \get_post_meta( $parent_id, self::PRODUCT_META_KEY, true );
			}
		}

		return $pass_id;
	}

	/**
	 * 依權限包期限模式計算到期表達式（對齊 Limit::calc_expire_date 慣例）
	 *
	 *   - unlimited           → '0'
	 *   - fixed               → strtotime("+{limit_value} {limit_unit}") 的 timestamp 字串（相對到期）
	 *   - assigned            → (string)(int) limit_value（指定日期的絕對 Unix timestamp，不經 strtotime）
	 *   - follow_subscription → "subscription_{id}"（由訂單對應的唯一 parent 訂閱推導；查無 → '0'）
	 *
	 * @param AccessPass     $pass  權限包 Model
	 * @param \WC_Order|null $order 訂單（follow_subscription 需要）
	 *
	 * @return string 到期表達式
	 */
	private static function calc_expire_date( AccessPass $pass, ?\WC_Order $order ): string {
		switch ( $pass->limit_type ) {
			case 'unlimited':
				return '0';

			case 'fixed':
				$value = (int) ( $pass->limit_value ?? 0 );
				$unit  = (string) ( $pass->limit_unit ?? 'day' );
				if ( $value <= 0 ) {
					return '0';
				}
				$timestamp = \strtotime( "+{$value} {$unit}" );
				return false === $timestamp ? '0' : (string) $timestamp;

			case 'assigned':
				// 指定日期到期：limit_value 已是絕對 Unix timestamp，直接回傳（不經 strtotime）
				return (string) (int) ( $pass->limit_value ?? 0 );

			case 'follow_subscription':
				return self::resolve_subscription_expire( $order );

			default:
				return '0';
		}
	}

	/**
	 * 由訂單推導跟隨訂閱的到期表達式 "subscription_{id}"
	 *
	 * 對齊 Limit::calc_expire_date：訂單對應唯一 parent 訂閱時回 "subscription_{id}"，否則 '0'。
	 *
	 * @param \WC_Order|null $order 訂單
	 *
	 * @return string
	 */
	private static function resolve_subscription_expire( ?\WC_Order $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return '0';
		}
		if ( ! \function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return '0';
		}

		$subscriptions = \wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'parent' ] );
		if ( \is_array( $subscriptions ) && 1 === \count( $subscriptions ) ) {
			$subscription    = \reset( $subscriptions );
			$subscription_id = (int) $subscription->get_id();
			return "subscription_{$subscription_id}";
		}

		return '0';
	}
}
