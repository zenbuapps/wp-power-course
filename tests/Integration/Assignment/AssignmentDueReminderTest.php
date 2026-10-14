<?php
/**
 * 作業截止提醒排程 整合測試（Issue #131 第一波 P6）
 *
 * Feature: specs/features/assignment/作業截止提醒.feature
 *          specs/features/email/觸發自動郵件.feature（assignment_due_soon Rule 的內容變數 Example）
 * 依據：計劃 §9 P6、§6.4、§4 DEC-02/DEC-10、§12 R-06/R-24；clarify D20
 *
 * ─── 本階段只測「每日掃描哪些學員該收信」這一段 ─────────────────
 * P5 已完成「收到 power_course_assignment_due_soon 之後怎麼排信、怎麼寄」
 * （PowerEmail\Trigger\At::schedule_assignment_due_soon_email() / send_assignment_due_soon_email()）。
 * 本檔驗證的是掃描本身：Service\DueReminder::run() 找出「截止時間落在 [現在, 現在+48小時]
 * 且仍有有效觀看權且尚未繳交」的學員，對每一位 do_action( At::ASSIGNMENT_DUE_SOON_ACTION,
 * $assignment_id, $user_id, $course_id, $due_at )；以及每日 09:00（站台時區）的排程本身
 * （Service\DueReminder::maybe_schedule_scan()）。
 * ──────────────────────────────────────────────────────────
 *
 * ─── 流程 ─────────────────────────────────────────────
 * run_due_soon_scan()：
 *   Service\DueReminder::run()（強制執行一次掃描，同步 do_action）
 *   → run_scheduled_actions('power_email_send_assignment_due_soon')（強制執行 pending action）
 *   → capture_mail()（pre_wp_mail 攔截，不真的寄信）
 * ────────────────────────────────────────────────────────
 *
 * ─── 測什麼、不測什麼 ─────────────────────────────────────
 * 不比照 998 業務決策直接呼叫尚未實作的 production class（Service\DueReminder），
 * 一律先 class_exists() / method_exists() 檢查，找不到就 $this->fail() 給出明確訊息，
 * 避免整個測試類別因為呼叫不存在的 class 而在方法內產生未捕捉的 Fatal Error
 * （比照 tests/Integration/Assignment/UploadAssignmentAttachmentTest.php 的 call_store() 寫法）。
 * ──────────────────────────────────────────────────────────
 *
 * ─── 與既有 Background 的差異 ─────────────────────────────
 * feature 的 Background 固定建立一個 emailId=810 的已發佈模板。本檔比照既有 P5 測試
 * （AssignmentGradedEmailTest／AssignmentEmailReplaceTest）的寫法，不在 set_up() 建立共用模板，
 * 改由每個測試方法各自呼叫 create_email_template() 建立自己需要的模板——理由：
 *   1. 避免共用模板造成測試間的隱性耦合（例如「內容比對」測試若沿用共用模板，
 *      會同時收到共用模板與內容測試模板各一封，混淆內容斷言）。
 *   2. 與既有 P5 測試風格一致，降低共用基底以外的認知負擔。
 * 功能語意不受影響：feature 的每個 Rule 都只依賴「存在一個已發佈的 assignment_due_soon 模板」，
 * 而非該模板的特定 emailId。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;
use J7\PowerCourse\Resources\AccessPass\Core\CPT as AccessPassCPT;
use J7\PowerCourse\Resources\AccessPass\Service\Repository as AccessPassRepository;

/**
 * Class AssignmentDueReminderTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentDueReminderTest extends AssignmentTestCase {

	/** 每日掃描的 Action Scheduler hook（計劃 §9 P6 Green 段明文：SCAN_HOOK） */
	private const SCAN_HOOK = 'pc_assignment_due_soon_scan';

	/** 掃描觸發 assignment_due_soon 信件排程後，實際寄信的 Action Scheduler hook（沿用 P5 AtHelper 的 "power_email_send_{slug}" 命名） */
	private const DUE_SOON_SEND_HOOK = 'power_email_send_assignment_due_soon';

	/** Service\DueReminder 類別 FQCN（計劃 §5.1，供動態呼叫 run() / maybe_schedule_scan() 用，字面字串避免尚未存在的 class 出現在 use 區塊） */
	private const DUE_REMINDER_SERVICE_CLASS = 'J7\PowerCourse\Resources\Assignment\Service\DueReminder';

	/** 現在時間（站台時區 Asia/Taipei），對齊 feature Background */
	private const NOW = '2026-09-17 10:00:00';

	/** 作業 400（練習一：變數宣告）的截止時間，落在 [現在, 現在+48小時] 窗口內（2026-09-18 23:59 站台時區） */
	private const DUE_AT_400 = 1789747140;

	/** 作業 401（練習二：上傳截圖）的截止時間，落在窗口外（2026-09-20 10:00 站台時區） */
	private const DUE_AT_401 = 1789869600;

	/** 作業 403（練習四：只收檔案）的截止時間，落在窗口外（早於現在，2026-09-10 23:59 站台時區） */
	private const DUE_AT_403 = 1789055940;

	/** @var int 學員 Alice（已加入課程 100） */
	private int $alice_id;

	/** @var string 學員 Alice 的 email */
	private string $alice_email;

	/** @var int 學員 Bob（已加入課程 100） */
	private int $bob_id;

	/** @var string 學員 Bob 的 email */
	private string $bob_email;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 201（1-1 變數） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，due_at 落在窗口內） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，due_at 落在窗口外） */
	private int $assignment_401_id;

	/** @var int 作業 402（練習三：心得，due_at=0，無截止時間） */
	private int $assignment_402_id;

	/** @var int 作業 403（練習四：只收檔案，due_at 落在窗口外） */
	private int $assignment_403_id;

	/**
	 * 建立 Background：Alice/Bob、課程 100、章節 201、作業 400/401/402/403、
	 * Alice/Bob 已加入課程 100（expire_date 0）、現在時間 2026-09-17 10:00:00、開始攔截寄信
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_email = 'alice_due_reminder_' . \uniqid() . '@test.com';
		$this->alice_id     = $this->factory()->user->create(
			[
				'user_login'   => 'alice_due_reminder_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email'   => $this->alice_email,
				'role'         => 'subscriber',
			]
		);

		$this->bob_email = 'bob_due_reminder_' . \uniqid() . '@test.com';
		$this->bob_id     = $this->factory()->user->create(
			[
				'user_login'   => 'bob_due_reminder_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email'   => $this->bob_email,
				'role'         => 'subscriber',
			]
		);

		$this->course_id      = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$this->assignment_400_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習一：變數宣告',
				'allow_text'    => 'yes',
				'allow_file'    => 'no',
				'required_text' => 'no',
				'required_file' => 'no',
				'max_files'     => 1,
				'due_at'        => self::DUE_AT_400,
				'menu_order'    => 0,
			]
		);
		$this->assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_text'    => 'yes',
				'allow_file'    => 'yes',
				'required_text' => 'no',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => self::DUE_AT_401,
				'menu_order'    => 10,
			]
		);
		$this->assignment_402_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習三：心得',
				'allow_text'    => 'yes',
				'allow_file'    => 'no',
				'required_text' => 'no',
				'required_file' => 'no',
				'max_files'     => 1,
				'due_at'        => 0,
				'menu_order'    => 20,
			]
		);
		$this->assignment_403_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習四：只收檔案',
				'allow_text'    => 'no',
				'allow_file'    => 'yes',
				'required_text' => 'no',
				'required_file' => 'no',
				'max_files'     => 1,
				'due_at'        => self::DUE_AT_403,
				'menu_order'    => 30,
			]
		);

		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
		$this->enroll_user_to_course( $this->bob_id, $this->course_id, 0 );

		$this->set_now( self::NOW );
		$this->capture_mail();
	}

	// ========== Helper：呼叫（可能）尚未存在的 Service\DueReminder ==========

	/**
	 * 執行一次截止提醒掃描：Service\DueReminder::run()（同步 do_action）
	 * → 強制執行 power_email_send_assignment_due_soon 的 pending action（真正「寄信」）
	 */
	private function run_due_soon_scan(): void {
		$this->call_static_or_fail( self::DUE_REMINDER_SERVICE_CLASS, 'run' );
		$this->run_scheduled_actions( self::DUE_SOON_SEND_HOOK );
	}

	/**
	 * 建立一封 assignment_due_soon 郵件模板（trigger_at 固定，其餘可覆寫）
	 *
	 * @param array<string, mixed> $args 覆寫 create_email_template() 的預設值
	 * @return int 郵件模板（pe_email）post ID
	 */
	private function create_due_soon_template( array $args = [] ): int {
		$defaults = [
			'post_title' => '作業即將截止',
			'trigger_at' => 'assignment_due_soon',
			'course_ids' => [],
		];

		return $this->create_email_template( \wp_parse_args( $args, $defaults ) );
	}

	/**
	 * 直接寫入一筆「已寄過」的 pc_email_records（供去重 / 截止時間變更測試模擬前置狀態）
	 *
	 * @param int $email_id      郵件模板 ID
	 * @param int $assignment_id 作業 ID
	 * @param int $due_at        截止時間（identifier 的一部分，DEC-10）
	 * @param int $user_id       學員 user ID
	 */
	private function seed_due_soon_sent( int $email_id, int $assignment_id, int $due_at, int $user_id ): void {
		$email      = new Email( $email_id );
		$identifier = $email->get_identifier( [ $assignment_id, $due_at ], $user_id );

		$recorded = EmailRecord::add(
			$this->course_id,
			$user_id,
			$email_id,
			'作業即將截止',
			'assignment_due_soon',
			$identifier,
			true
		);

		$this->assertNotFalse( $recorded, '前置條件不成立：無法寫入模擬「已寄過」的 EmailRecord' );
	}

	// ========== Helper：斷言寄出的截止提醒 ==========

	/**
	 * 取出 capture_mail() 攔截到的收件人（單封信可能是陣列或字串）
	 *
	 * @param array{to: mixed, subject: string, message: string, headers: mixed, attachments: mixed} $mail 單封攔截紀錄
	 * @return string
	 */
	private static function mail_recipient( array $mail ): string {
		$to = $mail['to'];
		return \is_array( $to ) ? \implode( ',', $to ) : (string) $to;
	}

	/**
	 * 斷言曾寄出「收件人 email 含 $email、內容含作業標題 $title」的截止提醒
	 *
	 * @param string $email   期望收件人 email
	 * @param string $title   期望信件內容包含的作業標題
	 * @param string $context 附加除錯上下文（例如 Scenario Outline 的說明欄），可省略
	 */
	private function assert_due_reminder_sent_to( string $email, string $title, string $context = '' ): void {
		$found = false;
		foreach ( $this->captured_mails as $mail ) {
			if ( \str_contains( self::mail_recipient( $mail ), $email ) && \str_contains( (string) $mail['message'], $title ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			\sprintf(
				'應寄送作業「%s」的截止提醒給 %s%s，實際攔截到 %d 封信：%s',
				$title,
				$email,
				'' === $context ? '' : "（{$context}）",
				\count( $this->captured_mails ),
				(string) \wp_json_encode( \array_map( [ self::class, 'mail_recipient' ], $this->captured_mails ) )
			)
		);
	}

	/**
	 * 斷言未曾寄出「收件人 email 含 $email、內容含作業標題 $title」的截止提醒
	 *
	 * @param string $email   期望不應收到信的 email
	 * @param string $title   作業標題
	 * @param string $context 附加除錯上下文（例如 Scenario Outline 的說明欄），可省略
	 */
	private function assert_due_reminder_not_sent_to( string $email, string $title, string $context = '' ): void {
		$found = false;
		foreach ( $this->captured_mails as $mail ) {
			if ( \str_contains( self::mail_recipient( $mail ), $email ) && \str_contains( (string) $mail['message'], $title ) ) {
				$found = true;
				break;
			}
		}

		$this->assertFalse(
			$found,
			\sprintf( '不應寄送作業「%s」的截止提醒給 %s%s', $title, $email, '' === $context ? '' : "（{$context}）" )
		);
	}

	/**
	 * 斷言寄出的截止提醒「恰好」等於期望清單（數量與內容皆須相符，對齊 feature 的
	 * "Then 應寄出以下作業截止提醒：" 表格語意——表格即完整清單，不是部分列舉）
	 *
	 * @param array<int, array{email: string, title: string}> $expected 期望的（收件人, 作業標題）清單
	 */
	private function assert_exact_due_reminders( array $expected ): void {
		$this->assertCount(
			\count( $expected ),
			$this->captured_mails,
			\sprintf(
				'寄出的截止提醒數量不符，預期 %d 封，實際攔截到 %d 封：%s',
				\count( $expected ),
				\count( $this->captured_mails ),
				(string) \wp_json_encode( \array_map( [ self::class, 'mail_recipient' ], $this->captured_mails ) )
			)
		);

		foreach ( $expected as $row ) {
			$this->assert_due_reminder_sent_to( $row['email'], $row['title'] );
		}
	}

	// ========== Helper：Action Scheduler 排程檢查（09:00 站台時區 / 間隔） ==========

	/**
	 * 取得指定 hook 目前 pending 排程的筆數
	 *
	 * @param string $hook Action Scheduler hook 名稱
	 * @return int
	 */
	private function count_scheduled_actions( string $hook ): int {
		if ( ! \function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		/** @var array<int, int|string> $action_ids */
		$action_ids = \as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);

		return \is_array( $action_ids ) ? \count( $action_ids ) : 0;
	}

	/**
	 * 取得指定 hook 第一筆 pending 排程的間隔秒數（非 ActionScheduler_IntervalSchedule 時回傳 null）
	 *
	 * @param string $hook Action Scheduler hook 名稱
	 * @return int|null
	 */
	private function get_scheduled_action_interval_seconds( string $hook ): ?int {
		if ( ! \function_exists( 'as_get_scheduled_actions' ) || ! \class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		/** @var array<int, int|string> $action_ids */
		$action_ids = \as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);

		if ( ! $action_ids ) {
			return null;
		}

		$store    = \ActionScheduler::store();
		$action   = $store->fetch_action( (string) \reset( $action_ids ) );
		$schedule = $action->get_schedule();

		if ( $schedule instanceof \ActionScheduler_IntervalSchedule ) {
			// interval_in_seconds() 自 AS 3.0.0 起棄用，呼叫會觸發 _deprecated_function，
			// WP_UnitTestCase 收到未預期的棄用通知就判定測試失敗；
			// get_recurrence() 是同語意的替代方法但為 protected，比照
			// tests/Integration/Email/ScheduledActionsTest.php 以 ReflectionMethod 取值。
			$method = new \ReflectionMethod( $schedule, 'get_recurrence' );
			$method->setAccessible( true );

			return (int) $method->invoke( $schedule );
		}

		return null;
	}

	// ========== Rule: 前置（狀態）- 只處理截止時間落在現在到 48 小時後（含兩端）的作業 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 只處理截止時間落在現在到 48 小時後（含兩端）的作業
	 * Example: 只寄出 48 小時內截止的練習一
	 *
	 * 練習二（窗口外，晚 3 天）、練習三（無截止時間）、練習四（窗口外，早 7 天）皆不應觸發，
	 * 用 assert_exact_due_reminders() 同時驗證「該寄的有寄」與「不該寄的沒寄」。
	 */
	public function test_只寄出48小時內截止的練習一(): void {
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_exact_due_reminders(
			[
				[ 'email' => $this->alice_email, 'title' => '練習一：變數宣告' ],
				[ 'email' => $this->bob_email, 'title' => '練習一：變數宣告' ],
			]
		);
	}

	// ========== Scenario Outline: 邊界值（含兩端）==========

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_邊界內截止時間
	 *
	 * Scenario Outline: 截止時間為 <due_at>（<說明>）時寄送提醒
	 *
	 * @param int    $due_at 邊界作業 405 的截止時間
	 * @param string $label  說明（僅供除錯訊息使用）
	 */
	public function test_邊界內截止時間會寄送提醒( int $due_at, string $label ): void {
		$this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '邊界練習',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'due_at'     => $due_at,
				'menu_order' => 40,
			]
		);
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_due_reminder_sent_to( $this->alice_email, '邊界練習', $label );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function provider_邊界內截止時間(): array {
		return [
			'等於現在'          => [ 1789610400, '等於現在' ],
			'等於現在 + 48 小時' => [ 1789783200, '等於現在 + 48 小時' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_邊界外截止時間
	 *
	 * Scenario Outline: 截止時間為 <due_at>（<說明>）時不寄送提醒
	 *
	 * @param int    $due_at 邊界作業 405 的截止時間
	 * @param string $label  說明（僅供除錯訊息使用）
	 */
	public function test_邊界外截止時間不寄送提醒( int $due_at, string $label ): void {
		$this->create_assignment(
			$this->chapter_201_id,
			[
				'title'      => '邊界練習',
				'allow_text' => 'yes',
				'allow_file' => 'no',
				'max_files'  => 1,
				'due_at'     => $due_at,
				'menu_order' => 40,
			]
		);
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_due_reminder_not_sent_to( $this->alice_email, '邊界練習', $label );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function provider_邊界外截止時間(): array {
		return [
			'早於現在 1 秒'            => [ 1789610399, '早於現在 1 秒' ],
			'晚於現在 + 48 小時 1 秒' => [ 1789783201, '晚於現在 + 48 小時 1 秒' ],
		];
	}

	// ========== Rule: 前置（狀態）- 已繳交該作業的學員不寄送（不論批改狀態） ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 已繳交該作業的學員不寄送（不論批改狀態）
	 * Example: Alice 已繳交練習一
	 */
	public function test_已繳交作業的學員不收到提醒(): void {
		$this->seed_submission(
			[
				'assignment_id'  => $this->assignment_400_id,
				'user_id'        => $this->alice_id,
				'text_body'      => 'let x = 1;',
				'attachment_ids' => [],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_exact_due_reminders(
			[
				[ 'email' => $this->bob_email, 'title' => '練習一：變數宣告' ],
			]
		);
	}

	// ========== Rule: 前置（狀態）- 只寄給對課程仍有有效觀看權的學員 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 只寄給對課程仍有有效觀看權的學員（含持有涵蓋此課程之有效權限包者）
	 * Example: Bob 已到期不寄送
	 *
	 * R-06：is_expired() 讀的是 time() 不是 Clock，用相對時間確保測試不隨系統時鐘漂移。
	 */
	public function test_觀看權已到期時不寄送提醒(): void {
		$this->enroll_user_to_course( $this->bob_id, $this->course_id, \time() - \DAY_IN_SECONDS );
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_exact_due_reminders(
			[
				[ 'email' => $this->alice_email, 'title' => '練習一：變數宣告' ],
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 只寄給對課程仍有有效觀看權的學員（含持有涵蓋此課程之有效權限包者）
	 * Example: 持有全站權限包的 Carol 也會收到
	 *
	 * Carol 未透過 enroll_user_to_course() 加入課程（avl_course_ids 沒有這門課），
	 * 僅持有涵蓋全站課程的權限包——驗證 is_avl() / is_expired() 對權限包持有者的 OR 疊加語意
	 * （Utils\Course::is_avl() 內部 Gate::user_has_valid_pass_for_course()）在截止提醒掃描裡也成立。
	 */
	public function test_持有全站權限包的Carol也會收到提醒(): void {
		$carol_email = 'carol_due_reminder_' . \uniqid() . '@test.com';
		$carol_id     = $this->factory()->user->create(
			[
				'user_login'   => 'carol_due_reminder_' . \uniqid(),
				'display_name' => 'Carol',
				'user_email'   => $carol_email,
				'role'         => 'subscriber',
			]
		);

		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => AccessPassCPT::POST_TYPE,
				'post_title'  => '全站權限包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $pass_id, 'scope_type', 'all' );
		\update_post_meta( $pass_id, 'limit_type', 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', 'active' );

		AccessPassRepository::insert_or_update( $carol_id, $pass_id, null, null );

		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assert_exact_due_reminders(
			[
				[ 'email' => $this->alice_email, 'title' => '練習一：變數宣告' ],
				[ 'email' => $this->bob_email, 'title' => '練習一：變數宣告' ],
				[ 'email' => $carol_email, 'title' => '練習一：變數宣告' ],
			]
		);
	}

	// ========== Rule: 前置（狀態）- 課程尚未開課時不寄送 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 課程尚未開課時不寄送
	 * Example: 課程排程開課時間在未來
	 *
	 * R-06：is_course_ready() 讀的是 time() 不是 Clock，用相對時間。
	 */
	public function test_課程尚未開課時不寄送任何郵件(): void {
		\update_post_meta( $this->course_id, 'course_schedule', \time() + \DAY_IN_SECONDS );
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assertSame( [], $this->captured_mails, '課程尚未開課時不應發送任何郵件' );
	}

	// ========== Rule: 前置（狀態）- 垃圾桶中的作業不寄送 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 垃圾桶中的作業不寄送
	 * Example: 練習一已移至垃圾桶
	 *
	 * 練習一（400）是 Background 中唯一落在窗口內的作業，移至垃圾桶後應完全沒有任何提醒。
	 */
	public function test_垃圾桶中的作業不寄送(): void {
		\wp_trash_post( $this->assignment_400_id );
		$this->create_due_soon_template();

		$this->run_due_soon_scan();

		$this->assertSame( [], $this->captured_mails, '垃圾桶中的作業不應觸發截止提醒' );
	}

	// ========== Rule: 前置（狀態）- 沒有已發佈的 assignment_due_soon 郵件模板時不寄送 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 沒有已發佈的 assignment_due_soon 郵件模板時不寄送
	 * Example: 模板為草稿
	 */
	public function test_草稿模板不寄送(): void {
		$this->create_due_soon_template( [ 'post_status' => 'draft' ] );

		$this->run_due_soon_scan();

		$this->assertSame( [], $this->captured_mails, '草稿模板不應觸發任何寄送' );
	}

	// ========== Rule: 後置（狀態）- 外掛初始化時確保每日 09:00 的掃描排程存在 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 外掛初始化時確保每日 09:00 的掃描排程存在
	 * Example: 註冊每日排程
	 */
	public function test_外掛初始化時應排程每日09點掃描(): void {
		$this->call_static_or_fail( self::DUE_REMINDER_SERVICE_CLASS, 'maybe_schedule_scan' );

		$next = \as_next_scheduled_action( self::SCAN_HOOK );
		$this->assertNotFalse( $next, '應已排程 ' . self::SCAN_HOOK );

		$this->assertSame(
			'09:00',
			\wp_date( 'H:i', (int) $next ),
			'排程的下次執行時間換算成站台時區應為 09:00'
		);

		$interval = $this->get_scheduled_action_interval_seconds( self::SCAN_HOOK );
		$this->assertSame( \DAY_IN_SECONDS, $interval, '排程間隔應為每日一次（86400 秒）' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 排程冪等（計劃 §9 P6 任務清單明文：「已排程時不重複」；feature 本身未列出獨立 Example，
	 * 對齊 P1 MigrationTest 對 AbstractTable::maybe_upgrade() 冪等性的驗證方式——test-creator 決定）
	 */
	public function test_已排程時不重複建立(): void {
		$this->call_static_or_fail( self::DUE_REMINDER_SERVICE_CLASS, 'maybe_schedule_scan' );
		$first = \as_next_scheduled_action( self::SCAN_HOOK );

		$this->call_static_or_fail( self::DUE_REMINDER_SERVICE_CLASS, 'maybe_schedule_scan' );
		$second = \as_next_scheduled_action( self::SCAN_HOOK );

		$this->assertSame( $first, $second, '重複呼叫 maybe_schedule_scan() 不應改變已排程的下次執行時間' );
		$this->assertSame( 1, $this->count_scheduled_actions( self::SCAN_HOOK ), '不應建立第二筆排程' );
	}

	// ========== Rule: 後置（狀態）- 同一模板對同一學員、同一作業、同一截止時間只寄一次 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 同一模板對同一學員、同一作業、同一截止時間只寄一次
	 * Example: 當天再次掃描不重複寄送
	 */
	public function test_同一截止時間不重複寄送(): void {
		$email_id = $this->create_due_soon_template();

		$this->seed_due_soon_sent( $email_id, $this->assignment_400_id, self::DUE_AT_400, $this->alice_id );
		$this->seed_due_soon_sent( $email_id, $this->assignment_400_id, self::DUE_AT_400, $this->bob_id );

		$this->run_due_soon_scan();

		$this->assertSame( [], $this->captured_mails, '同一截止時間已寄過時不應重複發送' );
	}

	// ========== Rule: 後置（狀態）- 截止時間變更後依新的截止時間再次提醒 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 截止時間變更後依新的截止時間再次提醒
	 * Example: 練習一延後截止後再次提醒
	 *
	 * 預先寫入「舊截止時間」的 EmailRecord（模擬上一次已針對舊 due_at 寄過提醒），
	 * 實際作業 400 目前的 due_at（DUE_AT_400）與這個舊值不同，identifier 因此不同，
	 * 證明去重是以「這個截止時間」為單位（DEC-10）。
	 */
	public function test_截止時間變更後依新截止時間再次提醒(): void {
		$email_id     = $this->create_due_soon_template();
		$old_due_at   = 1789700000;

		$this->seed_due_soon_sent( $email_id, $this->assignment_400_id, $old_due_at, $this->alice_id );

		$this->run_due_soon_scan();

		$this->assert_due_reminder_sent_to( $this->alice_email, '練習一：變數宣告' );
	}

	// ========== Rule: 後置（狀態）- 寄出後寫入郵件紀錄 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 寄出後寫入郵件紀錄
	 * Example: 寄給 Alice 後寫入 pc_email_records
	 */
	public function test_寄出後寫入pc_email_records(): void {
		$email_id = $this->create_due_soon_template();

		$this->run_due_soon_scan();

		$records = EmailRecord::get(
			[
				'user_id'    => (string) $this->alice_id,
				'trigger_at' => 'assignment_due_soon',
			]
		);

		$this->assertNotEmpty( $records, '應有一筆寄給 Alice 的作業截止提醒紀錄' );

		$record = $records[0];
		$this->assertSame( (string) $email_id, (string) $record->email_id, 'email_id 應為寄出的郵件模板 ID' );
		$this->assertSame( (string) $this->alice_id, (string) $record->user_id, 'user_id 應為 Alice' );
		$this->assertSame( (string) $this->course_id, (string) $record->post_id, 'post_id 應為課程 ID（DEC-10）' );
		$this->assertSame( 'assignment_due_soon', $record->trigger_at, 'trigger_at 應為 assignment_due_soon' );
		$this->assertSame( '1', (string) $record->mark_as_sent, 'mark_as_sent 應標記為已寄送' );
	}

	// ========== email/觸發自動郵件.feature：assignment_due_soon 內容變數（跨檔案 Example） ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Feature: specs/features/email/觸發自動郵件.feature
	 * Rule: 後置（狀態）- 新增觸發時機 assignment_due_soon：每日作業截止提醒掃描時寄給尚未繳交的學員，
	 *       {assignment_due_at} 以站台時區格式化
	 * Example: 截止提醒郵件含截止時間
	 *
	 * 與 AssignmentEmailReplaceTest::test_assignment_due_at格式為站台時區YmdHi() 不同：
	 * 該測試借用 P5 已就緒的 assignment_graded 流程驗證 {assignment_due_at} 這個 Replace 變數
	 * 本身的格式；本測試才是 feature 原文 "When ActionScheduler 執行作業截止提醒掃描" 的真正驗證，
	 * 確認截止提醒的實際掃描路徑也會正確替換 {assignment_title} 與 {assignment_due_at}。
	 */
	public function test_截止提醒郵件內容包含作業標題與站台時區截止時間(): void {
		$this->create_due_soon_template(
			[
				'post_content' => '「{assignment_title}」將於 {assignment_due_at} 截止',
			]
		);

		$this->run_due_soon_scan();

		$expected_due_at = \wp_date( 'Y-m-d H:i', self::DUE_AT_400 );
		$this->assertSame( '2026-09-18 23:59', $expected_due_at, '前置條件不成立：站台時區換算後的期望值應為 2026-09-18 23:59（與 email/觸發自動郵件.feature 一致）' );

		$this->assert_mail_sent_to( $this->alice_email );
		$this->assert_mail_content_contains( '「練習一：變數宣告」將於 ' . $expected_due_at . ' 截止' );
	}
}
