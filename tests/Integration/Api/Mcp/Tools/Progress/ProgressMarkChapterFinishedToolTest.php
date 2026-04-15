<?php
/**
 * ProgressMarkChapterFinishedTool 整合測試
 *
 * @group mcp
 * @group progress
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\Tools\Progress\ProgressMarkChapterFinishedTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ProgressMarkChapterFinishedToolTest
 */
class ProgressMarkChapterFinishedToolTest extends IntegrationTestCase {

	/**
	 * 建立關聯到 WC 課程產品的章節
	 *
	 * @return array{chapter_id: int, course_id: int}
	 */
	private function make_chapter_with_course(): array {
		$product = new \WC_Product_Simple();
		$product->set_name( '課程 Mark' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		$chapter_id = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '測試章節 Mark',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $chapter_id, 'parent_course_id', $product_id );

		return [
			'chapter_id' => (int) $chapter_id,
			'course_id'  => (int) $product_id,
		];
	}

	/**
	 * happy：管理員能將自己的章節標記為完成（is_finished 預設 true）
	 *
	 * @group happy
	 */
	public function test_admin_can_mark_self_chapter_finished(): void {
		$user_id = $this->create_admin_user();

		[ 'chapter_id' => $chapter_id ] = $this->make_chapter_with_course();

		$tool   = new ProgressMarkChapterFinishedTool();
		$result = $tool->run( [ 'chapter_id' => $chapter_id ] );

		$this->assertIsArray( $result, '應回傳陣列，實際為 ' . print_r( $result, true ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $chapter_id, $result['chapter_id'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertTrue( $result['is_finished'] );
	}

	/**
	 * happy：明確指定 is_finished = false 可取消完成狀態
	 *
	 * @group happy
	 */
	public function test_admin_can_explicitly_unmark_chapter(): void {
		$user_id = $this->create_admin_user();

		[ 'chapter_id' => $chapter_id ] = $this->make_chapter_with_course();

		// 先標記完成
		$tool = new ProgressMarkChapterFinishedTool();
		$tool->run(
			[
				'chapter_id'  => $chapter_id,
				'is_finished' => true,
			]
		);

		// 再取消
		$result = $tool->run(
			[
				'chapter_id'  => $chapter_id,
				'is_finished' => false,
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertFalse( $result['is_finished'] );
	}

	/**
	 * security：subscriber 試圖修改他人進度 → 403
	 *
	 * @group security
	 */
	public function test_subscriber_cannot_mark_other_user(): void {
		$other_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscriber_user();

		[ 'chapter_id' => $chapter_id ] = $this->make_chapter_with_course();

		$tool   = new ProgressMarkChapterFinishedTool();
		$result = $tool->run(
			[
				'chapter_id' => $chapter_id,
				'user_id'    => $other_user,
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

		$tool   = new ProgressMarkChapterFinishedTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
