<?php
/**
 * 重新計算課程已售出數量（total_sales）服務
 *
 * Issue #228 歷史資料修復：過去 total_sales 只進不出已累積虛高數據，
 * 提供一次性重新計算能力（手動 REST 按鈕 + 升級自動跑一次）。
 *
 * 重新計算邏輯與即時同步（TotalSalesSync）一致：掃描所有有效訂單
 * （達 course_access_trigger 狀態），依綁定課程與份數（含 Bundle 已展開的
 * order item）重建各課程 total_sales。為冪等操作（先歸零再累加，非累加式）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class RecalculateTotalSales
 */
final class RecalculateTotalSales {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Action Scheduler hook 名稱 */
	const AS_HOOK = 'pc_recalculate_total_sales';

	/** @var string Action Scheduler group 名稱 */
	const AS_GROUP = 'power_course_recalculate_total_sales';

	/** @var int 每批處理的訂單數，避免逾時 */
	const BATCH_SIZE = 50;

	/** Constructor */
	public function __construct() {
		\add_action( self::AS_HOOK, [ $this, 'process_batch' ], 10, 1 );
	}

	/**
	 * 排程重新計算
	 *
	 * 步驟一：歸零所有課程（_is_course=yes）的 total_sales，並清除所有訂單的計入旗標。
	 * 步驟二：排入 Action Scheduler 分批掃描有效訂單。
	 *
	 * @return array{scheduled: bool, course_count: int}
	 */
	public function schedule(): array {
		$course_ids = $this->get_course_ids();

		// 步驟一：歸零所有課程 total_sales
		foreach ( $course_ids as $course_id ) {
			$product = \wc_get_product( $course_id );
			if ( ! $product ) {
				continue;
			}
			$product->set_total_sales( 0 );
			$product->save();
		}

		// 清除所有訂單的計入旗標（冪等：避免 increment 旗標 guard 阻擋後續重算）
		$this->clear_counted_flags();

		// 步驟二：排入第一批背景任務
		\as_enqueue_async_action( self::AS_HOOK, [ 'offset' => 0 ], self::AS_GROUP );

		return [
			'scheduled'    => true,
			'course_count' => count( $course_ids ),
		];
	}

	/**
	 * 處理一批有效訂單，累加各課程 total_sales
	 *
	 * @param int $offset 偏移量
	 * @return void
	 */
	public function process_batch( int $offset = 0 ): void {
		$orders = $this->get_valid_orders( $offset, self::BATCH_SIZE );

		$sync = TotalSalesSync::instance();
		foreach ( $orders as $order ) {
			if ( ! ( $order instanceof \WC_Order ) ) {
				continue;
			}

			// 訂閱：僅首付 / 一般訂單計入，續訂 / resubscribe / switch 跳過（與 increment 同規則）
			if ( $this->is_subscription_renewal( $order ) ) {
				continue;
			}

			foreach ( $sync->resolve_course_quantities( $order ) as $course_id => $qty ) {
				$this->add_sales( $course_id, $qty );
			}

			$order->update_meta_data( TotalSalesSync::META_COUNTED, 'yes' );
			$order->save();
		}

		// 仍有下一批 → 再排程
		if ( count( $orders ) >= self::BATCH_SIZE ) {
			\as_enqueue_async_action( self::AS_HOOK, [ 'offset' => $offset + self::BATCH_SIZE ], self::AS_GROUP );
		}
	}

	/**
	 * 取得所有課程商品 ID（_is_course = yes）
	 *
	 * @return array<int>
	 */
	private function get_course_ids(): array {
		$ids = \get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => '_is_course',
						'value'   => [ 'yes', 'on' ],
						'compare' => 'IN',
					],
				],
			]
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * 取得一批有效訂單
	 *
	 * 有效 = 狀態達 course_access_trigger（未被 cancelled / refunded / failed）。
	 *
	 * @param int $offset 偏移量
	 * @param int $limit  每批數量
	 * @return array<\WC_Order>
	 */
	private function get_valid_orders( int $offset, int $limit ): array {
		$trigger = Settings::instance()->course_access_trigger;
		$orders  = \wc_get_orders(
			[
				'status'  => [ 'wc-' . $trigger ],
				'limit'   => $limit,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'type'    => 'shop_order',
			]
		);
		return is_array( $orders ) ? $orders : [];
	}

	/**
	 * 清除所有訂單的計入旗標
	 *
	 * @return void
	 */
	private function clear_counted_flags(): void {
		global $wpdb;
		// 同時處理傳統 post meta 與 HPOS 的 order meta
		$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => TotalSalesSync::META_COUNTED ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$wpdb->delete( $hpos_table, [ 'meta_key' => TotalSalesSync::META_COUNTED ] ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * 增減課程商品的 total_sales（floor 0，不為負）
	 *
	 * @param int $course_id 課程商品 ID
	 * @param int $delta 增減量
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

	/**
	 * 此訂單是否為訂閱的續訂 / resubscribe / switch（重算應跳過）
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
}
