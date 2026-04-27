<?php
/**
 * Revenue API
 */

declare(strict_types=1);

namespace J7\PowerCourse\Api\Reports\Revenue;

use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\General;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Report\Service\Stats;
use J7\PowerCourse\Plugin;

/**
 * Revenue Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected $apis = [
		[
			'endpoint'            => 'reports/revenue/stats',
			'method'              => 'get',
			'permission_callback' => null,
		],
	];


	/**
	 * 報表欄位
	 *
	 * @var array<string, string>
	 */
	protected $extra_report_columns = [];


	/**
	 * 報表欄位型別 intval|floatval
	 *
	 * @var array<string, string>
	 */
	protected $extra_report_column_types = [
		// 取得退款訂單數量
		'refunded_orders_count'     => 'intval',
		// 取得不包含退款的訂單數量
		'non_refunded_orders_count' => 'intval',
	];

	/** Constructor */
	public function __construct() {
		parent::__construct();

		// 檢查 API 請求是否包含 "/wp-json/power-course" 字串
		if (strpos( (string) $_SERVER['REQUEST_URI'], '/wp-json/power-course') === false) { // phpcs:ignore
			return;
		}

		global $wpdb;
		$this->extra_report_columns = [
			// 取得退款訂單數量
			'refunded_orders_count'     => "SUM( CASE WHEN {$wpdb->prefix}wc_order_stats.parent_id = 0 AND {$wpdb->prefix}wc_order_stats.status = \"wc-refunded\" THEN 1 ELSE 0 END ) as refunded_orders_count",
			// 取得不包含退款的訂單數量
			'non_refunded_orders_count' => "SUM( CASE WHEN {$wpdb->prefix}wc_order_stats.parent_id = 0 AND {$wpdb->prefix}wc_order_stats.status != \"wc-refunded\" THEN 1 ELSE 0 END ) as non_refunded_orders_count",
		];

		\add_filter( 'woocommerce_admin_report_columns', [ $this, 'add_report_columns' ], 100, 3 );
		\add_filter( 'woocommerce_rest_reports_column_types', [ $this, 'add_report_column_types' ], 100, 2 );
		\add_filter( 'woocommerce_analytics_report_should_use_cache', [ $this, 'disable_cache_in_local' ], 100, 2 );
		\add_filter('power_course_reports_revenue_stats', [ $this, 'extend_student_count_stats' ], 100, 2 );
		\add_filter('power_course_reports_revenue_stats', [ $this, 'extend_finished_chapters_count_stats' ], 110, 2 );
	}



	/**
	 * 取得報表收入統計資料 API
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_reports_revenue_stats_callback( $request ) { // phpcs:ignore
		// 從請求中取得查詢參數
		$params = $request->get_query_params();

		// 將業務邏輯委派給 Service 層（MCP tool 亦復用此方法）
		$filtered_data = Stats::revenue( $params );

		// 如果沒有找到數據，返回空響應
		if (empty($filtered_data)) {
			return new \WP_REST_Response(
				[
					'code'    => 200,
					'message' => __( 'No data found', 'power-course' ),
					'data'    => null,
				],
				200
			);
		}

		return new \WP_REST_Response(
			$filtered_data,
			200
		);
	}


	/**
	 * 添加報表欄位
	 *
	 * @param array<string, string> $columns 欄位 + 查詢語句
	 * @param string                $context 資料表名稱 orders_stats
	 * @param string                $table_name 資料表名稱 wp_wc_order_stats
	 * @return array<string, string>
	 */
	public function add_report_columns( $columns, $context, $table_name ) {
		return array_merge($columns, $this->extra_report_columns);
	}


	/**
	 * 添加報表欄位類型
	 *
	 * @param array<string, string> $column_types 欄位類型
	 * @param array<string, mixed>  $array 數據
	 * @return array<string, string>
	 */
	public function add_report_column_types( $column_types, $array ) {
		return array_merge($column_types, $this->extra_report_column_types);
	}

	/**
	 * 禁用本地報表的快取
	 *
	 * @param bool   $should_cache 是否快取資料
	 * @param string $cache_key 快取鍵
	 * @return bool
	 */
	public function disable_cache_in_local( $should_cache, $cache_key ) {
		return 'local' !== \wp_get_environment_type();
	}

	/**
	 * 擴展學生數量統計
	 *
	 * @param object                                                                                                                                                                                                                    $data 數據
	 * @param array{before: mixed, after: mixed, interval: mixed, page: int, per_page: int, orderby: mixed, order: mixed, segmentby: mixed, force_cache_refresh: mixed, date_type: mixed, fields: string[], product_includes: string[]} $query_args 查詢參數
	 * @return object
	 */
	public function extend_student_count_stats( object $data, array $query_args ): object { // phpcs:ignore
		global $wpdb;
		$sql = $this->get_course_sql( $query_args );
		/** @var array<int, array{time_interval: string, record_value: string}> $results */
		$results             = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		$total_student_count = array_reduce( $results, fn( $acc, $item ) => $acc + (float) $item['record_value'], 0 );

		/** @var \stdClass $data */
		$totals                = $data->totals;
		$totals->student_count = $total_student_count;
		/** @var array<int, array{interval: string, subtotals: \stdClass}> $intervals */
		$intervals       = $data->intervals;
		$data->intervals = array_map(
				function ( $interval ) use ( $results ) {
					/** @var array{interval: string, subtotals: \stdClass} $interval */
					/** @var array{time_interval: string, record_value: string}|null $target */
					$target                               = General::array_find( $results, fn( $item ) => $item['time_interval'] === $interval['interval'] );
					$interval['subtotals']->student_count = $target ? (float) $target['record_value'] : 0;
					return $interval;
				},
			$intervals
			);
		return $data;
	}

	/**
	 * 擴展完成的章節數量統計
	 *
	 * @param object                                                                                                                                                                                                                    $data 數據
	 * @param array{before: mixed, after: mixed, interval: mixed, page: int, per_page: int, orderby: mixed, order: mixed, segmentby: mixed, force_cache_refresh: mixed, date_type: mixed, fields: string[], product_includes: string[]} $query_args 查詢參數
	 * @return object
	 */
	public function extend_finished_chapters_count_stats( object $data, array $query_args ): object {
		global $wpdb;

		$sql = $this->get_chapter_sql( $query_args );
		/** @var array<int, array{time_interval: string, record_value: string}> $results */
			$results             = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		$total_finished_chapters_count = array_reduce( $results, fn( $acc, $item ) => $acc + (float) $item['record_value'], 0 );

		/** @var \stdClass $data */
		$totals                         = $data->totals;
		$totals->finished_chapters_count = $total_finished_chapters_count;
		/** @var array<int, array{interval: string, subtotals: \stdClass}> $intervals */
		$intervals       = $data->intervals;
		$data->intervals = array_map(
				function ( $interval ) use ( $results ) {
					/** @var array{interval: string, subtotals: \stdClass} $interval */
					/** @var array{time_interval: string, record_value: string}|null $target */
					$target = General::array_find( $results, fn( $item ) => $item['time_interval'] === $interval['interval'] );
					$interval['subtotals']->finished_chapters_count = $target ? (float) $target['record_value'] : 0;
					return $interval;
				},
			$intervals
			);
		return $data;
	}


	/**
	 * 取得學生數量統計的 SQL 語句
	 *
	 * @param array{before: mixed, after: mixed, interval: mixed, page: int, per_page: int, orderby: mixed, order: mixed, segmentby: mixed, force_cache_refresh: mixed, date_type: mixed, fields: string[], product_includes: string[]} $query_args 查詢參數
	 * @return string
	 */
	private function get_course_sql( array $query_args ): string {

		global $wpdb;
		$prefix     = $wpdb->prefix;
		$table_name = $prefix . Plugin::COURSE_TABLE_NAME;

		[
			'before' => $before,
			'after' => $after,
			'interval' => $interval,
		] = $query_args;

		$date_format = $this->get_date_format( (string) $interval );

		$where_clause = '';
		if (!empty($query_args['product_includes'])) {
			$where_clause = 'AND post_id IN (' . implode(',', $query_args['product_includes']) . ')';
		}

		return $wpdb->prepare(
			"SELECT %1\$s as time_interval, COUNT(DISTINCT user_id) as record_value FROM %2\$s WHERE meta_key = 'course_granted_at' %3\$s AND meta_value BETWEEN '%4\$s' AND '%5\$s' GROUP BY time_interval ORDER BY time_interval ASC;",
			$date_format,
			$table_name,
			$where_clause,
			$after,
			$before
		);
	}


	/**
	 * 取得完成的章節數量統計的 SQL 語句
	 *
	 * @param array{before: mixed, after: mixed, interval: mixed, page: int, per_page: int, orderby: mixed, order: mixed, segmentby: mixed, force_cache_refresh: mixed, date_type: mixed, fields: string[], product_includes: string[]} $query_args 查詢參數
	 * @return string
	 */
	private function get_chapter_sql( array $query_args ): string {

		global $wpdb;
		$prefix     = $wpdb->prefix;
		$table_name = $prefix . Plugin::CHAPTER_TABLE_NAME;

		[
			'before' => $before,
			'after' => $after,
			'interval' => $interval,
		] = $query_args;

		$date_format = $this->get_date_format( (string) $interval );

		$chapter_ids_in_specific_course = [];
		if (!empty($query_args['product_includes'])) {
			foreach ($query_args['product_includes'] as $course_id) {
				$chapter_ids_in_single_course   = ChapterUtils::get_flatten_post_ids( (int) $course_id);
				$chapter_ids_in_specific_course = array_merge($chapter_ids_in_specific_course, $chapter_ids_in_single_course);
			}
		}

		$where_clause = '';
		if (!empty($chapter_ids_in_specific_course)) {
			$where_clause = 'AND post_id IN (' . implode(',', $chapter_ids_in_specific_course) . ')';
		}

		return $wpdb->prepare(
			"SELECT %1\$s as time_interval, COUNT(meta_value) as record_value FROM %2\$s WHERE meta_key = 'finished_at' %3\$s AND meta_value BETWEEN '%4\$s' AND '%5\$s' GROUP BY time_interval ORDER BY time_interval ASC;",
			$date_format,
			$table_name,
			$where_clause,
			$after,
			$before
		);
	}

	/**
	 * 取得日期SQL格式
	 *
	 * @param string $interval 間隔
	 * @return string
	 */
	private function get_date_format( string $interval ): string {
		return match ($interval) {
			'day' => 'DATE(meta_value)',
			'week' => 'DATE_FORMAT(meta_value, "%x-%v")',
			'month' => 'DATE_FORMAT(meta_value, "%x-%m")',
			'quarter' => 'CONCAT(YEAR(meta_value), "-", QUARTER(meta_value))',
			'year' => 'DATE_FORMAT(meta_value, "%x")',
			default => 'DATE(meta_value)',
		};
	}
}
