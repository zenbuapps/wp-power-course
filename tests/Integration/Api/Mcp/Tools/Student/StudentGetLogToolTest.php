<?php
/**
 * StudentGetLogTool 整合測試
 *
 * @group mcp
 * @group student
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\Tools\Student\StudentGetLogTool;
use J7\PowerCourse\Resources\StudentLog\CRUD as StudentLogCRUD;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class StudentGetLogToolTest
 */
class StudentGetLogToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員可查詢學員日誌
	 *
	 * @group happy
	 */
	public function test_admin_can_query_logs(): void {
		$this->create_admin_user();

		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$course_id = $this->create_course();

		// 寫入一筆日誌
		StudentLogCRUD::instance()->add(
			[
				'user_id'   => (string) $user_id,
				'course_id' => (string) $course_id,
				'title'     => '測試日誌',
				'content'   => '',
				'log_type'  => 'course_granted',
			]
		);

		$tool   = new StudentGetLogTool();
		$result = $tool->run(
			[
				'user_id'        => $user_id,
				'course_id'      => $course_id,
				'paged'          => 1,
				'posts_per_page' => 20,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'logs', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
		$this->assertSame( $user_id, $result['logs'][0]['user_id'] );
	}

	/**
	 * 空查詢回傳空清單（預設分頁）
	 *
	 * @group smoke
	 */
	public function test_empty_filter_returns_paginated_shape(): void {
		$this->create_admin_user();

		$tool   = new StudentGetLogTool();
		$result = $tool->run( [] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'logs', $result );
		$this->assertArrayHasKey( 'total_pages', $result );
		$this->assertArrayHasKey( 'current_page', $result );
	}

	/**
	 * 訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new StudentGetLogTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}
}
