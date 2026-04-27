<?php
/**
 * Teacher Assign To Course MCP Tool 整合測試
 *
 * @group mcp
 * @group teacher
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\Tools\Teacher\TeacherAssignToCourseTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class TeacherAssignToCourseToolTest
 */
class TeacherAssignToCourseToolTest extends IntegrationTestCase {

	/**
	 * Happy path：成功將講師指派到課程
	 *
	 * @group happy
	 */
	public function test_admin_can_assign_teacher_to_course(): void {
		$this->create_admin_user();

		$teacher_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\update_user_meta( $teacher_id, 'is_teacher', 'yes' );
		$course_id = $this->create_course();

		$tool   = new TeacherAssignToCourseTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $teacher_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['success'] );
		$this->assertSame( $course_id, (int) $result['course_id'] );
		$this->assertSame( $teacher_id, (int) $result['user_id'] );

		// 驗證 post_meta 已寫入
		$teacher_ids = \get_post_meta( $course_id, 'teacher_ids', false );
		$this->assertIsArray( $teacher_ids );
		$this->assertContains( (string) $teacher_id, array_map( 'strval', (array) $teacher_ids ) );
	}

	/**
	 * Idempotent 測試：重複指派不應產生重複 meta
	 *
	 * @group idempotent
	 */
	public function test_duplicate_assign_is_idempotent(): void {
		$this->create_admin_user();

		$teacher_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\update_user_meta( $teacher_id, 'is_teacher', 'yes' );
		$course_id = $this->create_course();

		$tool = new TeacherAssignToCourseTool();
		$args = [
			'course_id' => $course_id,
			'user_id'   => $teacher_id,
		];

		// 連續指派 3 次
		$r1 = $tool->run( $args );
		$r2 = $tool->run( $args );
		$r3 = $tool->run( $args );

		$this->assertTrue( (bool) $r1['success'] );
		$this->assertTrue( (bool) $r2['success'] );
		$this->assertTrue( (bool) $r3['success'] );

		// 只能有一筆 teacher_ids meta
		$teacher_ids = (array) \get_post_meta( $course_id, 'teacher_ids', false );
		$matched     = array_filter(
			$teacher_ids,
			static fn( $value ): bool => (int) $value === $teacher_id
		);
		$this->assertCount( 1, $matched, '重複指派不應產生重複 teacher_ids meta' );
	}

	/**
	 * 權限不足：subscriber 應被拒絕（capability = edit_users）
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new TeacherAssignToCourseTool();
		$result = $tool->run(
			[
				'course_id' => 1,
				'user_id'   => 1,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema 驗證：metadata 與 required 欄位
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new TeacherAssignToCourseTool();
		$this->assertSame( 'teacher_assign_to_course', $tool->get_name() );
		$this->assertSame( 'teacher', $tool->get_category() );
		$this->assertSame( 'edit_users', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'course_id' );
		$this->assert_schema_has_property( $schema, 'user_id' );
		$this->assertEqualsCanonicalizing(
			[ 'course_id', 'user_id' ],
			$schema['required'] ?? []
		);
	}
}
