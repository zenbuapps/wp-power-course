<?php
/**
 * MCP Tool：bundle_get — 取得銷售方案詳情
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\BundleProduct\Service\Query;

/**
 * Class BundleGetTool
 * 取得指定銷售方案的課程、商品與數量配置。
 */
final class BundleGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'bundle_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '取得銷售方案', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'取得單一銷售方案的完整資訊，包含綁定課程、包含的商品 ID 列表（含向下相容）、以及每個商品的數量配置。',
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
					'description' => __( '銷售方案商品 ID', 'power-course' ),
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
				'id'                       => [ 'type' => 'integer' ],
				'name'                     => [ 'type' => 'string' ],
				'status'                   => [ 'type' => 'string' ],
				'price'                    => [ 'type' => 'string' ],
				'regular_price'            => [ 'type' => 'string' ],
				'bundle_type'              => [ 'type' => 'string' ],
				'link_course_id'           => [ 'type' => 'integer' ],
				'product_ids'              => [
					'type' => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'product_ids_with_compat'  => [
					'type' => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'product_quantities'       => [ 'type' => 'object' ],
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
		return 'bundle';
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

		$product = \wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'mcp_bundle_not_found',
				__( '找不到指定的商品。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$helper = Helper::instance( $product );
		if ( null === $helper || ! $helper->is_bundle_product ) {
			return new \WP_Error(
				'mcp_bundle_invalid',
				__( '指定的商品不是銷售方案（bundle_type 為空）。', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		return Query::format( $product, $helper );
	}
}
