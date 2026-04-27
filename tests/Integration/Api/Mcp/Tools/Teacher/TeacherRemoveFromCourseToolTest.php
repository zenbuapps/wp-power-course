<?php
/**
 * Teacher Remove From Course MCP Tool 整合測試
 *
 * @group mcp
 * @group teacher
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\Tools\Teacher\TeacherRemoveFromCourseTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class TeacherRemoveFromCourseToolTest
 */
class TeacherRemoveFromCourseToolTest extends IntegrationTestCase {

	/**
	 * Happy path：成功將講師從課程移除
	 *
	 * @group happy
	 */
	public function test_admin_can_remove_teacher_from_course(): void {
		$this->create_admin_user();

		$teacher_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\update_user_meta( $teacher_id, 'is_teacher', 'yes' );
		$course_id = $this->create_course();
		\add_post_meta( $course_id, 'teacher_ids', (string) $teacher_id, false );

		$tool   = new TeacherRemoveFromCourseTool();
		$result = $tool->run(
			[
				'course_id' => $course_id,
				'user_id'   => $teacher_id,
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( (bool) $result['success'] );

		// 驗證 meta 已移除
		$teacher_ids = (array) \get_post_meta( $course_id, 'teacher_ids', false );
		$matched     = array_filter(
			$teacher_ids,
			static fn( $value ): bool => (int) $value === $teacher_id
		);
		$this->assertCount( 0, $matched, 'teacher_ids meta 應已被移除' );
	}

	/**
	 * 權限不足：subscriber 應被拒絕（capability = edit_users）
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new TeacherRemoveFromCourseTool();
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
		$tool = new TeacherRemoveFromCourseTool();
		$this->assertSame( 'teacher_remove_from_course', $tool->get_name() );
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
