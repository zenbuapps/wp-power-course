<?php
/**
 * Teacher Get MCP Tool 整合測試
 *
 * @group mcp
 * @group teacher
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\Tools\Teacher\TeacherGetTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class TeacherGetToolTest
 */
class TeacherGetToolTest extends IntegrationTestCase {

	/**
	 * Happy path：成功取得講師詳情 + 授課清單
	 *
	 * @group happy
	 */
	public function test_admin_can_get_teacher_with_authored_courses(): void {
		$this->create_admin_user();

		$teacher_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\update_user_meta( $teacher_id, 'is_teacher', 'yes' );

		// 建立 2 個課程並綁定該講師（透過 add_post_meta 多筆 teacher_ids）
		$course_1 = $this->create_course( [ 'post_title' => '課程 A' ] );
		$course_2 = $this->create_course( [ 'post_title' => '課程 B' ] );
		\add_post_meta( $course_1, 'teacher_ids', (string) $teacher_id, false );
		\add_post_meta( $course_2, 'teacher_ids', (string) $teacher_id, false );

		$tool   = new TeacherGetTool();
		$result = $tool->run( [ 'user_id' => $teacher_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $teacher_id, (int) $result['id'] );
		$this->assertTrue( (bool) $result['is_teacher'] );
		$this->assertArrayHasKey( 'authored_courses', $result );
		$this->assertIsArray( $result['authored_courses'] );
		$this->assertCount( 2, $result['authored_courses'], '預期該講師授課 2 門課程' );
	}

	/**
	 * 非講師：對普通 user 呼叫應回傳 404
	 *
	 * @group error
	 */
	public function test_non_teacher_user_returns_error(): void {
		$this->create_admin_user();

		$normal_user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$tool   = new TeacherGetTool();
		$result = $tool->run( [ 'user_id' => $normal_user_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * 權限不足：subscriber 應被拒絕（capability = list_users）
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new TeacherGetTool();
		$result = $tool->run( [ 'user_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema 驗證：必填 user_id 與 metadata
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new TeacherGetTool();
		$this->assertSame( 'teacher_get', $tool->get_name() );
		$this->assertSame( 'teacher', $tool->get_category() );
		$this->assertSame( 'list_users', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'user_id' );
		$this->assertSame( [ 'user_id' ], $schema['required'] ?? [] );
	}
}
