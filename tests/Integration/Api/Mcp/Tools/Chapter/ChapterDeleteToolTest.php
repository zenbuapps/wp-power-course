<?php
/**
 * ChapterDeleteTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterDeleteTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterDeleteToolTest
 */
class ChapterDeleteToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能刪除章節（進垃圾桶）
	 *
	 * @group happy
	 */
	public function test_admin_can_delete_chapter(): void {
		$this->create_admin_user();

		$chapter_id = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '待刪除',
				'post_status' => 'publish',
			]
		);

		$tool   = new ChapterDeleteTool();
		$result = $tool->run( [ 'chapter_id' => $chapter_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $chapter_id, $result['chapter_id'] );

		$this->assertSame( 'trash', \get_post_status( $chapter_id ) );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new ChapterDeleteTool();
		$result = $tool->run( [ 'chapter_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 chapter_id
	 *
	 * @group smoke
	 */
	public function test_missing_chapter_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterDeleteTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
