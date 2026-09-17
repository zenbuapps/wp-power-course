<?php
/**
 * 章節生命週期連動作業 整合測試（Issue #131 第一波 P2）
 *
 * Feature: specs/features/chapter/刪除章節.feature（「章節作業連動」3 條 Rule）
 * 額外 1 條：課程還原時作業跟著還原（DEC-06、R-10，無直接對應 .feature Example，
 * 依計劃 §6.5「pc_chapter trash → 非 trash」流程圖：課程還原走 wp_update_post 把章節
 * 改回 publish，不會觸發 wp_untrash_post，須靠 transition_post_status 才能連動）
 *
 * 依據：計劃 §9 P2、§4 DEC-06、§6.5、§12 R-10/R-11
 *
 * ─── 本層「能驗什麼、不能驗什麼」────────────────────────────
 * 第 1 條透過真實的 DELETE /power-course/chapters/{id} 觸發既有的章節連帶刪除子章節
 * 機制（該機制本身已存在且測試中），驗證的是「新掛上的 Assignment\Core\LifeCycle
 * 是否也隨每個章節各自的 publish→trash transition 連動」。
 * 第 2、4 條刻意繞過 REST，直接呼叫 wp_untrash_post() / wp_update_post()，
 * 對齊 §6.5 流程圖「涵蓋 wp_untrash_post 與課程還原的 wp_update_post」兩種進入點。
 * ──────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class ChapterAssignmentCascadeTest
 *
 * @group assignment
 * @group issue-131
 */
class ChapterAssignmentCascadeTest extends AssignmentTestCase {

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/**
	 * 建立 Background：管理員、課程 100
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_cac_' . \uniqid(),
				'user_email' => 'admin_cac_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: chapter/刪除章節.feature
	 * Rule: 後置（狀態）- 章節移至垃圾桶時，其下（含子章節）的作業一併移至垃圾桶，學員繳交紀錄保留
	 * Example: 刪除父章節後子章節的作業也進入垃圾桶
	 */
	public function test_刪除父章節後子章節的作業也進入垃圾桶(): void {
		$chapter_200_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$chapter_210_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '1-1 小節',
				'post_parent' => $chapter_200_id,
			]
		);
		$chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第二章' ] );

		$assignment_400_id = $this->create_assignment( $chapter_210_id, [ 'title' => '練習一：變數宣告' ] );
		$assignment_401_id = $this->create_assignment( $chapter_201_id, [ 'title' => '第二章練習' ] );

		$submission_id = $this->seed_submission(
			[
				'assignment_id'  => $assignment_400_id,
				'user_id'        => $this->admin_id,
				'text_body'      => 'let x = 1;',
				'attachment_ids' => [],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);

		$response = $this->dispatch( 'DELETE', '/power-course/chapters/' . $chapter_200_id );
		$this->assert_success_response( $response );

		$this->assert_assignment_fields( $assignment_400_id, [ 'post_status' => 'trash' ] );
		$this->assert_assignment_fields( $assignment_401_id, [ 'post_status' => 'publish' ] );
		$this->assert_submission_exists(
			$submission_id,
			[
				'assignment_id' => $assignment_400_id,
				'status'        => 'pending',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: chapter/刪除章節.feature
	 * Rule: 後置（狀態）- 章節自垃圾桶還原時，一併還原其作業
	 * Example: 還原章節 201 後作業恢復發佈
	 */
	public function test_還原章節201後作業恢復發佈(): void {
		$chapter_201_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '第二章',
				'post_status' => 'trash',
			]
		);
		$assignment_401_id = $this->create_assignment(
			$chapter_201_id,
			[
				'title'       => '第二章練習',
				'post_status' => 'trash',
			]
		);

		\wp_untrash_post( $chapter_201_id );

		$this->assert_assignment_fields( $assignment_401_id, [ 'post_status' => 'publish' ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: chapter/刪除章節.feature
	 * Rule: 後置（狀態）- 章節被永久刪除時，其作業與作業的繳交紀錄、討論訊息一併刪除
	 * Example: 永久刪除章節 201
	 */
	public function test_永久刪除章節201(): void {
		$chapter_201_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '第二章',
				'post_status' => 'trash',
			]
		);
		$assignment_401_id = $this->create_assignment(
			$chapter_201_id,
			[
				'title'       => '第二章練習',
				'post_status' => 'trash',
			]
		);
		$submission_id = $this->seed_submission(
			[
				'assignment_id'  => $assignment_401_id,
				'user_id'        => $this->admin_id,
				'text_body'      => '心得',
				'attachment_ids' => [],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);

		\wp_delete_post( $chapter_201_id, true );

		$this->assert_assignment_not_exists( $assignment_401_id );
		$this->assert_submission_not_exists( $submission_id );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * DEC-06、R-10：課程還原走 wp_update_post 把章節改回 publish，不會觸發 wp_untrash_post，
	 * 須靠 transition_post_status（僅 pc_chapter）連動作業還原
	 * 無直接對應 .feature Example（計劃 §6.5 流程圖明文要求覆蓋此進入點）
	 */
	public function test_課程還原時作業跟著還原(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '第二章',
				'post_status' => 'trash',
			]
		);
		$assignment_id = $this->create_assignment(
			$chapter_id,
			[
				'title'       => '第二章練習',
				'post_status' => 'trash',
			]
		);

		// 模擬課程還原：直接 wp_update_post 把章節改回 publish（不經 wp_untrash_post）
		\wp_update_post(
			[
				'ID'          => $chapter_id,
				'post_status' => 'publish',
			]
		);

		$this->assert_assignment_fields( $assignment_id, [ 'post_status' => 'publish' ] );
	}
}
