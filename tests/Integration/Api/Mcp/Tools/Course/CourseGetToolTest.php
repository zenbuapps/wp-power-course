<?php
/**
 * Course Get MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseGetTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseGetToolTest
 */
class CourseGetToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員取得單一課程詳情
	 *
	 * @group happy
	 */
	public function test_admin_can_get_course(): void {
		$this->create_admin_user();
		$course_id = $this->create_course( [ 'post_title' => 'My Course' ] );

		$tool   = new CourseGetTool();
		$result = $tool->run( [ 'id' => $course_id ] );

		$this->assertIsArray( $result, '應回傳課程陣列' );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertSame( (string) $course_id, (string) $result['id'] );
	}

	/**
	 * 權限不足：guest 應被 AbstractTool 擋下
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();
		$course_id = $this->create_course();

		$tool   = new CourseGetTool();
		$result = $tool->run( [ 'id' => $course_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema validation：缺少 required field id 時應回傳 422
	 *
	 * @group error
	 */
	public function test_missing_id_returns_validation_error(): void {
		$this->create_admin_user();

		$tool   = new CourseGetTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
