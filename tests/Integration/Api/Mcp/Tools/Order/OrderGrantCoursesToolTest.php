<?php
/**
 * OrderGrantCoursesTool 整合測試
 *
 * @group mcp
 * @group order
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Order;

use J7\PowerCourse\Api\Mcp\Tools\Order\OrderGrantCoursesTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class OrderGrantCoursesToolTest
 */
class OrderGrantCoursesToolTest extends IntegrationTestCase {

	/**
	 * 建立一筆包含課程商品 item 的 WC 訂單（HPOS 相容）
	 *
	 * @param int    $customer_id 客戶 ID
	 * @param int    $course_id   課程商品 ID
	 * @param string $status      訂單狀態
	 * @return \WC_Order
	 */
	private function create_order_with_course_item( int $customer_id, int $course_id, string $status = 'completed' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_id( $customer_id );
		$order->set_currency( 'TWD' );
		$order->set_billing_email( 'buyer@example.com' );

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
	 * happy：管理員重跑訂單授權，學員獲得課程存取權
	 *
	 * @group happy
	 */
	public function test_admin_can_grant_courses(): void {
		$this->create_admin_user();
		$buyer_id  = $this->factory()->user->create();
		$course_id = $this->create_course();
		$order     = $this->create_order_with_course_item( $buyer_id, $course_id );

		$tool   = new OrderGrantCoursesTool();
		$result = $tool->run( [ 'order_id' => $order->get_id() ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $order->get_id(), (int) $result['order_id'] );
		$this->assertGreaterThanOrEqual( 1, (int) $result['granted_count'] );

		// 驗證實際授權結果
		$this->assert_user_has_course_access( $buyer_id, $course_id );
	}

	/**
	 * idempotent：重跑授權不會產生重複的 avl_course_ids user meta
	 *
	 * @group happy
	 */
	public function test_rerun_is_idempotent(): void {
		$this->create_admin_user();
		$buyer_id  = $this->factory()->user->create();
		$course_id = $this->create_course();
		$order     = $this->create_order_with_course_item( $buyer_id, $course_id );

		$tool = new OrderGrantCoursesTool();

		// 第一次執行
		$first = $tool->run( [ 'order_id' => $order->get_id() ] );
		$this->assertIsArray( $first );
		$this->assertTrue( $first['success'] );

		$after_first = (array) \get_user_meta( $buyer_id, 'avl_course_ids', false );

		// 第二次執行（重跑）
		$second = $tool->run( [ 'order_id' => $order->get_id() ] );
		$this->assertIsArray( $second );
		$this->assertTrue( $second['success'] );

		$after_second = (array) \get_user_meta( $buyer_id, 'avl_course_ids', false );

		// user meta 不應增加（idempotent）
		$this->assertSame(
			count( $after_first ),
			count( $after_second ),
			'重跑授權不應產生重複的 avl_course_ids user meta'
		);

		// 仍保持課程存取權
		$this->assert_user_has_course_access( $buyer_id, $course_id );
	}

	/**
	 * 不存在的訂單：404
	 *
	 * @group smoke
	 */
	public function test_missing_order_returns_not_found(): void {
		$this->create_admin_user();

		$tool   = new OrderGrantCoursesTool();
		$result = $tool->run( [ 'order_id' => 999999 ] );

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

		$tool   = new OrderGrantCoursesTool();
		$result = $tool->run( [ 'order_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 order_id → 422
	 *
	 * @group smoke
	 */
	public function test_missing_order_id_returns_invalid_input(): void {
		$this->create_admin_user();

		$tool   = new OrderGrantCoursesTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
