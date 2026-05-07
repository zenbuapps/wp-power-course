<?php
/**
 * MCP Tool：bundle_delete_products — 清空或移除銷售方案內的商品
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class BundleDeleteProductsTool
 * 清空銷售方案內的所有 bundled IDs（預設），或僅移除指定 product_ids。
 *
 * 向下相容：執行時自動清除已廢棄的 `exclude_main_course` meta（Issue #185）。
 */
final class BundleDeleteProductsTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'bundle_delete_products';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '移除銷售方案商品', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'清空銷售方案內的所有 bundled IDs（未提供 product_ids 時），或僅移除指定的商品 IDs。同時會清除已廢棄的 exclude_main_course meta。',
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
				'id'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '銷售方案商品 ID', 'power-course' ),
				],
				'product_ids' => [
					'type'        => 'array',
					'items'       => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'description' => __( '欲移除的商品 ID 列表；若未提供或為空，則清空所有 bundled IDs。', 'power-course' ),
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
				'cleared'       => [ 'type' => 'boolean' ],
				'removed_ids'   => [
					'type' => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'product_ids'   => [
					'type' => 'array',
					'items' => [ 'type' => 'integer' ],
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

		$logger  = new ActivityLogger();
		$user_id = \get_current_user_id();

		/** @var array<int> $target_ids */
		$target_ids = [];
		if ( isset( $args['product_ids'] ) && is_array( $args['product_ids'] ) ) {
			$target_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'intval', $args['product_ids'] ),
						static fn( int $v ): bool => $v > 0
					)
				)
			);
		}

		// 先紀錄操作前狀態
		$previous_ids = array_map( 'intval', $helper->get_product_ids() );

		try {
			if ( empty( $target_ids ) ) {
				// 清空所有 bundled IDs（同時移除 quantities 與 exclude_main_course）
				$helper->clear_bundled_ids();
				$removed_ids = $previous_ids;
				$cleared     = true;
			} else {
				// 僅移除指定的 IDs
				foreach ( $target_ids as $pid ) {
					$helper->delete_bundled_ids( $pid );
				}
				$removed_ids = $target_ids;
				$cleared     = false;

				// 向下相容：同時清除 exclude_main_course meta
				\delete_post_meta( $id, 'exclude_main_course' );
			}
		} catch ( \Throwable $e ) {
			$logger->log( $this->get_name(), $user_id, $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_bundle_delete_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$helper_after = Helper::instance( $id );
		$response     = [
			'id'          => $id,
			'cleared'     => $cleared,
			'removed_ids' => $removed_ids,
			'product_ids' => null !== $helper_after ? array_map( 'intval', $helper_after->get_product_ids() ) : [],
		];

		$logger->log( $this->get_name(), $user_id, $args, $response, true );

		return $response;
	}
}
