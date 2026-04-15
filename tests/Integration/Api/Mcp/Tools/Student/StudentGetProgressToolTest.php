<?php
/**
 * StudentGetProgressTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentGetProgressTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentGetProgressToolTest
 */
class StudentGetProgressToolTest extends IntegrationTestCase {

	/**
	 * happy：取得進度摘要
	 *
	 * @group happy
	 */
	public function test_admin_can_get_progress(): void {
		$this->create_admin_user();

		$course_id  = $this->create_course();
		$chapter_id = $this->create_chapter( $course_id );
		$user_id    = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user_id, $course_id );
		$this->set_chapter_finished( $chapter_id, $user_id, '2026-01-01 00:00:00' );

		$tool   = new StudentGetProgressTool();
		$result = $tool->run(
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertSame( $course_id, $result['course_id'] );
		$this->assertArrayHasKey( 'progress', $result );
		$this->assertArrayHasKey( 'finished_chapters', $result );
		$this->assertArrayHasKey( 'total_chapters', $result );
	}

	/**
	 * 找不到課程
	 *
	 * @group error
	 */
	public function test_course_not_found(): void {
		$this->create_admin_user();
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new StudentGetProgressTool();
		$result = $tool->run(
			[
				'user_id'   => $user_id,
				'course_id' => 9999999,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'progress_course_not_found', $result->get_error_code() );
	}

	/**
	 * 訪客無 read 權限通常仍通過 read cap；改以缺必填驗證錯誤
	 *
	 * @group smoke
	 */
	public function test_missing_required_fields(): void {
		$this->create_admin_user();

		$tool   = new StudentGetProgressTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
