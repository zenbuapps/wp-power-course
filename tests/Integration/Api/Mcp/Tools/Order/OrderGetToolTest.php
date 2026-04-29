<?php
/**
 * OrderGetTool 整合測試
 *
 * @group mcp
 * @group order
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\Tools\Order\OrderGetTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class OrderGetToolTest
 */
class OrderGetToolTest extends IntegrationTestCase {

	/**
	 * 建立一筆包含課程商品 item 的 WC 訂單
	 *
	 * @param int    $customer_id 客戶 ID
	 * @param int    $course_id   課程商品 ID
	 * @param string $status      訂單狀態
	 * @return \WC_Order
	 */
	private function create_order_with_course_item( int $customer_id, int $course_id, string $status = 'processing' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_id( $customer_id );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_currency( 'TWD' );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $course_id );
		$item->set_name( '測試課程' );
		$item->set_quantity( 1 );
		$order->add_item( $item );

		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * happy：管理員能取得訂單詳情與 courses
	 *
	 * @group happy
	 */
	public function test_admin_can_get_order_with_courses(): void {
		$this->create_admin_user();
		$buyer_id  = $this->factory()->user->create();
		$course_id = $this->create_course();
		// 用 processing 狀態避免 woocommerce_order_status_completed hooks 干擾
		$order     = $this->create_order_with_course_item( $buyer_id, $course_id, 'processing' );

		$tool   = new OrderGetTool();
		$result = $tool->run( [ 'id' => $order->get_id() ] );

		$this->assertIsArray( $result );
		$this->assertSame( $order->get_id(), (int) $result['id'] );
		$this->assertSame( 'processing', $result['status'] );
		$this->assertSame( $buyer_id, (int) $result['customer_id'] );
		$this->assertArrayHasKey( 'courses', $result );
		$this->assertGreaterThanOrEqual( 1, count( $result['courses'] ) );
		$product_ids = array_column( $result['courses'], 'product_id' );
		$this->assertContains( $course_id, array_map( 'intval', $product_ids ) );
	}

	/**
	 * 不存在的訂單：404
	 *
	 * @group smoke
	 */
	public function test_missing_order_returns_not_found(): void {
		$this->create_admin_user();

		$tool   = new OrderGetTool();
		$result = $tool->run( [ 'id' => 999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_order_not_found', $result->get_error_code() );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new OrderGetTool();
		$result = $tool->run( [ 'id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 id → 422
	 *
	 * @group smoke
	 */
	public function test_missing_id_returns_invalid_input(): void {
		$this->create_admin_user();

		$tool   = new OrderGetTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
