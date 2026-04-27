<?php
/**
 * ChapterListTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterListTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterListToolTest
 */
class ChapterListToolTest extends IntegrationTestCase {

	/**
	 * happy path：管理員能列出章節
	 *
	 * @group happy
	 */
	public function test_admin_can_list_chapters(): void {
		$this->create_admin_user();

		// 建立兩個章節
		$this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '第一章',
				'post_status' => 'publish',
			]
		);
		$this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '第二章',
				'post_status' => 'publish',
			]
		);

		$tool   = new ChapterListTool();
		$result = $tool->run( [] );

		$this->assertIsArray( $result, 'list 應該回傳陣列' );
		$this->assertArrayHasKey( 'chapters', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertGreaterThanOrEqual( 2, $result['total'] );
	}

	/**
	 * 權限不足：訪客被拒絕
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new ChapterListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result, '訪客執行應回傳 WP_Error' );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤處理：不合法的 course_id 仍應平穩回傳（不 fatal）
	 *
	 * @group smoke
	 */
	public function test_invalid_course_id_returns_empty_list(): void {
		$this->create_admin_user();

		$tool   = new ChapterListTool();
		$result = $tool->run( [ 'course_id' => 999999999 ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'chapters', $result );
		$this->assertSame( 0, $result['total'], '不存在的 course_id 應回傳空清單' );
	}

	/**
	 * schema 驗證：input schema 含必要欄位
	 *
	 * @group smoke
	 */
	public function test_input_schema_shape(): void {
		$tool   = new ChapterListTool();
		$schema = $tool->get_input_schema();

		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'course_id', $schema['properties'] );
		$this->assertArrayHasKey( 'posts_per_page', $schema['properties'] );
	}
}
