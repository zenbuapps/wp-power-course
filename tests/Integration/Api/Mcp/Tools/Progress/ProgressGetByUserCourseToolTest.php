<?php
/**
 * ProgressGetByUserCourseTool 整合測試
 *
 * @group mcp
 * @group progress
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\Tools\Progress\ProgressGetByUserCourseTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ProgressGetByUserCourseToolTest
 */
class ProgressGetByUserCourseToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員可取得其他學員在課程的進度
	 *
	 * @group happy
	 */
	public function test_admin_can_get_progress_of_other_student(): void {
		$this->create_admin_user();

		$course_id  = $this->create_course();
		$chapter_id = $this->create_chapter( $course_id );
		$student_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $student_id, $course_id );
		$this->set_chapter_finished( $chapter_id, $student_id, '2026-01-01 00:00:00' );

		$tool   = new ProgressGetByUserCourseTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
			]
		);

		$this->assertIsArray( $result, '應回傳陣列，實際為 ' . print_r( $result, true ) );
		$this->assertSame( $student_id, $result['user_id'] );
		$this->assertSame( $course_id, $result['course_id'] );
		$this->assertArrayHasKey( 'progress', $result );
		$this->assertArrayHasKey( 'finished_chapters', $result );
		$this->assertArrayHasKey( 'total_chapters', $result );
		$this->assertContains( $chapter_id, $result['finished_chapter_ids'] );
	}

	/**
	 * happy：未帶 user_id 時預設使用當前登入者
	 *
	 * @group happy
	 */
	public function test_defaults_to_current_user_when_user_id_omitted(): void {
		$admin_id  = $this->create_admin_user();
		$course_id = $this->create_course();
		$this->create_chapter( $course_id );
		$this->enroll_user_to_course( $admin_id, $course_id );

		$tool   = new ProgressGetByUserCourseTool();
		$result = $tool->run( [ 'course_id' => $course_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $admin_id, $result['user_id'] );
	}

	/**
	 * security：subscriber 想查他人進度 → 403
	 *
	 * @group security
	 */
	public function test_subscriber_cannot_read_other_user_progress(): void {
		$this->create_subscriber_user();
		$other_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$course_id  = $this->create_course();

		$tool   = new ProgressGetByUserCourseTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $other_user,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 course_id 回傳 422
	 *
	 * @group smoke
	 */
	public function test_missing_course_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ProgressGetByUserCourseTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
