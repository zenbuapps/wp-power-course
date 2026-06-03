<?php
/**
 * Report Stats Service
 *
 * 將 Revenue Api callback 的業務邏輯抽出，供 REST endpoint 與 MCP tool 共用。
 * 同時提供獨立的學員數量統計查詢方法。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Report\Service;

use Automattic\WooCommerce\Admin\API\Reports\Revenue\Query;
use Automattic\WooCommerce\Admin\API\Reports\GenericQuery as ProductQuery;
use J7\PowerCourse\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Stats
 *
 * 報表統計查詢服務。
 */
final class Stats {

	/**
	 * 取得營收統計（含 student_count、finished_chapters_count 擴展欄位）
	 *
	 * 從 `Api\Reports\Revenue\Api::get_reports_revenue_stats_callback` 抽出，
	 * 以便 MCP tool 與 REST callback 共用相同邏輯。
	 *
	 * @param array<string, mixed> $params 查詢參數（來自 REST request 或 MCP args）
	 * @return object|array<mixed> 統計資料（空結果時為空陣列）
	 */
	public static function revenue( array $params ): object|array {
		// 設定預設的分頁參數
		$params['page']     = 1;
		$params['per_page'] = 10000; // 設定一個大數值以一次性取得所有記錄

		// 準備查詢參數，模仿 WooCommerce 的收入統計控制器
		$default_args = [
			'before'              => $params['before'] ?? null,
			'after'               => $params['after'] ?? null,
			'interval'            => $params['interval'] ?? 'day',
			'page'                => $params['page'],
			'per_page'            => $params['per_page'],
			'orderby'             => $params['orderby'] ?? null,
			'order'               => $params['order'] ?? null,
			'segmentby'           => $params['segmentby'] ?? null,
			'force_cache_refresh' => $params['force_cache_refresh'] ?? false,
			'date_type'           => $params['date_type'] ?? null,
			'fields'              => [
				'net_revenue',
				'avg_order_value',
				'orders_count',
				'avg_items_per_order',
				'num_items_sold',
				'coupons',
				'coupons_count',
				'total_customers',
				'total_sales',
				'refunds',
				'shipping',
				'gross_sales',
			],
		];

		$query_args = \wp_parse_args( $params, $default_args );

		// 注入擴展欄位（refunded_orders_count / non_refunded_orders_count）
		$extra_report_keys = [ 'refunded_orders_count', 'non_refunded_orders_count' ];
		foreach ( $extra_report_keys as $extra_report_key ) {
			$query_args['fields'][] = $extra_report_key;
		}

		// 移除空值
		$query_args = array_filter( $query_args );

		// 使用 WooCommerce 的收入查詢來獲取數據
		// 僅 product 分支（指定 product_includes）走 products-stats，計算單一課程的「已售出數量」
		$is_product_query = ! empty( $query_args['product_includes'] );
		if ( $is_product_query ) {
			$query_args['context']  = 'view';
			$query_args['fields'][] = 'items_sold';
			$query                  = new ProductQuery( $query_args, 'products-stats' );
		} else {
			$query = new Query( $query_args );
		}

		// 只在 products-stats 查詢期間，臨時把 refunded 併入 analytics 排除狀態，
		// 讓「已退費」訂單比照「已取消」從已售出數量中剔除。
		// get_data() 後立即移除 filter，避免影響全域 Analytics 營收 / refunds 報表。
		if ( $is_product_query ) {
			\add_filter( 'woocommerce_analytics_excluded_order_statuses', [ self::class, 'append_refunded_to_excluded' ] );
		}

		/** @var object|array<mixed> $data */
		$data = $query->get_data();

		if ( $is_product_query ) {
			\remove_filter( 'woocommerce_analytics_excluded_order_statuses', [ self::class, 'append_refunded_to_excluded' ] );
		}

		$filtered_data = \apply_filters( 'power_course_reports_revenue_stats', $data, $query_args );

		return $filtered_data;
	}

