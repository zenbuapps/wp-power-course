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
	 * 建立一個 bundle 商品（供多測試共用）
	 *
	 * @param string $title    標題
	 * @param int    $course_id 綁定課程 ID
	 * @return int bundle post id
	 */
	private function create_bundle( string $title, int $course_id ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );
		return $bundle_id;
	}

	/**
	 * happy：管理員能列出 bundle 商品
	 *
	 * @group happy
	 */
	public function test_admin_can_list_bundles(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$this->create_bundle( '方案 A', $course_id );
		$this->create_bundle( '方案 B', $course_id );

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
	 * 過濾 link_course_id：只回傳綁定該課程的方案
	 *
	 * @group happy
	 */
	public function test_filter_by_link_course_id(): void {
		$this->create_admin_user();
		$course_a = $this->create_course( [ 'post_title' => '課程 A' ] );
		$course_b = $this->create_course( [ 'post_title' => '課程 B' ] );
		$this->create_bundle( '方案 A', $course_a );
		$this->create_bundle( '方案 B', $course_b );

		$tool   = new BundleListTool();
		$result = $tool->run( [ 'link_course_id' => $course_a ] );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( $course_a, (int) $result['items'][0]['link_course_id'] );
	}
}
