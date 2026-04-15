<?php
/**
 * StudentExportCountTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentExportCountTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentExportCountToolTest
 */
class StudentExportCountToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員可取得預計匯出筆數
	 *
	 * @group happy
	 */
	public function test_admin_can_get_count(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user_id, $course_id );

		$tool   = new StudentExportCountTool();
		$result = $tool->run( [ 'avl_course_ids' => [ $course_id ] ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertGreaterThanOrEqual( 1, $result['count'] );
	}

	/**
	 * 無資料時回傳 0
	 *
	 * @group smoke
	 */
	public function test_zero_count_when_no_data(): void {
		$this->create_admin_user();

		$tool   = new StudentExportCountTool();
		$result = $tool->run( [ 'avl_course_ids' => [ 9999999 ] ] );

		$this->assertSame( 0, $result['count'] );
	}

	/**
	 * 訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();
		$tool   = new StudentExportCountTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
