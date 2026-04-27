<?php
/**
 * StudentListTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentListTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentListToolTest
 */
class StudentListToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能列出課程的學員
	 *
	 * @group happy
	 */
	public function test_admin_can_list_students(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user1     = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$user2     = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user1, $course_id );
		$this->enroll_user_to_course( $user2, $course_id );

		$tool   = new StudentListTool();
		$result = $tool->run(
			[
				'course_id'      => $course_id,
				'posts_per_page' => 50,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'students', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
	}

	/**
	 * 權限不足：訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new StudentListTool();
		$result = $tool->run( [ 'course_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema：缺 course_id 回 422
	 *
	 * @group smoke
	 */
	public function test_missing_course_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new StudentListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
