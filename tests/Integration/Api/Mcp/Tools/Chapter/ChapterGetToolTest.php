<?php
/**
 * ChapterGetTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterGetTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterGetToolTest
 */
class ChapterGetToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能取得單一章節
	 *
	 * @group happy
	 */
	public function test_admin_can_get_chapter(): void {
		$this->create_admin_user();

		$chapter_id = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '章節 A',
				'post_status' => 'publish',
			]
		);

		$tool   = new ChapterGetTool();
		$result = $tool->run( [ 'chapter_id' => $chapter_id ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'chapter', $result );
		$this->assertIsArray( $result['chapter'] );
	}

	/**
	 * 權限不足：訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new ChapterGetTool();
		$result = $tool->run( [ 'chapter_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺少 chapter_id
	 *
	 * @group smoke
	 */
	public function test_missing_chapter_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterGetTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}

	/**
	 * 找不到章節：回傳 not_found
	 *
	 * @group smoke
	 */
	public function test_not_found_chapter(): void {
		$this->create_admin_user();

		$tool   = new ChapterGetTool();
		$result = $tool->run( [ 'chapter_id' => 999999999 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_chapter_not_found', $result->get_error_code() );
	}
}
