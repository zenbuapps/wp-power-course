<?php
/**
 * Course Update MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseUpdateTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseUpdateToolTest
 */
class CourseUpdateToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員更新既有課程名稱
	 *
	 * @group happy
	 */
	public function test_admin_can_update_course(): void {
		$this->create_admin_user();
		$course_id = $this->create_course( [ 'post_title' => 'Old Name' ] );

		$tool   = new CourseUpdateTool();
		$result = $tool->run(
			[
				'id'   => $course_id,
				'name' => 'New Name',
			]
		);

		$this->assertIsArray( $result, '預期回傳含 id 的陣列' );
		$this->assertArrayHasKey( 'id', $result );

		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertSame( 'New Name', $product->get_name() );
	}

	/**
	 * 權限不足：subscriber 應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();
		$course_id = $this->create_course();

		$tool   = new CourseUpdateTool();
		$result = $tool->run(
			[
				'id'   => $course_id,
				'name' => 'Forbidden Update',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema validation：缺少 id 應回傳 422
	 *
	 * @group error
	 */
	public function test_missing_id_returns_validation_error(): void {
		$this->create_admin_user();

		$tool   = new CourseUpdateTool();
		$result = $tool->run( [ 'name' => 'No ID' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
