<?php
/**
 * Course Duplicate MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseDuplicateTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseDuplicateToolTest
 */
class CourseDuplicateToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員複製課程
	 *
	 * @group happy
	 */
	public function test_admin_can_duplicate_course(): void {
		$this->create_admin_user();
		$course_id = $this->create_course( [ 'post_title' => 'Original Course' ] );

		$tool   = new CourseDuplicateTool();
		$result = $tool->run( [ 'id' => $course_id ] );

		$this->assertIsArray( $result, '預期回傳含新 id 的陣列' );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertGreaterThan( 0, (int) $result['id'] );
		$this->assertNotSame( $course_id, (int) $result['id'], '複製後的 ID 應與原 ID 不同' );
	}

	/**
	 * 權限不足：subscriber 應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();
		$course_id = $this->create_course();

		$tool   = new CourseDuplicateTool();
		$result = $tool->run( [ 'id' => $course_id ] );

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

		$tool   = new CourseDuplicateTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
