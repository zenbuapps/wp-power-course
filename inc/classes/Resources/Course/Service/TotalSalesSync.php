<?php
/**
 * 課程已售出數量（total_sales）同步服務
 *
 * Issue #228：WooCommerce 課程商品的 total_sales 過去「只進不出」，
 * 訂單取消 / 退費 / 付款失敗時不會扣減，導致數值虛高。
 *
 * 本服務讓 Power Course 主動接管「課程相關商品」的 total_sales 增減：
 * - 訂單進入 course_access_trigger 狀態 → increment
 * - 訂單離開觸發狀態（cancelled / failed / pending / on-hold）→ decrement
 * - 退費（全額 / 部分）→ 逐被退品項對應課程 decrement
 * - 以 order meta `_pc_counted_in_total_sales` 旗標保證冪等
 * - 訂閱：首次付款成功 +1，後續取消 / 過期 / 續訂不重複增減
 * - Bundle：依 pbp_product_quantities × 購買份數累計到方案內各課程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class TotalSalesSync
 */
final class TotalSalesSync {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 訂單是否已計入 total_sales 的 meta key */
	const META_COUNTED = '_pc_counted_in_total_sales';

	/** @var array<string> 訂單離開觸發狀態後、應扣減 total_sales 的目標狀態（排除 refunded，退費統一走 woocommerce_order_refunded） */
	const DECREMENT_STATUSES = [ 'cancelled', 'failed', 'pending', 'on-hold' ];

	/** Constructor */
	public function __construct() {
		// 決策 A：接管課程相關商品的 total_sales，移除 WC 原生計數掛載避免雙計
		$this->remove_wc_native_total_sales_hooks();

		// 訂單狀態變更：進入觸發狀態 +、離開觸發狀態 −
		\add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_changed' ], 10, 4 );

		// 退費（全額 / 部分）：逐被退品項對應課程扣減
		\add_action( 'woocommerce_order_refunded', [ $this, 'handle_order_refunded' ], 10, 2 );
	}

	/**
	 * 移除 WooCommerce 原生 total_sales 計數掛載
	 *
	 * WC 原生 `wc_update_total_sales_counts()` 只在訂單進入特定狀態時 +1，
	 * 且不會在退費 / 取消時扣減（即 Issue #228 根因）。本服務全面接管，
	 * 故移除原生掛載，避免課程商品被 +2（雙計）。
	 *
	 * @return void
	 */
	private function remove_wc_native_total_sales_hooks(): void {
		if ( ! function_exists( 'wc_update_total_sales_counts' ) ) {
			return;
		}
		foreach ( [ 'completed', 'processing', 'on-hold', 'cancelled', 'refunded' ] as $status ) {
			\remove_action( "woocommerce_order_status_{$status}", 'wc_update_total_sales_counts' );
		}
	}

	/**
	 * 取得目前的 course_access_trigger 觸發狀態（不含 wc- 前綴）
	 *
	 * @return string
	 */
	private function get_trigger(): string {
		return Settings::instance()->course_access_trigger;
	}

