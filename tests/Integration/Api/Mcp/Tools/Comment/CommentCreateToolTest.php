<?php
/**
 * MCP Tool — CommentCreateTool 整合測試
 *
 * @group mcp
 * @group comment
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Comment;

use J7\PowerCourse\Api\Mcp\Tools\Comment\CommentCreateTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CommentCreateToolTest
 * 驗證 comment_create tool 的 happy path、權限、schema
 */
class CommentCreateToolTest extends IntegrationTestCase {

	/**
	 * 建立一個可接受 comment 的 WC 商品
	 *
	 * @return int product_id
	 */
	private function make_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( '測試商品' );
		$product->set_status( 'publish' );
		$product->set_reviews_allowed( true );
		return $product->save();
	}

	/**
	 * happy path：登入者可對商品發表 comment 類型留言
	 *
	 * @group happy
	 */
	public function test_logged_in_user_can_create_comment(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		\wp_set_current_user( $user_id );

		$product_id = $this->make_product();

		$tool   = new CommentCreateTool();
		$result = $tool->run(
			[
				'chapter_id'   => $product_id,
				'content'      => '這堂課很棒',
				'comment_type' => 'comment',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['code'] );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertNotEmpty( $result['data']['id'] ?? null );
	}

	/**
	 * 權限：訪客（未登入）應被拒絕
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new CommentCreateTool();
		$result = $tool->run(
			[
				'chapter_id' => 1,
				'content'    => 'hi',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 代他人發言：非管理員嘗試以其他 user_id 發言應被拒絕
	 *
	 * @group security
	 */
	public function test_non_admin_cannot_impersonate_another_user(): void {
		$self_id  = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$other_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		\wp_set_current_user( $self_id );

		$product_id = $this->make_product();

		$tool   = new CommentCreateTool();
		$result = $tool->run(
			[
				'chapter_id' => $product_id,
				'content'    => '代他人發言',
				'user_id'    => $other_id,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema：必要欄位完整
	 *
	 * @group smoke
	 */
	public function test_schema_and_metadata(): void {
		$tool   = new CommentCreateTool();
		$schema = $tool->get_input_schema();

		$this->assert_schema_has_property( $schema, 'chapter_id' );
		$this->assert_schema_has_property( $schema, 'content' );
		$this->assert_schema_has_property( $schema, 'user_id' );
		$this->assert_schema_has_property( $schema, 'comment_type' );

		$this->assertSame( 'comment', $tool->get_category() );
		$this->assertSame( 'read', $tool->get_capability() );
		$this->assertSame( 'power-course/comment-create', $tool->get_ability_name() );
	}
}
