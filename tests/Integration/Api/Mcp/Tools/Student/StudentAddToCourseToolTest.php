<?php
/**
 * StudentAddToCourseTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentAddToCourseTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentAddToCourseToolTest
 */
class StudentAddToCourseToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員將學員加入課程
	 *
	 * @group happy
	 */
	public function test_admin_can_add_student_to_course(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentAddToCourseTool();
		$result = $tool->run(
			[
				'user_id'     => $user_id,
				'course_id'   => $course_id,
				'expire_date' => 0,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( $course_id, $result['course_id'] );
		$this->assertTrue( $this->user_has_course_access( $user_id, $course_id ) );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new StudentAddToCourseTool();
		$result = $tool->run(
			[
				'user_id'   => 1,
				'course_id' => 1,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema：缺必填欄位
	 *
	 * @group smoke
	 */
	public function test_missing_required_fields_returns_error(): void {
		$this->create_admin_user();

		$tool   = new StudentAddToCourseTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
