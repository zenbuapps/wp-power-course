<?php
/**
 * MCP Report Revenue Stats Tool
 *
 * 查詢指定日期區間的營收統計（含課程特有的學員數 / 完成章節數擴展欄位）。
 * 為避免 AI 無限制查詢炸掉 DB，強制要求 date_from / date_to 且區間不得超過 365 天。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Report;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Report\Service\Stats;

/**
 * Class ReportRevenueStatsTool
 *
 * 對應 MCP ability：power-course/report_revenue_stats
 */
final class ReportRevenueStatsTool extends AbstractTool {

	/** 日期區間最大天數（避免 heavy SQL 炸 DB） */
	private const MAX_DATE_RANGE_DAYS = 365;

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'report_revenue_stats';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '營收統計報表', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '查詢指定日期區間內的課程營收統計（含訂單數、退款、學員數、完成章節數）。區間上限為 365 天。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'date_from'        => [
					'type'        => 'string',
					'format'      => 'date',
					'description' => \__( '起始日期（YYYY-MM-DD）。', 'power-course' ),
				],
				'date_to'          => [
					'type'        => 'string',
					'format'      => 'date',
					'description' => \__( '結束日期（YYYY-MM-DD），與 date_from 間距不得超過 365 天。', 'power-course' ),
				],
				'interval'         => [
					'type'        => 'string',
					'enum'        => [ 'day', 'week', 'month', 'quarter', 'year' ],
					'description' => \__( '統計間隔；預設 day。', 'power-course' ),
				],
				'product_includes' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => \__( '指定要包含的課程（商品）ID 陣列；省略則為全部課程。', 'power-course' ),
				],
			],
			'required'   => [ 'date_from', 'date_to' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'totals'    => [
					'type'        => 'object',
					'description' => \__( '整段區間的統計合計。', 'power-course' ),
				],
				'intervals' => [
					'type'        => 'array',
					'description' => \__( '各區間的細項統計。', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'view_woocommerce_reports';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'report';
	}

	/**
	 * 執行營收統計查詢
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return mixed|\WP_Error 統計資料（object 或 array）或錯誤
	 */
	protected function execute( array $args ): mixed {
		$date_from = isset( $args['date_from'] ) && \is_string( $args['date_from'] )
		? \sanitize_text_field( $args['date_from'] )
		: '';
		$date_to   = isset( $args['date_to'] ) && \is_string( $args['date_to'] )
		? \sanitize_text_field( $args['date_to'] )
		: '';

		if ( '' === $date_from || '' === $date_to ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'date_from 與 date_to 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$date_range_error = self::validate_date_range( $date_from, $date_to );
		if ( $date_range_error instanceof \WP_Error ) {
			return $date_range_error;
		}

		$params = [
			// WC Revenue Query 使用 after / before 作為 ISO 時間；轉為完整的日期時間範圍
			'after'    => $date_from . 'T00:00:00',
			'before'   => $date_to . 'T23:59:59',
			'interval' => isset( $args['interval'] ) && \is_string( $args['interval'] )
				? \sanitize_text_field( $args['interval'] )
				: 'day',
		];

		if ( ! empty( $args['product_includes'] ) && \is_array( $args['product_includes'] ) ) {
			$params['product_includes'] = array_values(
				array_filter(
					array_map( 'intval', $args['product_includes'] ),
					fn( $id ) => $id > 0
				)
			);
		}

		try {
			$data = Stats::revenue( $params );
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);
			return new \WP_Error(
				'mcp_report_revenue_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'date_from' => $date_from,
				'date_to' => $date_to,
			],
			true
		);

		return $data;
	}

	/**
	 * 驗證日期區間是否合法（格式正確且不超過 MAX_DATE_RANGE_DAYS）
	 *
	 * @param string $date_from 起始日期
	 * @param string $date_to   結束日期
	 * @return \WP_Error|null 驗證失敗時回傳 WP_Error，通過時回傳 null
	 */
	public static function validate_date_range( string $date_from, string $date_to ): ?\WP_Error {
		$from_ts = strtotime( $date_from );
		$to_ts   = strtotime( $date_to );

		if ( false === $from_ts || false === $to_ts ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'date_from / date_to 日期格式不正確（應為 YYYY-MM-DD）。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( $to_ts < $from_ts ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'date_to 不得早於 date_from。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$days_diff = (int) floor( ( $to_ts - $from_ts ) / DAY_IN_SECONDS );
		if ( $days_diff > self::MAX_DATE_RANGE_DAYS ) {
			return new \WP_Error(
				'mcp_date_range_too_large',
				\sprintf(
					/* translators: %d: max days */
					\__( '日期區間不得超過 %d 天。', 'power-course' ),
					self::MAX_DATE_RANGE_DAYS
				),
				[ 'status' => 422 ]
			);
		}

		return null;
	}
}
