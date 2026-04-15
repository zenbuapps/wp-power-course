<?php
/**
 * Order Query Service — 訂單讀取服務（HPOS 相容）
 *
 * 供 MCP tool（order_list / order_get）使用，所有底層存取皆透過
 * WooCommerce CRUD API（`wc_get_order` / `wc_get_orders`）進行，
 * **禁止**使用 `get_post` / `WP_Query( post_type=shop_order )` / 直接 SQL，
 * 以確保在啟用 HPOS（High-Performance Order Storage）之站台上正確運作。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Order\Service;

use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class Query
 * 訂單查詢服務（HPOS-aware read-only 封裝）
 */
final class Query {

	/**
	 * 列出訂單（HPOS 相容）
	 *
	 * 僅作為 `wc_get_orders( $args )` 的 thin wrapper，附加：
	 * - `paginate => true` 強制回傳分頁結構
	 * - 預設狀態篩選（未指定時為 `any`）
	 * - 輸入參數清洗
	 *
	 * @param array<string, mixed> $args 查詢參數：
	 *                                   - status:       string|array<string> 訂單狀態（預設 'any'）
	 *                                   - page:         int 頁碼（預設 1）
	 *                                   - limit:        int 每頁筆數（預設 10，最大 100）
	 *                                   - orderby:      string 排序欄位（預設 'date'）
	 *                                   - order:        'ASC'|'DESC' 排序方向（預設 'DESC'）
	 *                                   - customer_id:  int 篩選指定客戶 ID
	 *                                   - date_from:    string `Y-m-d` 起始日（含）
	 *                                   - date_to:      string `Y-m-d` 結束日（含）
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 *         訂單清單與分頁資訊
	 */
	public static function list( array $args = [] ): array {
		$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$limit = isset( $args['limit'] ) ? min( 100, max( 1, (int) $args['limit'] ) ) : 10;

		/** @var string|array<string> $status */
		$status = $args['status'] ?? 'any';
		if ( is_array( $status ) ) {
			$status = array_values( array_filter( array_map( 'sanitize_key', $status ) ) );
			if ( empty( $status ) ) {
				$status = 'any';
			}
		} elseif ( is_string( $status ) ) {
			$status = sanitize_key( $status );
		} else {
			$status = 'any';
		}

		$orderby = isset( $args['orderby'] ) && is_string( $args['orderby'] )
		? sanitize_key( $args['orderby'] )
		: 'date';

		$order_dir = 'DESC';
		if ( isset( $args['order'] ) && is_string( $args['order'] ) ) {
			$candidate = strtoupper( sanitize_key( $args['order'] ) );
			if ( in_array( $candidate, [ 'ASC', 'DESC' ], true ) ) {
				$order_dir = $candidate;
			}
		}

		$query_args = [
			'paginate' => true,
			'paged'    => $page,
			'limit'    => $limit,
			'status'   => $status,
			'orderby'  => $orderby,
			'order'    => $order_dir,
			'return'   => 'objects',
		];

		if ( isset( $args['customer_id'] ) && (int) $args['customer_id'] > 0 ) {
			$query_args['customer_id'] = (int) $args['customer_id'];
		}

		// 日期範圍：wc_get_orders 支援 `date_created` 字串語法，如 '>=2024-01-01'、'2024-01-01...2024-12-31'
		$date_from = isset( $args['date_from'] ) && is_string( $args['date_from'] )
		? sanitize_text_field( $args['date_from'] )
		: '';
		$date_to   = isset( $args['date_to'] ) && is_string( $args['date_to'] )
		? sanitize_text_field( $args['date_to'] )
		: '';

		if ( '' !== $date_from && '' !== $date_to ) {
			$query_args['date_created'] = "{$date_from}...{$date_to}";
		} elseif ( '' !== $date_from ) {
			$query_args['date_created'] = ">={$date_from}";
		} elseif ( '' !== $date_to ) {
			$query_args['date_created'] = "<={$date_to}";
		}

		/** @var object{orders: array<int, \WC_Order>, total: int, max_num_pages: int} $results */
		$results     = \wc_get_orders( $query_args );
		$orders      = is_object( $results ) && isset( $results->orders ) ? $results->orders : [];
		$total       = is_object( $results ) && isset( $results->total ) ? (int) $results->total : count( $orders );
		$total_pages = is_object( $results ) && isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;

		$items = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$items[] = self::format_summary( $order );
		}

		return [
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * 取得單一訂單詳情（含相關課程清單）
	 *
	 * 透過 `wc_get_order` 讀取 WC_Order 物件（HPOS 相容），
	 * 並掃描訂單 items 中屬於課程商品的項目，回傳其課程 meta。
	 *
	 * @param int $order_id 訂單 ID
	 * @return array<string, mixed>|\WP_Error 訂單詳情 payload 或錯誤
	 */
	public static function get_with_courses( int $order_id ): array|\WP_Error {
		if ( $order_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( '訂單 ID 需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'mcp_order_not_found',
				__( '找不到指定的訂單。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$courses = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id        = (int) $item->get_product_id();
			$bind_courses_data = $item->get_meta( '_bind_courses_data' ) ?: [];
			$is_course_product = CourseUtils::is_course_product( $product_id );

			if ( ! $is_course_product && empty( $bind_courses_data ) ) {
				continue;
			}

			$courses[] = [
				'order_item_id'     => (int) $item->get_id(),
				'product_id'        => $product_id,
				'product_name'      => (string) $item->get_name(),
				'quantity'          => (int) $item->get_quantity(),
				'is_course_product' => $is_course_product,
				'bind_courses_data' => is_array( $bind_courses_data ) ? $bind_courses_data : [],
			];
		}

		$payload               = self::format_summary( $order );
		$payload['courses']    = $courses;
		$payload['customer_id'] = (int) $order->get_customer_id();

		return $payload;
	}

	/**
	 * 將 WC_Order 物件格式化為摘要陣列
	 *
	 * 一律透過 `WC_Order` getter 方法存取（HPOS 相容），不碰 post meta。
	 *
	 * @param \WC_Order $order 訂單物件
	 * @return array<string, mixed> 訂單摘要
	 */
	public static function format_summary( \WC_Order $order ): array {
		$date_created  = $order->get_date_created();
		$date_modified = $order->get_date_modified();

		return [
			'id'            => (int) $order->get_id(),
			'status'        => (string) $order->get_status(),
			'total'         => (string) $order->get_total(),
			'currency'      => (string) $order->get_currency(),
			'customer_id'   => (int) $order->get_customer_id(),
			'billing_email' => (string) $order->get_billing_email(),
			'date_created'  => null !== $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '',
			'date_modified' => null !== $date_modified ? $date_modified->date( 'Y-m-d H:i:s' ) : '',
			'payment_method' => (string) $order->get_payment_method(),
			'items_count'   => count( $order->get_items() ),
		];
	}
}
