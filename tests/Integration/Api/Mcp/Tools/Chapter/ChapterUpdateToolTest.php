<?php
/**
 * ChapterUpdateTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterUpdateTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterUpdateToolTest
 */
class ChapterUpdateToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能更新章節標題
	 *
	 * @group happy
	 */
	public function test_admin_can_update_chapter_title(): void {
		$this->create_admin_user();

		$chapter_id = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '舊標題',
				'post_status' => 'publish',
			]
		);

		$tool   = new ChapterUpdateTool();
		$result = $tool->run(
			[
				'chapter_id' => $chapter_id,
				'post_title' => '新標題',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $chapter_id, $result['chapter_id'] );

		$post = \get_post( $chapter_id );
		$this->assertNotNull( $post );
		$this->assertSame( '新標題', $post->post_title );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new ChapterUpdateTool();
		$result = $tool->run(
			[
				'chapter_id' => 1,
				'post_title' => 'X',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 chapter_id 回傳 422
	 *
	 * @group smoke
	 */
	public function test_missing_chapter_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterUpdateTool();
		$result = $tool->run( [ 'post_title' => 'X' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