	/**
	 * 解析訂單中「哪些課程被開通、各被開通幾份」
	 *
	 * 與 Order::add_meta_to_avl_course 的「哪些課程被開通」解析一致（Q1=C）：
	 * - 課程商品（is_course_product）→ 課程本身 +qty
	 * - 綁課商品（bind_courses_data）→ 各綁定課程 +qty
	 *
	 * 銷售方案（Bundle）已於下單時（woocommerce_new_order，見 Order.php）
	 * 展開成實際 order item（qty = pbp_product_quantities × 購買份數），
	 * 故此處直接逐 item 解析即可，**不**再次展開 bundle 商品本身
	 * （bundle 商品本身非課程、無 bind_courses_data，貢獻 0），避免雙計。
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, int> [ course_id => qty ]
	 */
	public function resolve_course_quantities( \WC_Order $order ): array {
		$result = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$qty = (int) $item->get_quantity();
			$this->accumulate_item_courses( $item->get_variation_id() ?: $item->get_product_id(), $qty, $result );
		}
		return $result;
	}

	/**
	 * 將單一商品（課程 / 綁課）的課程數量累計到 $result
	 *
	 * @param int             $product_id 商品 ID
	 * @param int             $qty 數量
	 * @param array<int, int> $result 累計結果（傳參考）
	 * @return void
	 */
	private function accumulate_item_courses( int $product_id, int $qty, array &$result ): void {
		if ( ! \wc_get_product( $product_id ) ) {
			return;
		}

		// 課程商品本身
		if ( CourseUtils::is_course_product( $product_id ) ) {
			$result[ $product_id ] = ( $result[ $product_id ] ?? 0 ) + $qty;
		}

		// 綁課商品：各綁定課程 +qty
		$course_ids = BindCoursesData::instance( $product_id )->get_course_ids();
		foreach ( $course_ids as $course_id ) {
			$course_id = (int) $course_id;
			if ( $course_id <= 0 ) {
				continue;
			}
			$result[ $course_id ] = ( $result[ $course_id ] ?? 0 ) + $qty;
		}
	}

	/**
	 * 計入訂單的 total_sales（進入觸發狀態時）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function increment( \WC_Order $order ): void {
		// 冪等 guard：已計入則不重複 +（Q2=B）
		if ( 'yes' === $order->get_meta( self::META_COUNTED ) ) {
			return;
		}

		// 訂閱：續訂 / resubscribe / switch 不計入（只認首付 / 一般訂單，Q3=A）
		if ( $this->is_subscription_renewal( $order ) ) {
			return;
		}

		foreach ( $this->resolve_course_quantities( $order ) as $course_id => $qty ) {
			$this->add_sales( $course_id, $qty );
		}

		$order->update_meta_data( self::META_COUNTED, 'yes' );
		$order->save();
	}

	/**
	 * 扣減訂單的 total_sales（離開觸發狀態 / 退費時）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<int, int>|null $course_qty 指定扣減的 [course_id => qty]（部分退費用）；null 則整單扣減
	 * @param bool                 $reset_flag 是否將旗標重設為 'no'（整單離開 = true，部分退費 = false）
	 * @return void
	 */
	public function decrement( \WC_Order $order, ?array $course_qty = null, bool $reset_flag = true ): void {
		// 未計入則不誤扣
		if ( 'yes' !== $order->get_meta( self::META_COUNTED ) ) {
			return;
		}

		// 訂閱一律不扣減（曾成立即算成立過，Q3=A）
		if ( $this->is_subscription_related( $order ) ) {
			return;
		}

		$map = $course_qty ?? $this->resolve_course_quantities( $order );
		foreach ( $map as $course_id => $qty ) {
			$this->add_sales( $course_id, - $qty );
		}

		if ( $reset_flag ) {
			$order->update_meta_data( self::META_COUNTED, 'no' );
			$order->save();
		}
	}

	/**
	 * 增減課程商品的 total_sales（floor 0，不為負）
	 *
	 * @param int $course_id 課程商品 ID
	 * @param int $delta 增減量（可為負）
	 * @return void
	 */
	private function add_sales( int $course_id, int $delta ): void {
		$product = \wc_get_product( $course_id );
		if ( ! $product ) {
			return;
		}
		$new_total = max( 0, (int) $product->get_total_sales() + $delta );
		$product->set_total_sales( $new_total );
		$product->save();
	}

	// ========== Hooks ==========

	/**
	 * 訂單狀態變更時同步 total_sales
	 *
	 * @param int       $order_id 訂單 ID
	 * @param string    $from 變更前狀態（不含 wc- 前綴）
	 * @param string    $to 變更後狀態（不含 wc- 前綴）
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function handle_order_status_changed( int $order_id, string $from, string $to, \WC_Order $order ): void {
		$trigger = $this->get_trigger();

		// 進入觸發狀態 → 計入
		if ( $to === $trigger ) {
			$this->increment( $order );
			return;
		}

		// 離開觸發狀態 → 扣減（排除 refunded，退費統一走 woocommerce_order_refunded）
		if ( $from === $trigger && in_array( $to, self::DECREMENT_STATUSES, true ) ) {
			$this->decrement( $order );
		}
	}

	/**
	 * 退費（全額 / 部分）時扣減對應課程的 total_sales
	 *
	 * 統一由本 hook 處理退費扣減；全額退費時 WC 雖也會把狀態轉為 refunded，
	 * 但 handle_order_status_changed 已排除 refunded，避免雙重扣減。
	 *
	 * @param int $order_id 訂單 ID
	 * @param int $refund_id 退費單 ID
	 * @return void
	 */
	public function handle_order_refunded( int $order_id, int $refund_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}

		// 未計入則不扣
		if ( 'yes' !== $order->get_meta( self::META_COUNTED ) ) {
			return;
		}

		$refund = \wc_get_order( $refund_id );
		if ( ! ( $refund instanceof \WC_Order_Refund ) ) {
			return;
		}

		// 計算被退的各商品數量
		$refunded_qty = [];
		foreach ( $refund->get_items() as $refund_item ) {
			if ( ! ( $refund_item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$product_id = $refund_item->get_variation_id() ?: $refund_item->get_product_id();
			// 退費的 qty 為負數，取絕對值
			$qty = abs( (int) $refund_item->get_quantity() );
			if ( $qty <= 0 ) {
				continue;
			}
			$refunded_qty[ $product_id ] = ( $refunded_qty[ $product_id ] ?? 0 ) + $qty;
		}

		// 將被退商品換算成課程數量
		$course_qty = [];
		foreach ( $refunded_qty as $product_id => $qty ) {
			$this->accumulate_item_courses( (int) $product_id, $qty, $course_qty );
		}

		// 判斷是否整單已全退（退費後剩餘可退金額 <= 0）
		$fully_refunded = (float) $order->get_remaining_refund_amount() <= 0;

		if ( $course_qty ) {
			// 部分退費僅扣被退課程，且旗標維持 yes；全退則重設旗標為 no
			$this->decrement( $order, $course_qty, $fully_refunded );
		} elseif ( $fully_refunded ) {
			// refund 無 line_items 但為全額退費（例：直接退金額）→ 整單扣減
			$this->decrement( $order );
		}
	}

	// ========== 訂閱判定 ==========

	/**
	 * 此訂單是否為訂閱的續訂 / resubscribe / switch（increment 應跳過）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private function is_subscription_renewal( \WC_Order $order ): bool {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}
		return (bool) \wcs_order_contains_subscription( $order, [ 'renewal', 'resubscribe', 'switch' ] );
	}

	/**
	 * 此訂單是否與訂閱相關（decrement 應跳過，訂閱曾成立即算）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private function is_subscription_related( \WC_Order $order ): bool {
		if ( ! class_exists( 'WC_Subscription' ) || ! function_exists( 'wcs_order_contains_subscription' ) ) {
			return false;
		}
		return (bool) \wcs_order_contains_subscription( $order, [ 'parent', 'renewal', 'resubscribe', 'switch' ] );
	}
}
