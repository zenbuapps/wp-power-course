<?php
/**
 * MCP Tool：order_list — 列出訂單（HPOS 相容）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Order\Service\Query;

/**
 * Class OrderListTool
 * 列出 WooCommerce 訂單，支援日期、狀態與客戶篩選。
 * 一律透過 `wc_get_orders` 查詢，**禁止**使用 `WP_Query(post_type=shop_order)`。
 */
final class OrderListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'order_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '列出訂單', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'列出 WooCommerce 訂單，支援依狀態、客戶、日期區間篩選。底層使用 `wc_get_orders`，與 HPOS 相容。',
			'power-course'
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'page'        => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( '頁碼（從 1 開始）', 'power-course' ),
				],
				'limit'       => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => __( '每頁筆數，最大 100', 'power-course' ),
				],
				'status'      => [
					'oneOf'       => [
						[ 'type' => 'string' ],
						[
							'type'  => 'array',
							'items' => [ 'type' => 'string' ],
						],
					],
					'description' => __( '訂單狀態，可為字串或陣列（如 "completed" 或 ["pending","processing"]），預設 "any"', 'power-course' ),
				],
				'customer_id' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '篩選指定客戶 ID 的訂單', 'power-course' ),
				],
				'date_from'   => [
					'type'        => 'string',
					'description' => __( '起始日期（含），格式 Y-m-d', 'power-course' ),
				],
				'date_to'     => [
					'type'        => 'string',
					'description' => __( '結束日期（含），格式 Y-m-d', 'power-course' ),
				],
				'orderby'     => [
					'type'        => 'string',
					'default'     => 'date',
					'description' => __( '排序欄位', 'power-course' ),
				],
				'order'       => [
					'type'        => 'string',
					'enum'        => [ 'ASC', 'DESC' ],
					'default'     => 'DESC',
					'description' => __( '排序方向', 'power-course' ),
				],
			],
			'required'   => [],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'items'       => [
					'type'        => 'array',
					'description' => __( '訂單清單', 'power-course' ),
					'items'       => [ 'type' => 'object' ],
				],
				'total'       => [
					'type'        => 'integer',
					'description' => __( '總筆數', 'power-course' ),
				],
				'total_pages' => [
					'type'        => 'integer',
					'description' => __( '總頁數', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'order';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	protected function execute( array $args ): array {
		$query_args = [];

		if ( isset( $args['page'] ) ) {
			$query_args['page'] = max( 1, (int) $args['page'] );
		}
		if ( isset( $args['limit'] ) ) {
			$query_args['limit'] = min( 100, max( 1, (int) $args['limit'] ) );
		}
		if ( isset( $args['status'] ) ) {
			$query_args['status'] = $args['status'];
		}
		if ( isset( $args['customer_id'] ) ) {
			$query_args['customer_id'] = max( 0, (int) $args['customer_id'] );
		}
		if ( isset( $args['date_from'] ) && is_string( $args['date_from'] ) ) {
			$query_args['date_from'] = sanitize_text_field( $args['date_from'] );
		}
		if ( isset( $args['date_to'] ) && is_string( $args['date_to'] ) ) {
			$query_args['date_to'] = sanitize_text_field( $args['date_to'] );
		}
		if ( isset( $args['orderby'] ) && is_string( $args['orderby'] ) ) {
			$query_args['orderby'] = sanitize_key( $args['orderby'] );
		}
		if ( isset( $args['order'] ) && is_string( $args['order'] ) ) {
			$query_args['order'] = strtoupper( sanitize_key( $args['order'] ) );
		}

		return Query::list( $query_args );
	}
}
