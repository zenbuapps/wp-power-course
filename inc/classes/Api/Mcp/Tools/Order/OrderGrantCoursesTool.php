<?php
/**
 * MCP Tool：order_grant_courses — 手動觸發訂單的課程授權
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Order as OrderResource;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;

/**
 * Class OrderGrantCoursesTool
 * 重新跑一次訂單的「課程授權流程」（`Resources\Order::add_meta_to_avl_course`），
 * 底層使用 `wc_get_order` + WC_Order CRUD，與 HPOS 相容。
 *
 * 用途：
 * - 訂單建立時因故未觸發授權 hook，手動補跑
 * - 透過 AddStudent 內部 dedupe 機制確保 idempotent（重跑不會新增重複 user meta）
 */
final class OrderGrantCoursesTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'order_grant_courses';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '手動觸發訂單課程授權', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'針對指定訂單，先修復缺失的訂單項目與 item meta（Issue #263：重試付款會清空訂單項目），再重跑課程授權流程。**本操作會修改訂單項目，非唯讀**：可能新增方案內含商品的 line item、並補寫缺漏的 item meta（既有 meta 不覆寫）。僅接受處於授權狀態的訂單，已取消／已退款訂單會被拒絕。已授權過的學員不會重複新增 avl_course_ids；到期日（expire_date）會被重新計算並覆寫，計算依據優先取下單當時寫入 order item 的限制快照，缺快照才回退商品當下設定。',
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
				'order_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '訂單 ID', 'power-course' ),
				],
			],
			'required'   => [ 'order_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'order_id'        => [ 'type' => 'integer' ],
				'success'         => [ 'type' => 'boolean' ],
				'granted_count'   => [
					'type'        => 'integer',
					'description' => __( '本次訂單中被處理（授權）的課程數', 'power-course' ),
				],
				'message'         => [ 'type' => 'string' ],
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
		$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
		if ( $order_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'order_id 為必填且需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		// 驗證訂單存在（HPOS 相容）
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'mcp_order_not_found',
				__( '找不到指定的訂單。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$logger  = new ActivityLogger();
		$user_id = \get_current_user_id();

		// 狀態閘門（Issue #263）：add_meta_to_avl_course() 被直接呼叫時**不看訂單狀態**
		// （平常是靠 woocommerce_order_status_{grant_status} hook 才只在特定狀態觸發）。
		// 沒有這道閘門，對 cancelled / refunded / pending 訂單呼叫本工具會直接授課。
		$grantable_statuses = Grant::grant_statuses();
		if ( ! \in_array( $order->get_status(), $grantable_statuses, true ) ) {
			return new \WP_Error(
				'mcp_order_status_not_grantable',
				sprintf(
					/* translators: 1: 訂單目前狀態, 2: 允許授權的狀態清單 */
					__( '訂單狀態為 %1$s，非授權狀態（%2$s），已中止以避免對已取消／已退款訂單授課。', 'power-course' ),
					$order->get_status(),
					implode( ', ', $grantable_statuses )
				),
				[ 'status' => 409 ]
			);
		}

		// Issue #263：resume 訂單缺 item meta 與方案內含商品，先跑一次修復再統計 / 授權，
		// 否則本工具會回報 granted_count = 0 與「訂單內沒有課程商品可授權。」（說謊），
		// 且其呼叫的 add_meta_to_avl_course() 同樣什麼都不會授予。
		// repair_order_items() 對 item **數量**具冪等性，且只補缺漏、不覆寫既有 item meta
		// （fill_missing_only），但仍會新增 line item 並 fire woocommerce_new_order_item。
		try {
			OrderResource::instance()->repair_order_items( $order );
			$order = \wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error(
					'mcp_order_not_found',
					__( '找不到指定的訂單。', 'power-course' ),
					[ 'status' => 404 ]
				);
			}
		} catch ( \Throwable $e ) {
			$logger->log( $this->get_name(), $user_id, $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_order_repair_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		// 統計「可授權課程 item 數量」（授權後回傳給呼叫端作 audit）
		$granted_count = 0;
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id        = (int) $item->get_product_id();
			$bind_courses_data = OrderResource::get_item_bind_courses_data( $item );
			if ( \J7\PowerCourse\Utils\Course::is_course_product( $product_id ) || ! empty( $bind_courses_data ) ) {
				++$granted_count;
			}
		}

		try {
			// 直接呼叫既有 method，不動其 signature
			OrderResource::instance()->add_meta_to_avl_course( $order_id );
		} catch ( \Throwable $e ) {
			$logger->log(
				$this->get_name(),
				$user_id,
				$args,
				$e->getMessage(),
				false
			);

			return new \WP_Error(
				'mcp_order_grant_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$response = [
			'order_id'      => $order_id,
			'success'       => true,
			'granted_count' => $granted_count,
			'message'       => 0 === $granted_count
				? __( '訂單內沒有課程商品可授權。', 'power-course' )
				: sprintf(
					/* translators: %d: granted course count */
					__( '已重跑訂單授權流程，處理課程 item 數：%d。', 'power-course' ),
					$granted_count
				),
		];

		$logger->log( $this->get_name(), $user_id, $args, $response, true );

		return $response;
	}
}
