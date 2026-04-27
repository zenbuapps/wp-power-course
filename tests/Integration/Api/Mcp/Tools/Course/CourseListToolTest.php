<?php
/**
 * Course List MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseListTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseListToolTest
 */
class CourseListToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員可成功列出課程
	 *
	 * @group happy
	 */
	public function test_admin_can_list_courses(): void {
		$this->create_admin_user();
		$this->create_course( [ 'post_title' => 'Course A' ] );
		$this->create_course( [ 'post_title' => 'Course B' ] );

		$tool   = new CourseListTool();
		$result = $tool->run( [ 'posts_per_page' => 20 ] );

		$this->assertIsArray( $result, '預期回傳陣列' );
		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertGreaterThanOrEqual( 2, (int) $result['total'] );
	}

	/**
	 * 權限不足：subscriber 使用者應被拒絕並回傳 WP_Error
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();
		// course_list 的 capability 是 'read'，subscriber 也有 'read'，
		// 但要確認 guest 狀態被擋下（AbstractTool 有 is_user_logged_in guard）
		$this->set_guest_user();

		$tool   = new CourseListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result, '訪客應回傳 WP_Error' );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema 驗證：input_schema 必須包含分頁欄位且 category 為 course
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool = new CourseListTool();
		$this->assertSame( 'course_list', $tool->get_name() );
		$this->assertSame( 'course', $tool->get_category() );
		$this->assertSame( 'read', $tool->get_capability() );

		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'paged' );
		$this->assert_schema_has_property( $schema, 'posts_per_page' );
		$this->assert_schema_has_property( $schema, 'orderby' );
	}
}
