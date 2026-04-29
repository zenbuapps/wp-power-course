<?php
/**
 * MCP Tool — CommentToggleApprovedTool 整合測試
 *
 * @group mcp
 * @group comment
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\Tools\Comment\CommentToggleApprovedTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CommentToggleApprovedToolTest
 * 驗證 comment_toggle_approved tool 的 happy path、權限、schema
 */
class CommentToggleApprovedToolTest extends IntegrationTestCase {

	/**
	 * happy path：管理員可切換留言審核狀態（1 → 0）
	 *
	 * @group happy
	 */
	public function test_admin_can_toggle_approved(): void {
		$this->create_admin_user();

		$post_id    = $this->factory()->post->create( [ 'post_status' => 'publish' ] );
		$comment_id = $this->factory()->comment->create(
			[
				'comment_post_ID'  => $post_id,
				'comment_approved' => '1',
				'comment_type'     => 'comment',
				'comment_content'  => '要被切換的留言',
			]
		);

		$tool   = new CommentToggleApprovedTool();
		$result = $tool->run( [ 'comment_id' => $comment_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['code'], '預期切換成功' );

		$refreshed = \get_comment( $comment_id );
		$this->assertInstanceOf( \WP_Comment::class, $refreshed );
		$this->assertSame( '0', $refreshed->comment_approved, '原本 approved=1 應被切換為 0' );
	}

	/**
	 * 權限：訂閱者（不具 moderate_comments）應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new CommentToggleApprovedTool();
		$result = $tool->run( [ 'comment_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema：comment_id 為 required
	 *
	 * @group smoke
	 */
	public function test_schema_requires_comment_id(): void {
		$tool   = new CommentToggleApprovedTool();
		$schema = $tool->get_input_schema();

		$this->assert_schema_has_property( $schema, 'comment_id' );
		$this->assertArrayHasKey( 'required', $schema );
		$this->assertContains( 'comment_id', $schema['required'] );

		$this->assertSame( 'comment', $tool->get_category() );
		$this->assertSame( 'moderate_comments', $tool->get_capability() );
		$this->assertSame( 'power-course/comment-toggle-approved', $tool->get_ability_name() );
	}

	/**
	 * 參數驗證：缺少 comment_id 應回傳 WP_Error
	 *
	 * @group smoke
	 */
	public function test_missing_comment_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new CommentToggleApprovedTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
