<?php
/**
 * 作業批改通知信 動態變數替換 整合測試（Issue #131 第一波 P5）
 *
 * Feature: specs/features/email/觸發自動郵件.feature
 *   Rule: 後置（狀態）- 新增觸發時機 assignment_graded：管理員批改作業為 pass 或 fail 時寄給繳交學員，
 *         郵件可使用作業變數
 *   Rule: 後置（狀態）- 新增觸發時機 assignment_due_soon：{assignment_due_at} 以站台時區格式化
 *         （due_soon 完整排程流程是 P6 範圍，見計劃 §9 P6；本檔只驗證 {assignment_due_at} 這個
 *         Replace 變數本身的格式，改用 P5 已就緒的 assignment_graded 流程夾帶同一個變數來驗證，
 *         不等 P6 的 Service\DueReminder 才能測——test-creator 決定，見下方測試方法內的說明）
 *
 * ─── 流程 ─────────────────────────────────────────────
 * 批改（REST PATCH /power-course/assignment-submissions/{id}/grade）
 *   → run_scheduled_actions('power_email_send_assignment_graded')
 *   → capture_mail()
 * ────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentEmailReplaceTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentEmailReplaceTest extends AssignmentTestCase {

	/** 作業批改通知信對應的 Action Scheduler hook */
	private const GRADED_HOOK = 'power_email_send_assignment_graded';

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice（display_name = Alice，已加入課程 100） */
	private int $alice_id;

	/** @var string 學員 Alice 的 email */
	private string $alice_email;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 500（練習一：變數宣告） */
	private int $assignment_id;

	/** @var int 繳交 600（作業 500，Alice，pending） */
	private int $submission_id;

	/**
	 * 建立 Background：Admin/Alice（display_name=Alice）、課程 100、章節 201、
	 * 作業「練習一：變數宣告」、Alice 已加入課程 100、繳交（pending）、
	 * 現在時間 2026-09-17 10:00:00、開始攔截寄信
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_email_replace_' . \uniqid(),
				'user_email' => 'admin_email_replace_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_email = 'alice_email_replace_' . \uniqid() . '@test.com';
		$this->alice_id     = $this->factory()->user->create(
			[
				'user_login'   => 'alice_email_replace_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email'   => $this->alice_email,
				'role'         => 'subscriber',
			]
		);

		$this->course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$this->assignment_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習一：變數宣告',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'menu_order' => 0,
			]
		);

		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$this->submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'attachment_ids' => [],
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);

		$this->set_now( '2026-09-17 10:00:00' );
		$this->capture_mail();
	}

	/**
	 * 批改請求
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param array<string, mixed> $params        status / feedback
	 * @return \WP_REST_Response
	 */
	private function grade( int $submission_id, array $params ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'PATCH', '/power-course/assignment-submissions/' . $submission_id . '/grade', $params );
	}

	// ========== 動態內容替換：作業變數 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 新增觸發時機 assignment_graded：管理員批改作業為 pass 或 fail 時寄給繳交學員，
	 *       郵件可使用作業變數
	 * Example: 批改通過時寄出含作業資訊的郵件
	 *
	 * feature 字面內容：「Alice 您的作業「練習一：變數宣告」批改結果：通過。寫得很好」
	 * {display_name} 由既有 Replace\User 負責（不在本階段驗證範圍）；
	 * {assignment_title}/{assignment_status}/{assignment_feedback} 為 P5 新增的 Replace\Assignment 變數。
	 * {assignment_status} 的中譯沿用 P4 已完成的 Utils::status_label()：pass → 「通過」。
	 */
	public function test_批改通過時寄出含作業變數的郵件內容(): void {
		$this->create_email_template(
			[
				'post_title'   => '作業批改結果通知',
				'post_content' => '{display_name} 您的作業「{assignment_title}」批改結果：{assignment_status}。{assignment_feedback}',
				'course_ids'   => [ $this->course_id ],
			]
		);

		$response = $this->grade( $this->submission_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assert_mail_sent_to( $this->alice_email );
		$this->assert_mail_content_contains( 'Alice 您的作業「練習一：變數宣告」批改結果：通過。寫得很好' );
	}

	// ========== 動態內容替換：{assignment_due_at} 格式（站台時區） ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 新增觸發時機 assignment_due_soon：{assignment_due_at} 以站台時區格式化
	 *
	 * test-creator 決定：feature 對 {assignment_due_at} 格式的 Example 掛在 assignment_due_soon
	 * （P6 排程掃描，Service\DueReminder 尚未實作）之下，但 {assignment_due_at} 本身是 Replace\Assignment
	 * 這個 P5 產物提供的共用變數（比對 Replace\Course 的變數同時服務 course_granted/course_finish/
	 * course_launch 多個 trigger 的既有先例）。本測試改用 P5 已就緒的 assignment_graded 流程，
	 * 驗證同一個 {assignment_due_at} 變數格式化是否正確（Y-m-d H:i，站台時區），
	 * 不需要等 P6 的每日掃描才能驗證這個 Replace 變數本身的行為。
	 * due_at 沿用 feature（email/觸發自動郵件.feature）due_soon Example 的字面 timestamp 1789747140，
	 * 對應站台時區（Asia\/Taipei）「2026-09-18 23:59」，與 feature 文字互相佐證。
	 */
	public function test_assignment_due_at格式為站台時區YmdHi(): void {
		$due_timestamp = 1789747140;

		$assignment_with_due_at = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '練習一：變數宣告（含截止時間）',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'due_at'     => $due_timestamp,
				'menu_order' => 20,
			]
		);

		$submission_with_due_at = $this->seed_submission(
			[
				'assignment_id' => $assignment_with_due_at,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let y = 2;',
				'attachment_ids' => [],
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);

		$this->create_email_template(
			[
				'post_title'   => '作業批改結果通知（含截止時間變數）',
				'post_content' => '「{assignment_title}」截止時間為 {assignment_due_at}',
				'course_ids'   => [ $this->course_id ],
			]
		);

		$response = $this->grade( $submission_with_due_at, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$expected_due_at = \wp_date( 'Y-m-d H:i', $due_timestamp );
		$this->assertSame( '2026-09-18 23:59', $expected_due_at, '前置條件不成立：站台時區換算後的期望值應為 2026-09-18 23:59（與 email/觸發自動郵件.feature 的 due_soon Example 一致）' );

		$this->assert_mail_content_contains( '截止時間為 ' . $expected_due_at );
	}
}
