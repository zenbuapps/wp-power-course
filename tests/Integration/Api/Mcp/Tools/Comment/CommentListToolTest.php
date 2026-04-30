<?php
/**
 * MCP Tool — CommentListTool 整合測試
 *
 * @group mcp
 * @group comment
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\Tools\Comment\CommentListTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CommentListToolTest
 * 驗證 comment_list tool 的 happy path、權限、schema
 */
class CommentListToolTest extends IntegrationTestCase {

	/**
	 * happy path：管理員可列出某 post 的留言
	 *
	 * @group happy
	 */
	public function test_admin_can_list_comments(): void {
		$this->create_admin_user();

		$post_id = $this->factory()->post->create( [ 'post_status' => 'publish' ] );

		// 建立三筆已核准留言
		for ( $i = 0; $i < 3; $i++ ) {
			$this->factory()->comment->create(
				[
					'comment_post_ID'  => $post_id,
					'comment_approved' => '1',
					'comment_type'     => 'comment',
					'comment_content'  => "測試留言 {$i}",
				]
			);
		}

		$tool   = new CommentListTool();
		$result = $tool->run(
			[
				'post_id' => $post_id,
				'type'    => 'comment',
				'status'  => 'all',
				'number'  => 10,
				'paged'   => 1,
			]
		);

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'comments', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 3, $result['comments'] );
	}

	/**
	 * 權限：訂閱者（不具 moderate_comments）應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new CommentListTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema：input schema 包含 post_id、type、status 等核心欄位
	 *
	 * @group smoke
	 */
	public function test_input_schema_has_core_fields(): void {
		$tool   = new CommentListTool();
		$schema = $tool->get_input_schema();

		$this->assert_schema_has_property( $schema, 'post_id' );
		$this->assert_schema_has_property( $schema, 'type' );
		$this->assert_schema_has_property( $schema, 'status' );
		$this->assert_schema_has_property( $schema, 'paged' );
		$this->assert_schema_has_property( $schema, 'number' );

		// 靜態設定檢查
		$this->assertSame( 'comment', $tool->get_category() );
		$this->assertSame( 'moderate_comments', $tool->get_capability() );
		$this->assertSame( 'power-course/comment-list', $tool->get_ability_name() );
	}
}
