<?php
/**
 * ChapterCreateTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterCreateTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterCreateToolTest
 */
class ChapterCreateToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能建立章節
	 *
	 * @group happy
	 */
	public function test_admin_can_create_chapter(): void {
		$this->create_admin_user();

		$tool   = new ChapterCreateTool();
		$result = $tool->run(
			[
				'post_title'   => '測試章節',
				'post_content' => '<p>內容</p>',
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'chapter_id', $result );
		$this->assertGreaterThan( 0, $result['chapter_id'] );

		$post = \get_post( $result['chapter_id'] );
		$this->assertNotNull( $post );
		$this->assertSame( ChapterCPT::POST_TYPE, $post->post_type );
		$this->assertSame( '測試章節', $post->post_title );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new ChapterCreateTool();
		$result = $tool->run( [ 'post_title' => 'X' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 post_title 回傳 422
	 *
	 * @group smoke
	 */
	public function test_missing_post_title_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterCreateTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
