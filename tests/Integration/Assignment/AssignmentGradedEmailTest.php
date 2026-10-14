<?php
/**
 * 作業批改通知信（PowerEmail 觸發） 整合測試（Issue #131 第一波 P5）
 *
 * Feature: specs/features/assignment/批改作業.feature（寄信 3 條 Rule）
 * 依據：計劃 §9 P5、§4 DEC-10、§12 R-09 / R-23 / R-25
 *
 * ─── 流程 ─────────────────────────────────────────────
 * 批改（REST PATCH /power-course/assignment-submissions/{id}/grade）
 *   → run_scheduled_actions('power_email_send_assignment_graded')（強制執行 pending action）
 *   → capture_mail()（pre_wp_mail 攔截，不真的寄信）
 * ────────────────────────────────────────────────────────
 *
 * ─── DEC-10（orchestrator 裁定，本階段驗收依據）─────────────
 * 1. 作業信件一律立即寄送，不套用郵件模板的「延遲寄送」設定（同 access_pass_expiring 的既有做法）。
 * 2. 模板的 condition.course_ids 有值時只寄該課程的作業；course_ids 為空代表全部課程都寄。
 * 3. identifier ids = [submission_id, graded_at 的 timestamp]——每次批改（含改判）各寄一次通知的關鍵。
 * ────────────────────────────────────────────────────────
 *
 * ─── 回歸守門聲明（S-RED-PHP 規則 13）───────────────────────
 * 本檔 3 個「不應寄送」測試（pending 不寄 / course_ids 不含不寄 / 草稿模板不寄）在 Green 實作前
 * 就會是綠燈：目前 At.php 完全沒有監聽 power_course_assignment_graded，批改後不論任何條件都
 * 不會排程寄信，captured_mails 必然為空。這 3 條在 Green 實作後轉為守住「不誤寄」的回歸測試。
 * ────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;

/**
 * Class AssignmentGradedEmailTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentGradedEmailTest extends AssignmentTestCase {

	/** 作業批改通知信對應的 Action Scheduler hook（AtHelper::ASSIGNMENT_GRADED 的衍生 hook） */
	private const GRADED_HOOK = 'power_email_send_assignment_graded';

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice（已加入課程 100） */
	private int $alice_id;

	/** @var string 學員 Alice 的 email（動態產生，斷言收件人時使用） */
	private string $alice_email;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告） */
	private int $assignment_400_id;

	/** @var int 繳交 500（作業 400，Alice，pending） */
	private int $submission_500_id;

	/**
	 * 建立 Background：Admin/Alice、課程 100、章節 201、作業 400、
	 * Alice 已加入課程 100、繳交 500（pending）、現在時間 2026-09-17 10:00:00、開始攔截寄信
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_graded_email_' . \uniqid(),
				'user_email' => 'admin_graded_email_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_email = 'alice_graded_email_' . \uniqid() . '@test.com';
		$this->alice_id     = $this->factory()->user->create(
			[
				'user_login'   => 'alice_graded_email_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email'   => $this->alice_email,
				'role'         => 'subscriber',
			]
		);

		$this->course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$this->assignment_400_id = $this->create_assignment(
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

		$this->submission_500_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
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

	// ========== 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded 郵件；改回 pending 時不寄送 ==========

	/**
	 * @test
	 * @group happy
	 * @dataProvider provider_pass或fail
	 *
	 * Rule: 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded 郵件；改回 pending 時不寄送
	 * Example: 批改為 <status> 時寄送批改通知
	 *
	 * @param string $status pass | fail
	 */
	public function test_批改為pass或fail時寄送批改通知( string $status ): void {
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'course_ids' => [], // DEC-10：course_ids 為空代表全部課程都寄
			]
		);

		$response = $this->grade( $this->submission_500_id, [ 'status' => $status, 'feedback' => '評語' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assert_mail_sent_to( $this->alice_email );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provider_pass或fail(): array {
		return [
			'pass' => [ 'pass' ],
			'fail' => [ 'fail' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded 郵件；改回 pending 時不寄送
	 * Example: 改回待批改時不寄送批改通知
	 *
	 * 回歸守門（S-RED-PHP 規則 13）：Green 實作前，At.php 尚未監聽 power_course_assignment_graded，
	 * 任何 status 批改後都不會寄信，本測試現在就是綠燈。Green 實作後轉為守住「pending 不誤寄」的回歸測試。
	 */
	public function test_改回pending時不寄送批改通知(): void {
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'course_ids' => [],
			]
		);

		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pending' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertSame( [], $this->captured_mails, '改回待批改時不應寄送任何郵件' );
	}

	// ========== 後置（狀態）- 每次批改（含改判）各寄一次批改通知 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 每次批改（含改判）各寄一次批改通知
	 * Example: 同一繳交第二次批改仍寄送通知
	 *
	 * 先寫入 identifier 含「舊 graded_at」的 EmailRecord（模擬上一次批改已寄過通知），
	 * 證明去重是以「該次批改」（graded_at）為單位，而不是以 submission_id 本身——
	 * DEC-10：identifier ids = [submission_id, graded_at 的 timestamp]。
	 * identifier 字串透過真實的 Email::get_identifier() 產生，不自行猜測字串格式。
	 *
	 * 用另一個作業（練習二）承載這筆繳交，而非沿用 set_up() 的 assignment_400_id：
	 * pc_assignment_submissions 對 (assignment_id, user_id) 有 UNIQUE KEY
	 * （uq_assignment_submissions_owner），assignment_400 + alice 這組已被 set_up() 的
	 * submission_500 佔用，同組合再 seed 一筆會撞唯一鍵、insert 靜默失敗（對齊 feature
	 * 本身也是用作業 401 承載這個 Example 的繳交 502，而非沿用作業 400 的繳交 500）。
	 */
	public function test_同一繳交第二次批改仍寄送通知(): void {
		$email_id = $this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'course_ids' => [],
			]
		);

		$assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
				'menu_order'    => 10,
			]
		);

		$old_graded_at = '2026-09-16 10:00:00';
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'attachment_ids' => [],
				'status'        => 'fail',
				'feedback'      => '再補充',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => $old_graded_at,
			]
		);
		$this->assertGreaterThan( 0, $submission_id, '前置條件不成立：seed_submission() 應成功寫入一筆新繳交（未撞到 (assignment_id,user_id) 唯一鍵）' );

		$old_timestamp  = ( new \DateTimeImmutable( $old_graded_at, \wp_timezone() ) )->getTimestamp();
		$email          = new Email( $email_id );
		$old_identifier = $email->get_identifier( [ $submission_id, $old_timestamp ], $this->alice_id );

		$recorded = EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$email_id,
			'作業批改結果通知',
			'assignment_graded',
			$old_identifier,
			true
		);
		$this->assertNotFalse( $recorded, '前置條件不成立：無法寫入模擬「上一次已寄過」的 EmailRecord' );

		$response = $this->grade( $submission_id, [ 'status' => 'fail', 'feedback' => '截圖仍不清楚' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assert_mail_sent_to( $this->alice_email );
	}

	// ========== DEC-10：模板 course_ids 範圍 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 模板的 condition.course_ids 有值時只寄該課程的作業（DEC-10）
	 *
	 * 回歸守門（S-RED-PHP 規則 13）：理由同 test_改回pending時不寄送批改通知——
	 * Green 實作前批改後一律不寄信，本測試現在就是綠燈，Green 實作後轉為守住課程範圍過濾的回歸測試。
	 */
	public function test_模板course_ids不含該課程時不寄(): void {
		$other_course_id = $this->create_course( [ 'post_title' => '其他課程（不含作業 400 的課程）' ] );

		$this->create_email_template(
			[
				'post_title' => '只寄其他課程的作業通知',
				'course_ids' => [ $other_course_id ],
			]
		);

		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertSame( [], $this->captured_mails, 'course_ids 不含作業所屬課程時不應寄送' );
	}

	// ========== 草稿模板 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 草稿模板不寄
	 *
	 * 回歸守門（S-RED-PHP 規則 13）：理由同上——Green 實作前批改後一律不寄信，本測試現在就是綠燈，
	 * Green 實作後轉為守住「排信查詢只撈 post_status=publish」的回歸測試
	 * （對齊 At::schedule_email() 既有的 get_posts( post_status: 'publish' ) 查詢條件）。
	 */
	public function test_草稿模板不寄(): void {
		$this->create_email_template(
			[
				'post_title'  => '草稿中的批改通知',
				'course_ids'  => [],
				'post_status' => 'draft',
			]
		);

		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		$this->assert_success_response( $response );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertSame( [], $this->captured_mails, '草稿模板不應寄送' );
	}

	// ========== DEC-10：立即寄送，不套用模板延遲寄送設定 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 作業信件一律立即寄送，不套用郵件模板的「延遲寄送」設定（DEC-10，orchestrator 裁定）
	 *
	 * 模板 condition.sending 設定為「延遲 30 天寄送」，若 Green 誤用既有 At::schedule_email()
	 * （會呼叫 Email::get_sending_timestamp() 套用模板延遲設定）而非比照 access_pass_expiring
	 * 的做法固定立即排程，這裡會抓到：排程時間會被推到 30 天後而非現在。
	 *
	 * 不能用 run_scheduled_actions()（會無視排定時間強制執行所有 pending action）驗證這件事，
	 * 改用 get_next_scheduled_action_timestamp() 直接讀出 Action Scheduler 排定的目標時間點。
	 *
	 * test-creator 決定：本條為 orchestrator 傳達的 DEC-10 決定 1 的直接驗證，
	 * feature 檔本身未列出「延遲設定被忽略」的獨立 Example，故標記為 test-creator 決定。
	 */
	public function test_批改通過時忽略模板延遲寄送設定_立即寄出(): void {
		$this->create_email_template(
			[
				'post_title' => '延遲寄送模板（應被作業信件忽略）',
				'course_ids' => [],
				'sending'    => [
					'type'  => 'send_later',
					'value' => '30',
					'unit'  => 'day',
					'range' => null,
				],
			]
		);

		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		$this->assert_success_response( $response );

		$due_timestamp = $this->get_next_scheduled_action_timestamp( self::GRADED_HOOK );

		$this->assertNotNull( $due_timestamp, '批改為 pass 後應立即排入 ' . self::GRADED_HOOK . ' 的寄送任務' );
		$this->assertLessThanOrEqual(
			\time() + 5,
			$due_timestamp,
			'作業批改通知一律立即寄送，不得套用模板的延遲寄送設定（DEC-10），' .
			'實際排定時間過晚，疑似誤套用了 30 天延遲設定'
		);
	}
}
