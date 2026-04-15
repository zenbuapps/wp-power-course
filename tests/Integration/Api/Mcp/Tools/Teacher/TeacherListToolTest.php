<?php
/**
 * Teacher List MCP Tool 整合測試
 *
 * @group mcp
 * @group teacher
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\Tools\Teacher\TeacherListTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class TeacherListToolTest
 */
class TeacherListToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員成功列出講師
	 *
	 * @group happy
	 */
	public function test_admin_can_list_teachers(): void {
		$this->create_admin_user();

		// 建立 2 位講師 + 1 位非講師
		$teacher_1 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$teacher_2 = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\update_user_meta( $teacher_1, 'is_teacher', 'yes' );
		\update_user_meta( $teacher_2, 'is_teacher', 'yes' );

		$tool   = new TeacherListTool();
		$result = $tool->run( [ 'number' => 20 ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertGreaterThanOrEqual( 2, (int) $result['total'], '預期至少 2 位講師' );

		// 確認回傳的項目都是講師
		foreach ( $result['items'] as $item ) {
			$this->assertTrue( (bool) $item['is_teacher'], '列表內的使用者應皆為講師' );
		}
	}

	/**
	 * 權限不足：訪客應被拒絕
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new TeacherListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema 驗證：input_schema 欄位與 metadata
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new TeacherListTool();
		$this->assertSame( 'teacher_list', $tool->get_name() );
		$this->assertSame( 'teacher', $tool->get_category() );
		$this->assertSame( 'list_users', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'paged' );
		$this->assert_schema_has_property( $schema, 'number' );
		$this->assert_schema_has_property( $schema, 'search' );
	}
}