	/**
	 * 把 refunded 併入 analytics 排除狀態
	 *
	 * 僅供 products-stats 查詢期間掛載，讓「已退費」(wc-refunded) 訂單比照「已取消」(wc-cancelled)
	 * 從已售出數量（items_sold）中剔除。WC 預設排除清單不含 refunded，故手動改單為「已退費」時
	 * 已售出數量原本不會下降——掛上本 filter 後即會下降。
	 *
	 * 注意：filter `woocommerce_analytics_excluded_order_statuses` 收到的狀態值是**不帶 `wc-` 前綴**
	 * 的原始值（WC 之後才 normalize 成 `wc-refunded`），所以 push `'refunded'` 即可。
	 *
	 * @param array<string> $statuses 既有排除狀態（不含 wc- 前綴）
	 * @return array<string>
	 */
	public static function append_refunded_to_excluded( array $statuses ): array {
		$statuses[] = 'refunded'; // 不加 wc- 前綴，WC 後續會 normalize
		return array_values( array_unique( $statuses ) );
	}

	/**
	 * 取得學員數量統計（以日期區間為條件）
	 *
	 * 查詢 pc_avl_coursemeta 表中 `course_granted_at` 記錄，按日期分組計算每段期間新增學員數。
	 *
	 * @param array{after?: string, before?: string, interval?: string, product_includes?: array<int|string>} $args 查詢參數
	 * @return array{total: int, intervals: array<int, array{time_interval: string, count: int}>}
	 */
	public static function get_student_count_stats( array $args ): array {
		global $wpdb;

		$after    = isset( $args['after'] ) ? (string) $args['after'] : '';
		$before   = isset( $args['before'] ) ? (string) $args['before'] : '';
		$interval = isset( $args['interval'] ) ? (string) $args['interval'] : 'day';

		$table_name  = $wpdb->prefix . Plugin::COURSE_TABLE_NAME;
		$date_format = self::get_date_format( $interval );

		// product_includes 白名單（只接受整數 ID）
		$where_clause = '';
		if ( ! empty( $args['product_includes'] ) && \is_array( $args['product_includes'] ) ) {
			$sanitized_ids = array_filter( array_map( 'intval', $args['product_includes'] ), fn( $id ) => $id > 0 );
			if ( ! empty( $sanitized_ids ) ) {
				$where_clause = 'AND post_id IN (' . implode( ',', $sanitized_ids ) . ')';
			}
		}

		$sql = $wpdb->prepare(
			"SELECT %1\$s as time_interval, COUNT(DISTINCT user_id) as record_value FROM %2\$s WHERE meta_key = 'course_granted_at' %3\$s AND meta_value BETWEEN '%4\$s' AND '%5\$s' GROUP BY time_interval ORDER BY time_interval ASC;",
			$date_format,
			$table_name,
			$where_clause,
			$after,
			$before
		);

		/** @var array<int, array{time_interval: string, record_value: string}> $results */
		$results = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB

		$total     = 0;
		$intervals = [];
		foreach ( (array) $results as $row ) {
			$count      = (int) $row['record_value'];
			$total     += $count;
			$intervals[] = [
				'time_interval' => (string) $row['time_interval'],
				'count'         => $count,
			];
		}

		return [
			'total'     => $total,
			'intervals' => $intervals,
		];
	}

	/**
	 * 取得日期 SQL 格式
	 *
	 * @param string $interval 間隔
	 * @return string
	 */
	private static function get_date_format( string $interval ): string {
		return match ( $interval ) {
			'day'     => 'DATE(meta_value)',
			'week'    => 'DATE_FORMAT(meta_value, "%x-%v")',
			'month'   => 'DATE_FORMAT(meta_value, "%x-%m")',
			'quarter' => 'CONCAT(YEAR(meta_value), "-", QUARTER(meta_value))',
			'year'    => 'DATE_FORMAT(meta_value, "%x")',
			default   => 'DATE(meta_value)',
		};
	}
}
