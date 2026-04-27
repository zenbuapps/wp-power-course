<?php
/**
 * ReportRevenueStatsTool 整合測試
 *
 * @group mcp
 * @group report
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Report;

use J7\PowerCourse\Api\Mcp\Tools\Report\ReportRevenueStatsTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ReportRevenueStatsToolTest
 */
class ReportRevenueStatsToolTest extends IntegrationTestCase {

	/**
	 * Schema / metadata 檢查
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new ReportRevenueStatsTool();

		$this->assertSame( 'report_revenue_stats', $tool->get_name() );
		$this->assertSame( 'report', $tool->get_category() );
		$this->assertSame( 'view_woocommerce_reports', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'date_from' );
		$this->assert_schema_has_property( $schema, 'date_to' );
		$this->assertContains( 'date_from', $schema['required'] ?? [] );
		$this->assertContains( 'date_to', $schema['required'] ?? [] );
	}

	/**
	 * 訪客應被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new ReportRevenueStatsTool();
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
	 * 缺少 date_from / date_to 應回傳 WP_Error
	 *
	 * @group validation
	 */
	public function test_missing_dates_returns_wp_error(): void {
		$this->create_admin_user();

		$tool   = new ReportRevenueStatsTool();
		$result = $tool->run( [] );

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

		$tool   = new ReportRevenueStatsTool();
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

	/**
	 * date_to 早於 date_from 應被拒
	 *
	 * @group validation
	 */
	public function test_reversed_date_range_is_rejected(): void {
		$this->create_admin_user();

		$tool   = new ReportRevenueStatsTool();
		$result = $tool->run(
			[
				'date_from' => '2026-02-01',
				'date_to'   => '2026-01-01',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
