<?php
/**
 * StudentExportCsvTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentExportCsvTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentExportCsvToolTest
 */
class StudentExportCsvToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能匯出 CSV，回傳 URL
	 *
	 * @group happy
	 */
	public function test_admin_can_export_csv_and_get_url(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user_id, $course_id );

		$tool   = new StudentExportCsvTool();
		$result = $tool->run( [ 'course_id' => $course_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'url', $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertArrayHasKey( 'rows', $result );
		$this->assertStringContainsString( '.csv', $result['filename'] );
		$this->assertGreaterThanOrEqual( 1, $result['rows'] );
	}

	/**
	 * 課程不存在回 404
	 *
	 * @group error
	 */
	public function test_missing_course_returns_wp_error(): void {
		$this->create_admin_user();

		$tool   = new StudentExportCsvTool();
		$result = $tool->run( [ 'course_id' => 9999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_course_not_found', $result->get_error_code() );
	}

	/**
	 * 訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();
		$tool   = new StudentExportCsvTool();
		$result = $tool->run( [ 'course_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
