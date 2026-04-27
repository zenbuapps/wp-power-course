<?php
/**
 * MCP Tool：order_get — 取得單一訂單詳情（含相關課程）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Order\Service\Query;

/**
 * Class OrderGetTool
 * 取得指定訂單的摘要以及訂單中與課程相關的 items meta。
 * 一律透過 `wc_get_order` 讀取，與 HPOS 相容。
 */
final class OrderGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'order_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '取得訂單詳情', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'取得單一 WooCommerce 訂單的基本資訊，以及訂單中與課程相關的 items（含綁定課程 meta）。底層使用 `wc_get_order`，與 HPOS 相容。',
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
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '訂單 ID', 'power-course' ),
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'            => [ 'type' => 'integer' ],
				'status'        => [ 'type' => 'string' ],
				'total'         => [ 'type' => 'string' ],
				'currency'      => [ 'type' => 'string' ],
				'customer_id'   => [ 'type' => 'integer' ],
				'billing_email' => [ 'type' => 'string' ],
				'date_created'  => [ 'type' => 'string' ],
				'date_modified' => [ 'type' => 'string' ],
				'payment_method' => [ 'type' => 'string' ],
				'items_count'   => [ 'type' => 'integer' ],
				'courses'       => [
					'type'  => 'array',
					'items' => [ 'type' => 'object' ],
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
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$id = isset( $args['id'] ) ? (int) $args['id'] : 0;
		if ( $id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'id 為必填且需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		return Query::get_with_courses( $id );
	}
}
