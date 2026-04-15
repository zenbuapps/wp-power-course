<?php
/**
 * ProgressResetTool 整合測試
 *
 * @group mcp
 * @group progress
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\Migration;
use J7\PowerCourse\Api\Mcp\Tools\Progress\ProgressResetTool;
use J7\PowerCourse\Plugin;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ProgressResetToolTest
 */
class ProgressResetToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能重置學員在課程的進度，刪除所有章節紀錄
	 *
	 * @group happy
	 */
	public function test_admin_can_reset_student_progress(): void {
		$this->create_admin_user();

		$course_id   = $this->create_course();
		$chapter_a   = $this->create_chapter( $course_id );
		$chapter_b   = $this->create_chapter( $course_id );
		$student_id  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $student_id, $course_id );
		$this->set_chapter_finished( $chapter_a, $student_id, '2026-01-01 00:00:00' );
		$this->set_chapter_finished( $chapter_b, $student_id, '2026-01-02 00:00:00' );

		$tool   = new ProgressResetTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => true,
			]
		);

		$this->assertIsArray( $result, '應回傳陣列，實際為 ' . print_r( $result, true ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $course_id, $result['course_id'] );
		$this->assertSame( $student_id, $result['user_id'] );
		$this->assertGreaterThanOrEqual( 2, $result['deleted_rows'] );

		// 驗證 DB 確實被清空
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_TABLE_NAME;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND post_id IN (%d, %d)",
				$student_id,
				$chapter_a,
				$chapter_b
			)
		);
		$this->assertSame( 0, $count, '章節進度應全數被刪除' );
	}

	/**
	 * safety：confirm 不是嚴格的 true → 拒絕執行
	 *
	 * @group security
	 */
	public function test_confirm_not_strictly_true_is_rejected(): void {
		$this->create_admin_user();

		$course_id  = $this->create_course();
		$student_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool = new ProgressResetTool();

		// 未提供 confirm
		$r1 = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $r1 );
		$this->assertSame( 'mcp_confirm_required', $r1->get_error_code() );

		// confirm = false
		$r2 = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => false,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $r2 );
		$this->assertSame( 'mcp_confirm_required', $r2->get_error_code() );

		// confirm = 'true'（字串）應被拒絕，因為必須嚴格 bool true
		$r3 = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => 'true',
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $r3 );
		$this->assertSame( 'mcp_confirm_required', $r3->get_error_code() );

		// confirm = 1（整數 truthy）應被拒絕
		$r4 = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => 1,
			]
		);
		$this->assertInstanceOf( \WP_Error::class, $r4 );
		$this->assertSame( 'mcp_confirm_required', $r4->get_error_code() );
	}

	/**
	 * security：subscriber（無 edit_users 權限）被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied_by_capability(): void {
		$this->create_subscriber_user();

		$course_id  = $this->create_course();
		$student_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new ProgressResetTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => true,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * happy：ActivityLog 會記錄重置操作
	 *
	 * @group happy
	 */
	public function test_reset_is_logged_to_activity(): void {
		$this->create_admin_user();

		$course_id   = $this->create_course();
		$chapter_id  = $this->create_chapter( $course_id );
		$student_id  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $student_id, $course_id );
		$this->set_chapter_finished( $chapter_id, $student_id, '2026-01-01 00:00:00' );

		$tool = new ProgressResetTool();
		$tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $student_id,
				'confirm'   => true,
			]
		);

		global $wpdb;
		$activity_table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$logs           = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT * FROM {$activity_table} WHERE tool_name = %s ORDER BY id DESC LIMIT 1",
				'progress_reset'
			)
		);

		$this->assertNotEmpty( $logs, 'ActivityLog 應有 progress_reset 記錄' );
		$this->assertSame( 1, (int) $logs[0]->success );
	}
}
