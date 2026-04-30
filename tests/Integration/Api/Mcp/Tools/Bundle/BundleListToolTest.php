<?php
/**
 * BundleListTool 整合測試
 *
 * @group mcp
 * @group bundle
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\Tools\Bundle\BundleListTool;
use J7\PowerCourse\BundleProduct\Helper;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class BundleListToolTest
 */
class BundleListToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能列出 bundle 商品
	 *
	 * @group happy
	 */
	public function test_admin_can_list_bundles(): void {
		$this->create_admin_user();
		$course_id = $this->create_wc_course();
		$this->create_wc_bundle( '方案 A', $course_id );
		$this->create_wc_bundle( '方案 B', $course_id );

		$tool   = new BundleListTool();
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

		$tool   = new BundleListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 過濾 link_course_id：回傳的方案都綁定該課程
	 *
	 * @group happy
	 */
	public function test_filter_by_link_course_id(): void {
		$this->create_admin_user();
		$course_a = $this->create_wc_course( [ 'post_title' => '課程 A' ] );
		$course_b = $this->create_wc_course( [ 'post_title' => '課程 B' ] );
		$bundle_a = $this->create_wc_bundle( '方案 A', $course_a );
		$this->create_wc_bundle( '方案 B', $course_b );

		$tool   = new BundleListTool();
		$result = $tool->run( [ 'link_course_id' => $course_a ] );

		$this->assertIsArray( $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );

		// 驗證回傳的 items 都綁定 course_a
		$found = false;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $bundle_a ) {
				$found = true;
				$this->assertSame( $course_a, (int) $item['link_course_id'] );
			}
		}
		$this->assertTrue( $found, '應包含綁定 course_a 的方案' );
	}
}
