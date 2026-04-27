<?php
/**
 * OrderListTool 整合測試
 *
 * @group mcp
 * @group order
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\Tools\Order\OrderListTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class OrderListToolTest
 *
 * 所有測試皆以 `wc_get_order` / `wc_create_order` 建立訂單，不碰 wp_posts/wp_postmeta。
 */
class OrderListToolTest extends IntegrationTestCase {

	/**
	 * 建立一筆 WC 訂單（HPOS 相容）
	 *
	 * @param array<string, mixed> $args 訂單屬性覆蓋：
	 *                                   - status:      string 訂單狀態
	 *                                   - customer_id: int 客戶 ID
	 *                                   - date_created: string 建立日期（Y-m-d H:i:s）
	 * @return \WC_Order
	 */
	private function create_wc_order( array $args = [] ): \WC_Order {
		$order = new \WC_Order();
		if ( isset( $args['customer_id'] ) ) {
			$order->set_customer_id( (int) $args['customer_id'] );
		}
		$order->set_currency( 'TWD' );
		$order->set_status( $args['status'] ?? 'pending' );
		if ( isset( $args['date_created'] ) && is_string( $args['date_created'] ) ) {
			$order->set_date_created( $args['date_created'] );
		}
		$order->set_billing_email( $args['billing_email'] ?? 'buyer@example.com' );
		$order->save();

		return $order;
	}

	/**
	 * happy：管理員能列出訂單
	 *
	 * @group happy
	 */
	public function test_admin_can_list_orders(): void {
		$this->create_admin_user();
		$this->create_wc_order( [ 'status' => 'completed' ] );
		$this->create_wc_order( [ 'status' => 'pending' ] );

		$tool   = new OrderListTool();
		$result = $tool->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new OrderListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 篩選：status=completed 只回傳 completed 訂單
	 *
	 * @group happy
	 */
	public function test_filter_by_status_completed(): void {
		$this->create_admin_user();
		$this->create_wc_order( [ 'status' => 'completed' ] );
		$this->create_wc_order( [ 'status' => 'completed' ] );
		$this->create_wc_order( [ 'status' => 'pending' ] );

		$tool   = new OrderListTool();
		$result = $tool->run( [ 'status' => 'completed' ] );

		$this->assertIsArray( $result );
		$this->assertSame( 2, $result['total'] );
		foreach ( $result['items'] as $item ) {
			$this->assertSame( 'completed', $item['status'] );
		}
	}

	/**
	 * 篩選：date_from / date_to 限定日期範圍
	 *
	 * @group happy
	 */
	public function test_filter_by_date_range(): void {
		$this->create_admin_user();

		// 建立不同日期訂單
		$this->create_wc_order(
			[
				'status'       => 'completed',
				'date_created' => '2024-01-15 10:00:00',
			]
		);
		$this->create_wc_order(
			[
				'status'       => 'completed',
				'date_created' => '2024-06-15 10:00:00',
			]
		);
		$this->create_wc_order(
			[
				'status'       => 'completed',
				'date_created' => '2024-12-15 10:00:00',
			]
		);

		$tool = new OrderListTool();

		// date_from
		$result_from = $tool->run( [ 'date_from' => '2024-06-01' ] );
		$this->assertIsArray( $result_from );
		$this->assertGreaterThanOrEqual( 2, $result_from['total'] );

		// date_to
		$result_to = $tool->run( [ 'date_to' => '2024-03-01' ] );
		$this->assertIsArray( $result_to );
		$this->assertGreaterThanOrEqual( 1, $result_to['total'] );

		// date_from + date_to 組合
		$result_range = $tool->run(
			[
				'date_from' => '2024-05-01',
				'date_to'   => '2024-07-31',
			]
		);
		$this->assertIsArray( $result_range );
		$this->assertGreaterThanOrEqual( 1, $result_range['total'] );
	}

	/**
	 * 篩選：customer_id 只回傳該客戶的訂單
	 *
	 * @group happy
	 */
	public function test_filter_by_customer_id(): void {
		$admin_id = $this->create_admin_user();
		$buyer_id = $this->factory()->user->create( [ 'role' => 'customer' ] );

		$this->create_wc_order( [ 'customer_id' => $buyer_id, 'status' => 'completed' ] );
		$this->create_wc_order( [ 'customer_id' => $admin_id, 'status' => 'completed' ] );

		$tool   = new OrderListTool();
		$result = $tool->run( [ 'customer_id' => $buyer_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertSame( $buyer_id, (int) $result['items'][0]['customer_id'] );
	}

	/**
	 * schema：必含 items / total / total_pages
	 *
	 * @group smoke
	 */
	public function test_output_schema_has_required_keys(): void {
		$tool   = new OrderListTool();
		$schema = $tool->get_output_schema();
		$this->assert_schema_has_property( $schema, 'items' );
		$this->assert_schema_has_property( $schema, 'total' );
		$this->assert_schema_has_property( $schema, 'total_pages' );
	}
}
