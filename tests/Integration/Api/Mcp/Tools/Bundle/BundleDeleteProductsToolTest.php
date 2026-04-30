<?php
/**
 * BundleDeleteProductsTool 整合測試
 *
 * @group mcp
 * @group bundle
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\Tools\Bundle\BundleDeleteProductsTool;
use J7\PowerCourse\BundleProduct\Helper;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class BundleDeleteProductsToolTest
 */
class BundleDeleteProductsToolTest extends IntegrationTestCase {

	/**
	 * happy：未指定 product_ids 時清空所有
	 *
	 * @group happy
	 */
	public function test_admin_can_clear_all_bundled_ids(): void {
		$this->create_admin_user();
		$course_id = $this->create_wc_course();
		$other_id  = $this->create_wc_course( [ 'post_title' => '其他商品' ] );
		$bundle_id = $this->create_wc_bundle_with_products( $course_id, [ $course_id, $other_id ] );

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run( [ 'id' => $bundle_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['cleared'] );
		$this->assertContains( $course_id, $result['removed_ids'] );
		$this->assertContains( $other_id, $result['removed_ids'] );
		$this->assertSame( [], $result['product_ids'] );
	}

	/**
	 * happy：指定 product_ids 時只移除指定的
	 *
	 * @group happy
	 */
	public function test_admin_can_remove_specific_ids(): void {
		$this->create_admin_user();
		$course_id = $this->create_wc_course();
		$other_id  = $this->create_wc_course( [ 'post_title' => '其他商品' ] );
		$bundle_id = $this->create_wc_bundle_with_products( $course_id, [ $course_id, $other_id ] );

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run(
			[
				'id'          => $bundle_id,
				'product_ids' => [ $other_id ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cleared'] );
		$this->assertSame( [ $other_id ], $result['removed_ids'] );

		// 驗證 product_ids 回傳值中不再包含 other_id
		$this->assertNotContains( $other_id, $result['product_ids'] );
		$this->assertContains( $course_id, $result['product_ids'] );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run( [ 'id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
