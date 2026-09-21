<?php
/**
 * 移除學員課程權限時保留作業繳交紀錄 整合測試（Issue #131 第一波 P3）
 *
 * Feature: specs/features/student/移除學員課程權限.feature
 *          Rule「後置（狀態）- 移除學員課程權限時保留其作業繳交紀錄」
 * 依據：計劃 §9 P3「RemoveStudentKeepsSubmissionsTest.php（很可能實作前就綠，見回報回歸守門清單）」
 *
 * ─── 為什麼很可能是回歸守門 ─────────────────────────────────────
 * 退課動作觸發 J7\PowerCourse\Resources\Course\LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION
 * hook（見 tests/Integration/ChapterProgress/RemoveStudentCleanupTest.php 的既有寫法）。
 * 這條 hook 目前只清 avl_course_ids / chaptermeta 相關資料，完全不觸碰
 * pc_assignment_submissions 表；本 PR（P1-P3）也沒有任何程式碼把這條 hook 接去刪繳交紀錄。
 * 也就是說「移除學員課程權限不影響其作業繳交紀錄」這件事，在 P3 Green 完成前就已經是真的
 * （沒有任何東西會去刪它）。寫這條測試的目的是**未來若有人手滑把刪除邏輯接上這個 hook，
 * 這條測試會立刻變紅**，屬於防呆的回歸守門測試，不是本 PR 新增的行為。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class RemoveStudentKeepsSubmissionsTest
 *
 * @group assignment
 * @group issue-131
 */
class RemoveStudentKeepsSubmissionsTest extends AssignmentTestCase {

	/** @var int Alice */
	private int $alice_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_id;

	/** @var int 作業 400（練習一：變數宣告） */
	private int $assignment_id;

	/**
	 * 建立 Background：Alice、課程 100、章節 201、作業 400、Alice 已加入課程 100
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_rsks_' . \uniqid(),
				'user_email' => 'alice_rsks_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id    = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_id   = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );
		$this->assignment_id = $this->create_assignment( $this->chapter_id, [ 'title' => '練習一：變數宣告' ] );

		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 移除學員課程權限時保留其作業繳交紀錄
	 * Example: 移除 Alice 後繳交紀錄仍在
	 */
	public function test_移除Alice後繳交紀錄仍在(): void {
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => 1,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);

		try {
			\do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assert_submission_exists(
			$submission_id,
			[
				'status'   => 'pass',
				'feedback' => '寫得很好',
			]
		);
	}
}
