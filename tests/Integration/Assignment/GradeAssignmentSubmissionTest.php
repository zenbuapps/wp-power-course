<?php
/**
 * 批改作業（寄信以外的 Rule） 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/批改作業.feature（寄信 3 條 Rule 留給 P5，見下方說明）
 * 依據：計劃 §9 P4、§6.3（批改資料流）、§4 DEC-18/DEC-21/DEC-22/DEC-23、§12 R-18
 *
 * ─── 介面契約備忘（詳見回報「介面契約」段）─────────────────────────
 * 「寄信本身留到 P5」（計劃 §9 P4 明文）：feature 的「觸發 assignment_graded 郵件」
 * 「每次批改各寄一次」兩條 Rule，本階段改測「pass / fail 觸發 do_action('power_course_assignment_graded')、
 * pending 不觸發」，不斷言實際寄信（capture_mail()）——寄信路徑（At.php 監聽此 action 排信）是 P5 的產物。
 *
 * finished_at 的時間斷言偏離 feature 字面值：`Chapter\Service\Progress::mark_finished()`
 * 內部用 `\wp_date('Y-m-d H:i:s')`（真實系統時間），不是本模組的 `Clock::now()`（DEC-02 只管
 * 作業模組自己的欄位如 graded_at）；計劃 §13「不做」列表明文「不改 Progress::mark_finished 行為」，
 * 故無法讓 finished_at 精確等於 feature 的「2026-09-17 10:00:00」。改為斷言 finished_at 不為空
 * （比對既有 tests/Integration/Progress/ChapterToggleFinishedTest.php 的既定作法），
 * 精確行為改由 action 參數 (201, 100, 2) 佐證。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class GradeAssignmentSubmissionTest
 *
 * @group assignment
 * @group issue-131
 */
class GradeAssignmentSubmissionTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice（已加入課程 100） */
	private int $alice_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，僅文字） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖） */
	private int $assignment_401_id;

	/** @var int 繳交 500（作業 400，Alice，pending） */
	private int $submission_500_id;

	/** @var array<int, array{0:int,1:int,2:int}> 掛在 power_course_chapter_finished 的捕捉紀錄 */
	private array $captured_finished = [];

	/** @var array<int, array{0:int,1:int,2:int}> 掛在 power_course_chapter_unfinished 的捕捉紀錄 */
	private array $captured_unfinished = [];

	/** @var array<int, array<int, mixed>> 掛在 power_course_assignment_graded 的捕捉紀錄 */
	private array $captured_graded = [];

	/**
	 * 建立 Background：Admin/Alice、課程 100、章節 201、作業 400/401、
	 * Alice 已加入課程 100、繳交 500（作業 400，Alice，pending）、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_grade_' . \uniqid(),
				'user_email' => 'admin_grade_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_grade_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_grade_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id     = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
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
		$this->assignment_401_id = $this->create_assignment(
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

		$this->captured_finished   = [];
		$this->captured_unfinished = [];
		$this->captured_graded     = [];

		\add_action( 'power_course_chapter_finished', [ $this, 'capture_chapter_finished' ], 10, 3 );
		\add_action( 'power_course_chapter_unfinished', [ $this, 'capture_chapter_unfinished' ], 10, 3 );
		\add_action( 'power_course_assignment_graded', [ $this, 'capture_assignment_graded' ], 10, 10 );
	}

	/**
	 * 移除本測試掛上的三個 action listener
	 */
	public function tear_down(): void {
		\remove_action( 'power_course_chapter_finished', [ $this, 'capture_chapter_finished' ], 10 );
		\remove_action( 'power_course_chapter_unfinished', [ $this, 'capture_chapter_unfinished' ], 10 );
		\remove_action( 'power_course_assignment_graded', [ $this, 'capture_assignment_graded' ], 10 );

		parent::tear_down();
	}

	/**
	 * 捕捉 power_course_chapter_finished 觸發時的參數
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id  課程 ID
	 * @param int $user_id    學員 ID
	 */
	public function capture_chapter_finished( int $chapter_id, int $course_id, int $user_id ): void {
		$this->captured_finished[] = [ $chapter_id, $course_id, $user_id ];
	}

	/**
	 * 捕捉 power_course_chapter_unfinished 觸發時的參數
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id  課程 ID
	 * @param int $user_id    學員 ID
	 */
	public function capture_chapter_unfinished( int $chapter_id, int $course_id, int $user_id ): void {
		$this->captured_unfinished[] = [ $chapter_id, $course_id, $user_id ];
	}

	/**
	 * 捕捉 power_course_assignment_graded 觸發時的參數（不限定參數個數，介面由 Green 決定，
	 * 本測試只驗證是否觸發與第一個參數為 submission_id，見介面契約段）
	 *
	 * @param mixed ...$args 觸發時傳入的參數
	 */
	public function capture_assignment_graded( ...$args ): void {
		$this->captured_graded[] = $args;
	}

	/**
	 * 批改請求
	 *
	 * @param int                   $submission_id 繳交 ID
	 * @param array<string, mixed> $params        status / feedback
	 * @return \WP_REST_Response
	 */
	private function grade( int $submission_id, array $params ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'PATCH', '/power-course/assignment-submissions/' . $submission_id . '/grade', $params );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員批改時操作失敗
	 */
	public function test_非管理員批改時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'PATCH', '/power-course/assignment-submissions/' . $this->submission_500_id . '/grade', [ 'status' => 'pass' ] );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在，所屬作業在垃圾桶中時視為不存在
	 * Example: 繳交紀錄不存在時批改失敗
	 */
	public function test_繳交紀錄不存在時批改失敗(): void {
		$response = $this->grade( 9999, [ 'status' => 'pass' ] );

		$this->assert_operation_failed_response( $response, '繳交紀錄不存在', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在，所屬作業在垃圾桶中時視為不存在
	 * Example: 繳交所屬作業在垃圾桶時批改失敗
	 */
	public function test_繳交所屬作業在垃圾桶時批改失敗(): void {
		$trashed_assignment_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'       => '已刪除練習',
				'menu_order'  => 20,
				'post_status' => 'trash',
			]
		);
		$trashed_submission_id = $this->seed_submission(
			[
				'assignment_id' => $trashed_assignment_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '舊作業',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);

		$response = $this->grade( $trashed_submission_id, [ 'status' => 'pass' ] );

		$this->assert_operation_failed_response( $response, '繳交紀錄不存在', 404, 'rest_not_found' );
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provider_非法status
	 *
	 * Rule: 前置（參數）- status 必須為 pending、pass、fail 之一
	 *
	 * @param string $status 非法的 status 值
	 */
	public function test_status為非法值時批改失敗( string $status ): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => $status ] );

		$this->assert_operation_failed_response( $response, 'status 必須為 pending、pass、fail 之一', 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provider_非法status(): array {
		return [
			'空字串'  => [ '' ],
			'passed' => [ 'passed' ],
		];
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- feedback 不可超過 10000 字
	 * Example: 評語超過 10000 字時批改失敗
	 */
	public function test_評語超過10000字時批改失敗(): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => 'fail', 'feedback' => \str_repeat( '字', 10001 ) ] );

		$this->assert_operation_failed_response( $response, 'feedback 不可超過 10000 字', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider provider_批改狀態與評語
	 *
	 * Rule: 後置（狀態）- 寫入批改狀態、評語、批改者與批改時間（任何狀態皆寫入批改者與批改時間）
	 *
	 * @param string $status   pending | pass | fail
	 * @param string $feedback 評語
	 */
	public function test_批改為指定狀態時寫入結果( string $status, string $feedback ): void {
		$params = [ 'status' => $status ];
		if ( '' !== $feedback ) {
			$params['feedback'] = $feedback;
		}

		$response = $this->grade( $this->submission_500_id, $params );

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$this->submission_500_id,
			[
				'status'    => $status,
				'feedback'  => $feedback,
				'graded_by' => $this->admin_id,
				'graded_at' => '2026-09-17 10:00:00',
			]
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provider_批改狀態與評語(): array {
		return [
			'pass'    => [ 'pass', '寫得很好' ],
			'fail'    => [ 'fail', '請補充型別說明' ],
			'pending' => [ 'pending', '' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 未提供 feedback 時評語清空
	 * Example: 改判通過且未給評語時清除舊評語
	 */
	public function test_未給feedback時清除舊評語(): void {
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'status'        => 'fail',
				'feedback'      => '舊評語',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);

		$response = $this->grade( $submission_id, [ 'status' => 'pass' ] );

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$submission_id,
			[
				'status'    => 'pass',
				'feedback'  => '',
				'graded_at' => '2026-09-17 10:00:00',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 可重複改判，以最後一次批改為準
	 * Example: 已通過的繳交改判為未通過
	 */
	public function test_已通過的繳交改判為未通過(): void {
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'status'        => 'pass',
				'feedback'      => '很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);

		$response = $this->grade( $submission_id, [ 'status' => 'fail', 'feedback' => '截圖看不清楚' ] );

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$submission_id,
			[
				'status'    => 'fail',
				'feedback'  => '截圖看不清楚',
				'graded_at' => '2026-09-17 10:00:00',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 批改為 pass 時，若作業所屬章節對該學員尚未完成則自動標記完成，不檢查同章節其他作業
	 * Example: 練習一通過時自動完成章節 201（練習二尚未繳交）
	 *
	 * finished_at 時間斷言偏離 feature 字面值，見檔案頂部說明；精確行為以 action 參數佐證。
	 */
	public function test_練習一通過時自動完成章節201(): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );

		$this->assert_success_response( $response );

		$finished_at = $this->get_chapter_meta( $this->chapter_201_id, $this->alice_id, 'finished_at' );
		$this->assertNotEmpty( $finished_at, '批改通過後章節應自動標記完成，finished_at 不應為空' );

		$this->assertSame(
			[ [ $this->chapter_201_id, $this->course_id, $this->alice_id ] ],
			$this->captured_finished,
			'power_course_chapter_finished 應以 (chapter_id, course_id, user_id) 被觸發一次'
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 章節已完成時不重複標記、不覆寫完成時間
	 * Example: 章節已完成時批改通過不改變完成時間
	 */
	public function test_章節已完成時批改通過不改變完成時間(): void {
		$this->set_chapter_finished( $this->chapter_201_id, $this->alice_id, '2026-09-10 08:00:00' );

		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass' ] );

		$this->assert_success_response( $response );

		$finished_at = $this->get_chapter_meta( $this->chapter_201_id, $this->alice_id, 'finished_at' );
		$this->assertSame( '2026-09-10 08:00:00', $finished_at, '已完成章節的 finished_at 不應被覆寫' );
		$this->assertSame( [], $this->captured_finished, 'power_course_chapter_finished 不應被觸發' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_改判狀態
	 *
	 * Rule: 後置（狀態）- 由通過改判為未通過或待批改時，不撤銷章節完成狀態
	 *
	 * @param string $status fail | pending
	 */
	public function test_改判為指定狀態後章節仍為完成( string $status ): void {
		$submission_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'status'        => 'pass',
				'feedback'      => '很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);
		$this->set_chapter_finished( $this->chapter_201_id, $this->alice_id, '2026-09-16 10:00:00' );

		$response = $this->grade( $submission_id, [ 'status' => $status ] );

		$this->assert_success_response( $response );

		$finished_at = $this->get_chapter_meta( $this->chapter_201_id, $this->alice_id, 'finished_at' );
		$this->assertSame( '2026-09-16 10:00:00', $finished_at, '改判不應撤銷章節完成狀態' );
		$this->assertSame( [], $this->captured_unfinished, 'power_course_chapter_unfinished 不應被觸發' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provider_改判狀態(): array {
		return [
			'fail'    => [ 'fail' ],
			'pending' => [ 'pending' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider provider_pass或fail
	 *
	 * Rule: 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded；改回 pending 時不寄送
	 * 本階段只驗證 do_action('power_course_assignment_graded') 觸發，寄信路徑留給 P5（見檔案頂部說明）
	 *
	 * @param string $status pass | fail
	 */
	public function test_批改為pass或fail時觸發assignment_graded( string $status ): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => $status, 'feedback' => '評語' ] );

		$this->assert_success_response( $response );

		$this->assertCount( 1, $this->captured_graded, 'power_course_assignment_graded 應觸發一次' );
		$this->assertSame(
			$this->submission_500_id,
			(int) ( $this->captured_graded[0][0] ?? 0 ),
			'power_course_assignment_graded 的第一個參數應為 submission_id'
		);
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
	 * Rule: 後置（狀態）- 批改為 pass 或 fail 時觸發 assignment_graded；改回 pending 時不寄送
	 * Example: 改回待批改時不寄送批改通知（本階段驗證 action 不觸發）
	 */
	public function test_改回pending時不觸發assignment_graded(): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pending' ] );

		$this->assert_success_response( $response );
		$this->assertSame( [], $this->captured_graded, 'pending 時 power_course_assignment_graded 不應被觸發' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 自動完成章節或寄送通知失敗時，不影響批改結果
	 * Example: 寄信服務錯誤時批改仍成功（本階段以「graded action listener 拋例外」模擬副作用失敗）
	 */
	public function test_副作用失敗仍成功(): void {
		$thrower = static function (): void {
			throw new \RuntimeException( '模擬寄信服務錯誤' );
		};
		\add_action( 'power_course_assignment_graded', $thrower, 20 );

		try {
			$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );
		} finally {
			\remove_action( 'power_course_assignment_graded', $thrower, 20 );
		}

		$this->assert_success_response( $response );
		$this->assert_submission_exists(
			$this->submission_500_id,
			[
				'status'   => 'pass',
				'feedback' => '寫得很好',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳批改後的繳交紀錄
	 * Example: 批改成功後回應繳交資料
	 */
	public function test_批改成功後回應繳交資料(): void {
		$response = $this->grade( $this->submission_500_id, [ 'status' => 'pass', 'feedback' => '寫得很好' ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'id'            => $this->submission_500_id,
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-17 10:00:00',
			]
		);
	}
}
