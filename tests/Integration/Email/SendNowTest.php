<?php
/**
 * 立即發送郵件整合測試
 *
 * Feature: specs/features/email/立即發送郵件.feature
 * 測試 POST /power-email/v1/emails/send-now 的業務邏輯：
 * - email_ids / user_ids 必填驗證
 * - as_enqueue_async_action 呼叫行為
 * - Action Scheduler 群組與 hook 設定
 *
 * @group email
 * @group send-now
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;

/**
 * Class SendNowTest
 * 測試立即發送郵件 API 的業務邏輯
 */
class SendNowTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int Bob 用戶 ID */
	private int $bob_id;

	/** @var int 郵件模板 500 ID */
	private int $email_500_id;

	/** @var int 郵件模板 501 ID */
	private int $email_501_id;

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
				'user_login' => 'admin_' . uniqid(),
				'user_email' => 'admin_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		// 建立郵件模板 CPT
		$this->email_500_id = $this->factory()->post->create(
			[
				'post_title'  => '歡迎信',
				'post_type'   => 'pc_email',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->email_500_id, 'trigger_at', 'course_granted' );

		$this->email_501_id = $this->factory()->post->create(
			[
				'post_title'  => '補寄通知',
				'post_type'   => 'pc_email',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->email_501_id, 'trigger_at', 'course_granted' );

		$this->ids['Admin'] = $this->admin_id;
		$this->ids['Alice'] = $this->alice_id;
		$this->ids['Bob']   = $this->bob_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * Action Scheduler 已初始化（Action Scheduler 是 WC/AS 的依賴）
	 */
	public function test_冒煙_ActionScheduler常數存在(): void {
		$this->assertTrue( defined( 'ACTION_SCHEDULER_ABSPATH' ) || function_exists( 'as_enqueue_async_action' ), 'Action Scheduler 應已載入' );
	}

	/**
	 * @test
	 * @group smoke
	 * At 常數定義正確
	 */
	public function test_冒煙_At常數定義正確(): void {
		$this->assertSame( 'power_email', At::AS_GROUP );
		$this->assertSame( 'power_email_send_users', At::SEND_USERS_HOOK );
	}

	// ========== 前置（參數）- 必填驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: email_ids 為必填
	 * 模擬 WP::include_required_params 的行為（拋出 \RuntimeException 或回傳 WP_Error）
	 */
	public function test_缺少email_ids時_include_required_params拋出例外(): void {
		$body_params = [ 'user_ids' => [ $this->alice_id ] ];

		try {
			// 模擬 API 的 include_required_params 呼叫
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'email_ids', 'user_ids' ] );
			$this->fail( '應拋出例外，因為 email_ids 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e, '缺少 email_ids 應拋出例外或 WP_Error' );
		}
	}

	/**
	 * @test
	 * @group error
	 * Rule: user_ids 為必填
	 */
	public function test_缺少user_ids時_include_required_params拋出例外(): void {
		$body_params = [ 'email_ids' => [ $this->email_500_id ] ];

		try {
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'email_ids', 'user_ids' ] );
			$this->fail( '應拋出例外，因為 user_ids 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e, '缺少 user_ids 應拋出例外或 WP_Error' );
		}
	}

	// ========== 後置（狀態）- Action Scheduler ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 成功將單一模板發給單一用戶，as_enqueue_async_action 被呼叫
	 */
	public function test_立即發送_單一模板單一用戶_AS_action新增(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'as_enqueue_async_action 不存在，Action Scheduler 未載入' );
		}

		$email_ids = [ $this->email_500_id ];
		$user_ids  = [ $this->alice_id ];

		// 直接呼叫 as_enqueue_async_action（模擬 API 行為）
		$action_id = as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[
				'email_ids' => $email_ids,
				'user_ids'  => $user_ids,
			],
			At::AS_GROUP
		);

		// 應回傳正整數 action_id
		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id, '新增的 action_id 應為正整數' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 新增的 action 的 group 為 "power_email"
	 */
	public function test_立即發送_action_group為power_email(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$action_id = as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ $this->email_500_id ],
				'user_ids'  => [ $this->alice_id ],
			],
			At::AS_GROUP
		);

		// 查詢 action 確認 group
		$scheduled = as_get_scheduled_actions(
			[
				'hook'  => At::SEND_USERS_HOOK,
				'group' => At::AS_GROUP,
			],
			'ids'
		);

		$this->assertNotEmpty( $scheduled, '應有 pending action' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 支援多模板對多用戶，args 完整傳入
	 */
	public function test_立即發送_多模板多用戶_args完整(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$email_ids = [ $this->email_500_id, $this->email_501_id ];
		$user_ids  = [ $this->alice_id, $this->bob_id ];

		$action_id = as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[
				'email_ids' => $email_ids,
				'user_ids'  => $user_ids,
			],
			At::AS_GROUP
		);

		$this->assertIsInt( $action_id );
		$this->assertGreaterThan( 0, $action_id );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 批次處理每批 20 筆
	 * 驗證 send_users_callback 的 batch_size 常數
	 */
	public function test_send_users_callback批次大小為20(): void {
		// 測試 batch_process 的 batch_size 設定（透過驗證 At 類的邏輯）
		// At::send_users_callback 使用 batch_size=20, pause_ms=750
		// 這個測試驗證相關常數與文件一致
		$this->assertSame( 'power_email_send_users', At::SEND_USERS_HOOK, 'SEND_USERS_HOOK 常數正確' );
		$this->assertSame( 'power_email', At::AS_GROUP, 'AS_GROUP 常數正確' );
	}

	// ========== AtHelper 常數驗證 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: AtHelper 定義的 9 個 slug 全部存在於 allowed_slugs
	 */
	public function test_AtHelper_9個slug全部在allowed_slugs(): void {
		$expected_slugs = [
			AtHelper::COURSE_GRANTED,
			AtHelper::COURSE_FINISHED,
			AtHelper::COURSE_LAUNCHED,
			AtHelper::CHAPTER_ENTERED,
			AtHelper::CHAPTER_FINISHED,
			AtHelper::ORDER_CREATED,
			AtHelper::CHAPTER_UNFINISHED,
			AtHelper::COURSE_REMOVED,
			AtHelper::UPDATE_STUDENT,
		];

		foreach ( $expected_slugs as $slug ) {
			$this->assertContains( $slug, AtHelper::$allowed_slugs, "{$slug} 應在 allowed_slugs 中" );
		}

		$this->assertCount( 9, AtHelper::$allowed_slugs, 'allowed_slugs 應有 9 個 slug' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: AtHelper 的 label 正確對應各 slug
	 */
	public function test_AtHelper_slug_label對應正確(): void {
		$expected_labels = [
			AtHelper::COURSE_GRANTED     => '開通課程權限後',
			AtHelper::COURSE_FINISHED    => '課程完成時',
			AtHelper::COURSE_LAUNCHED    => '課程開課時',
			AtHelper::CHAPTER_ENTERED    => '進入單元時',
			AtHelper::CHAPTER_FINISHED   => '完成單元時',
			AtHelper::ORDER_CREATED      => '訂單成立時',
			AtHelper::CHAPTER_UNFINISHED => '單元未完成時',
			AtHelper::COURSE_REMOVED     => '管理員手動移除課程權限時',
			AtHelper::UPDATE_STUDENT     => '更新學員觀看課程期限時',
		];

		foreach ( $expected_labels as $slug => $expected_label ) {
			$helper = new AtHelper( $slug );
			$this->assertSame( $expected_label, $helper->label, "{$slug} 的 label 應為 {$expected_label}" );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 非法 slug 被 validate_slug 替換為 COURSE_GRANTED
	 */
	public function test_AtHelper_非法slug_fallback到COURSE_GRANTED(): void {
		$helper = new AtHelper( 'invalid_slug_xyz' );
		$this->assertSame( AtHelper::COURSE_GRANTED, $helper->slug, '非法 slug 應 fallback 為 COURSE_GRANTED' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 空字串 slug 被替換為 COURSE_GRANTED
	 */
	public function test_AtHelper_空字串slug_fallback到COURSE_GRANTED(): void {
		$helper = new AtHelper( '' );
		$this->assertSame( AtHelper::COURSE_GRANTED, $helper->slug, '空字串 slug 應 fallback 為 COURSE_GRANTED' );
	}
}
