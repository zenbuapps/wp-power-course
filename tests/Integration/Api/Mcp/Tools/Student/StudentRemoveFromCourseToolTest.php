<?php
/**
 * StudentRemoveFromCourseTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentRemoveFromCourseTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentRemoveFromCourseToolTest
 */
class StudentRemoveFromCourseToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員移除學員課程權限
	 *
	 * @group happy
	 */
	public function test_admin_can_remove_student(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user_id, $course_id );
		$this->assertTrue( $this->user_has_course_access( $user_id, $course_id ) );

		$tool   = new StudentRemoveFromCourseTool();
		$result = $tool->run(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $this->user_has_course_access( $user_id, $course_id ) );
	}

	/**
	 * 無效 user_id 回錯
	 *
	 * @group error
	 */
	public function test_invalid_user_returns_wp_error(): void {
		$this->create_admin_user();

		$tool   = new StudentRemoveFromCourseTool();
		$result = $tool->run(
			[
				'user_id'   => 9999999,
				'course_id' => 1,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * 權限不足
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new StudentRemoveFromCourseTool();
		$result = $tool->run(
			[
				'user_id'   => 1,
				'course_id' => 1,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
