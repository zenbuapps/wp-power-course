<?php
/**
 * ChapterToggleFinishTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\Settings;
use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterToggleFinishTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterToggleFinishToolTest
 */
class ChapterToggleFinishToolTest extends IntegrationTestCase {

	/**
	 * 設定（每個測試前執行）— 預設開啟「允許修改」，
	 * 因為 Issue #217 後 toggle_finish 屬於 OP_UPDATE，需要 settings 開啟才會被允許
	 */
	public function set_up(): void {
		parent::set_up();
		( new Settings() )->set_update_allowed( true );
	}

	/**
	 * 測試：toggle_finish 應被分類為 OP_UPDATE（會寫入學員進度資料）
	 *
	 * @group smoke
	 */
	public function test_toggle_finish_classified_as_op_update(): void {
		$tool = new ChapterToggleFinishTool();
		$this->assertSame(
			AbstractTool::OP_UPDATE,
			$tool->get_operation_type(),
			'chapter_toggle_finish 會寫入 pc_avl_chaptermeta，必須是 OP_UPDATE'
		);
	}

	/**
	 * 測試：當 allow_update 為 false 時，toggle_finish 應被拒絕
	 *
	 * @group security
	 */
	public function test_toggle_finish_blocked_when_allow_update_false(): void {
		$this->create_admin_user();
		( new Settings() )->set_update_allowed( false );

		$tool   = new ChapterToggleFinishTool();
		$result = $tool->run(
			[
				'chapter_id'  => 1,
				'is_finished' => true,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_operation_not_allowed', $result->get_error_code() );
	}

	/**
	 * 建立一個關聯到 WC 課程產品的章節
	 *
	 * @return array{chapter_id: int, course_id: int}
	 */
	private function make_chapter_with_course(): array {
		// 建 WC 產品當課程
		$product = new \WC_Product_Simple();
		$product->set_name( '課程 X' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		$chapter_id = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '測試單元',
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
	 * happy：管理員能將自己的章節標記為完成
	 *
	 * @group happy
	 */
	public function test_admin_can_toggle_finish_self(): void {
		$user_id = $this->create_admin_user();

		[ 'chapter_id' => $chapter_id ] = $this->make_chapter_with_course();

		$tool   = new ChapterToggleFinishTool();
		$result = $tool->run(
			[
				'chapter_id'  => $chapter_id,
				'is_finished' => true,
			]
		);

		$this->assertIsArray( $result, '應回傳陣列，實際為 ' . print_r( $result, true ) );
		$this->assertTrue( $result['success'] );
		$this->assertSame( $chapter_id, $result['chapter_id'] );
		$this->assertSame( $user_id, $result['user_id'] );
		$this->assertTrue( $result['is_finished'] );
	}

	/**
	 * 權限不足：訪客被拒
	 *
	 * @group security
	 */
	public function test_guest_is_denied(): void {
		$this->set_guest_user();

		$tool   = new ChapterToggleFinishTool();
		$result = $tool->run(
			[
				'chapter_id'  => 1,
				'is_finished' => true,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 權限檢查：非管理員試圖修改他人進度 → 403
	 *
	 * @group security
	 */
	public function test_subscriber_cannot_toggle_other_user(): void {
		$other_user = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->create_subscriber_user();

		[ 'chapter_id' => $chapter_id ] = $this->make_chapter_with_course();

		$tool   = new ChapterToggleFinishTool();
		$result = $tool->run(
			[
				'chapter_id'  => $chapter_id,
				'user_id'     => $other_user,
				'is_finished' => true,
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：缺 is_finished 回傳 422
	 *
	 * @group smoke
	 */
	public function test_missing_is_finished_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterToggleFinishTool();
		$result = $tool->run( [ 'chapter_id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
