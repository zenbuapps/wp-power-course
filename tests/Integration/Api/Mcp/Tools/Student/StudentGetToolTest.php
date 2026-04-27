<?php
/**
 * StudentGetTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentGetTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentGetToolTest
 */
class StudentGetToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能取得學員詳情
	 *
	 * @group happy
	 */
	public function test_admin_can_get_student(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create(
			[
				'role'         => 'subscriber',
				'display_name' => '小明',
			]
		);
		\update_user_meta( $user_id, 'first_name', 'Ming' );
		$this->enroll_user_to_course( $user_id, $course_id );

		$tool   = new StudentGetTool();
		$result = $tool->run( [ 'user_id' => $user_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( 'Ming', $result['first_name'] );
		$this->assertContains( $course_id, $result['avl_course_ids'] );
	}

	/**
	 * 找不到學員回 404
	 *
	 * @group error
	 */
	public function test_not_found_returns_wp_error(): void {
		$this->create_admin_user();

		$tool   = new StudentGetTool();
		$result = $tool->run( [ 'user_id' => 9999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'student_not_found', $result->get_error_code() );
	}

	/**
	 * 訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();
		$tool   = new StudentGetTool();
		$result = $tool->run( [ 'user_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
