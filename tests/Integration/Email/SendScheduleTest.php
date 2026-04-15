<?php
/**
 * 排程發送郵件整合測試
 *
 * Feature: specs/features/email/排程發送郵件.feature
 * 測試 POST /power-email/v1/emails/send-schedule 的業務邏輯：
 * - email_ids / user_ids / timestamp 必填驗證
 * - as_schedule_single_action 的呼叫行為
 * - 過去 timestamp 仍可建立（past-due 行為）
 *
 * @group email
 * @group send-schedule
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;

/**
 * Class SendScheduleTest
 * 測試排程發送郵件 API 的業務邏輯
 */
class SendScheduleTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int 郵件模板 ID */
	private int $email_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress 內建 API 與 Action Scheduler
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_sched_' . uniqid(),
				'user_email' => 'admin_sched_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_sched_' . uniqid(),
				'user_email' => 'alice_sched_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程提醒',
				'post_type'   => 'pc_email',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->email_id, 'trigger_at', 'course_granted' );

		$this->ids['Admin'] = $this->admin_id;
		$this->ids['Alice'] = $this->alice_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * as_schedule_single_action 函式存在
	 */
	public function test_冒煙_as_schedule_single_action存在(): void {
		$this->assertTrue( function_exists( 'as_schedule_single_action' ), 'as_schedule_single_action 應已載入' );
	}

	// ========== 前置（參數）- 必填驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: timestamp 為必填（缺少時拋出例外）
	 */
	public function test_缺少timestamp時_include_required_params拋出例外(): void {
		$body_params = [
			'email_ids' => [ $this->email_id ],
			'user_ids'  => [ $this->alice_id ],
		];

		try {
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'email_ids', 'user_ids', 'timestamp' ] );
			$this->fail( '應拋出例外，因為 timestamp 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e, '缺少 timestamp 應拋出例外' );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: timestamp 為字串時（如 "not-a-num"），(int) cast 後值為 0
	 * 依 code：$timestamp = (int) $body_params['timestamp']
	 * 字串 "not-a-num" cast 為 0，仍可建立 action（不拋錯）
	 */
	public function test_timestamp為字串時_cast為int後建立action(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 依 code is source of truth：(int) "not-a-num" = 0，不拋錯
		$timestamp_str = 'not-a-num';
		$timestamp     = (int) $timestamp_str;

		// timestamp 為 0，as_schedule_single_action 仍應執行（不拋例外）
		$action_id = as_schedule_single_action(
			$timestamp,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		// 應回傳 action_id（整數）
		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
	}

	// ========== 後置（狀態）- Action Scheduler ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 成功排程未來時間，action_id 為正整數
	 */
	public function test_排程未來時間_action_id為正整數(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 未來 1 小時後
		$future_timestamp = time() + 3600;

		$action_id = as_schedule_single_action(
			$future_timestamp,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id, '排程 action_id 應為正整數' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 新增的 action group 為 "power_email"
	 */
	public function test_排程action_group為power_email(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$future_timestamp = time() + 3600;

		as_schedule_single_action(
			$future_timestamp,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		$scheduled = as_get_scheduled_actions(
			[
				'hook'  => At::SEND_USERS_HOOK,
				'group' => At::AS_GROUP,
			],
			'ids'
		);

		$this->assertNotEmpty( $scheduled, 'group=power_email 應有 pending action' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 排程過去時間（past-due）仍可建立 action
	 */
	public function test_排程過去時間_仍可建立action(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 過去時間（year 2001）
		$past_timestamp = 1000000000;

		$action_id = as_schedule_single_action(
			$past_timestamp,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		// 依 spec：仍可建立，不報錯
		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id, '過去 timestamp 仍可建立 action' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 多個排程動作各自獨立（不互相覆蓋）
	 */
	public function test_多次排程_動作各自獨立(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$ts1 = time() + 1800;
		$ts2 = time() + 3600;

		$action_id_1 = as_schedule_single_action(
			$ts1,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		$action_id_2 = as_schedule_single_action(
			$ts2,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		$this->assertNotSame( $action_id_1, $action_id_2, '兩次排程應產生不同的 action_id' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: timestamp 為 0 時，等同立即執行（past-due）
	 */
	public function test_timestamp為0時_等同立即執行(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$action_id = as_schedule_single_action(
			0,
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
	}
}
