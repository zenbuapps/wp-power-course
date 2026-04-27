<?php
/**
 * ReportStudentCountTool 整合測試
 *
 * @group mcp
 * @group report
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Report;

use J7\PowerCourse\Api\Mcp\Tools\Report\ReportStudentCountTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ReportStudentCountToolTest
 */
class ReportStudentCountToolTest extends IntegrationTestCase {

	/**
	 * Schema / metadata 檢查
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new ReportStudentCountTool();

		$this->assertSame( 'report_student_count', $tool->get_name() );
		$this->assertSame( 'report', $tool->get_category() );
		$this->assertSame( 'view_woocommerce_reports', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'date_from' );
		$this->assert_schema_has_property( $schema, 'date_to' );
		$this->assertContains( 'date_from', $schema['required'] ?? [] );
		$this->assertContains( 'date_to', $schema['required'] ?? [] );

		$out = $tool->get_output_schema();
		$this->assert_schema_has_property( $out, 'total' );
		$this->assert_schema_has_property( $out, 'intervals' );
	}

	/**
	 * 訪客應被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new ReportStudentCountTool();
		$result = $tool->run(
			[
				'date_from' => '2026-01-01',
				'date_to'   => '2026-01-31',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * Happy path：有學員授權紀錄時可回傳統計（管理員）
	 *
	 * @group happy
	 */
	public function test_admin_can_get_student_count(): void {
		$this->create_admin_user();

		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->enroll_user_to_course( $user_id, $course_id );

		$tool = new ReportStudentCountTool();

		// 使用涵蓋今日的區間（enroll_user_to_course 寫入當前時間）
		$today = \wp_date( 'Y-m-d' );
		$from  = \wp_date( 'Y-m-d', strtotime( '-7 days' ) );

		$result = $tool->run(
			[
				'date_from' => $from,
				'date_to'   => $today,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'intervals', $result );
		$this->assertGreaterThanOrEqual( 1, (int) $result['total'] );
	}

	/**
	 * 缺少 date_from / date_to 應回傳 WP_Error
	 *
	 * @group validation
	 */
	public function test_missing_dates_returns_wp_error(): void {
		$this->create_admin_user();

		$tool   = new ReportStudentCountTool();
		$result = $tool->run( [ 'date_from' => '2026-01-01' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}

	/**
	 * 日期區間超過 365 天應被拒
	 *
	 * @group security
	 * @group validation
	 */
	public function test_date_range_exceeds_365_days_is_rejected(): void {
		$this->create_admin_user();

		$tool   = new ReportStudentCountTool();
		$result = $tool->run(
			[
				'date_from' => '2024-01-01',
				'date_to'   => '2026-01-01', // 相距 731 天
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_date_range_too_large', $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
