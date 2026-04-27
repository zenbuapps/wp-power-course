<?php
/**
 * Course Delete MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseDeleteTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseDeleteToolTest
 */
class CourseDeleteToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員刪除課程
	 *
	 * @group happy
	 */
	public function test_admin_can_delete_course(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();

		$tool   = new CourseDeleteTool();
		$result = $tool->run( [ 'id' => $course_id ] );

		$this->assertIsArray( $result, '預期回傳含 id 的陣列' );
		$this->assertSame( $course_id, (int) $result['id'] );

		// 驗證資料已從 DB 移除
		$this->assertNull( \get_post( $course_id ) );
	}

	/**
	 * 權限不足：subscriber 應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();
		$course_id = $this->create_course();

		$tool   = new CourseDeleteTool();
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

		$tool   = new CourseDeleteTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
