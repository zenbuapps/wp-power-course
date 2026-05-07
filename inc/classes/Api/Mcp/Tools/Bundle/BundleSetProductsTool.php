<?php
/**
 * MCP Tool：bundle_set_products — 原子設定銷售方案包含的商品與數量
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class BundleSetProductsTool
 * 原子操作：同時寫入 `pbp_product_ids` 與 `pbp_product_quantities`，任一失敗即還原。
 *
 * 向下相容：儲存時自動清除已廢棄的 `exclude_main_course` meta（Issue #185）。
 */
final class BundleSetProductsTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'bundle_set_products';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '設定銷售方案商品', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'設定銷售方案包含的商品 IDs 與每個商品的數量（原子操作）。若數量寫入失敗，會還原商品 IDs 至操作前狀態，避免出現資料不一致。',
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
					'description' => __( '方案包含的商品 ID 列表', 'power-course' ),
				],
				'quantities'  => [
					'type'                 => 'object',
					'description'          => __( '每個商品的數量（key 為 product_id, value 為 1~999 整數）', 'power-course' ),
					'additionalProperties' => [
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 999,
					],
				],
			],
			'required'   => [ 'id', 'product_ids' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'                 => [ 'type' => 'integer' ],
				'product_ids'        => [
					'type' => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'product_quantities' => [ 'type' => 'object' ],
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
	 * 執行業務邏輯（原子操作）
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

		if ( ! isset( $args['product_ids'] ) || ! is_array( $args['product_ids'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'product_ids 為必填陣列。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		/** @var array<int> $product_ids */
		$product_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $args['product_ids'] ),
					static fn( int $v ): bool => $v > 0
				)
			)
		);

		/** @var array<string, mixed> $raw_quantities */
		$raw_quantities = isset( $args['quantities'] ) && is_array( $args['quantities'] ) ? $args['quantities'] : [];
		$quantities     = [];
		foreach ( $raw_quantities as $pid => $qty ) {
			$int_pid = (int) $pid;
			$int_qty = (int) $qty;
			if ( $int_pid <= 0 ) {
				continue;
			}
			// Schema 保證範圍，但仍 enforce 1~999
			if ( $int_qty < 1 || $int_qty > 999 ) {
				return new \WP_Error(
					'mcp_invalid_input',
					sprintf(
						/* translators: 1: product id, 2: qty */
						__( '商品 %1$d 的數量 %2$d 超出範圍（1~999）。', 'power-course' ),
						$int_pid,
						$int_qty
					),
					[ 'status' => 422 ]
				);
			}
			$quantities[ (string) $int_pid ] = $int_qty;
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

		$logger = new ActivityLogger();
		$user_id = \get_current_user_id();

		// ========== 原子操作：先備份 → 寫 product_ids → 寫 quantities → 失敗則還原 ==========
		$previous_ids        = array_map( 'intval', $helper->get_product_ids() );
		$previous_quantities = $helper->get_product_quantities();

		try {
			$helper->set_bundled_ids( $product_ids );

			// 僅寫入有提供 quantities 時才更新，避免覆蓋；若有提供但為空物件，表示重設為預設
			if ( isset( $args['quantities'] ) ) {
				$helper->set_product_quantities( $quantities );
			}

			// 向下相容：儲存時清除已廢棄的 exclude_main_course meta
			\delete_post_meta( $id, 'exclude_main_course' );
		} catch ( \Throwable $e ) {
			// 還原 product_ids
			try {
				$helper->set_bundled_ids( $previous_ids );
			} catch ( \Throwable $restore_error ) {
				// 還原失敗只能記錄
				$logger->log(
					$this->get_name(),
					$user_id,
					$args,
					sprintf( 'set failed: %s; restore also failed: %s', $e->getMessage(), $restore_error->getMessage() ),
					false
				);

				return new \WP_Error(
					'mcp_bundle_set_failed_critical',
					__( '寫入與還原皆失敗，資料可能處於不一致狀態。', 'power-course' ),
					[ 'status' => 500 ]
				);
			}

			// 還原 quantities（若先前有值）
			try {
				/** @var array<string|int, int> $restore_quantities */
				$restore_quantities = array_map( 'intval', $previous_quantities );
				$helper->set_product_quantities( $restore_quantities );
			} catch ( \Throwable $restore_error ) {
				$logger->log(
					$this->get_name(),
					$user_id,
					$args,
					sprintf( 'set failed: %s; quantities restore failed: %s', $e->getMessage(), $restore_error->getMessage() ),
					false
				);
			}

			$logger->log( $this->get_name(), $user_id, $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_bundle_set_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		// 成功後重新讀取確認
		$helper_after = Helper::instance( $id );
		$response     = [
			'id'                 => $id,
			'product_ids'        => null !== $helper_after ? array_map( 'intval', $helper_after->get_product_ids() ) : $product_ids,
			'product_quantities' => null !== $helper_after ? $helper_after->get_product_quantities() : $quantities,
		];

		$logger->log( $this->get_name(), $user_id, $args, $response, true );

		return $response;
	}
}
