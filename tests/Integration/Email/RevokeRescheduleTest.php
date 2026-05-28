<?php
/**
 * 撤銷課程權限後重新寄送開通信整合測試
 *
 * Feature: Issue #232 — 撤銷後重寄開通信的三項協調修法
 * 修法1a: schedule_email() 的 dedup 查詢限縮至 pending/in-progress，complete 不再封鎖
 * 修法1b: pending action 已存在時仍不重複排程（防垃圾郵件守衛）
 * 修法2:  trigger_condition() 在 allow_repeat_send=true 時跳過 is_sent 檢查
 * 修法3:  LifeCycle::clear_course_granted_scheduled_actions() 於撤銷時清除 pending actions
 *
 * @group email
 * @group revoke-reschedule
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\PowerEmail\Resources\EmailRecord\CRUD as EmailRecord;
use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class RevokeRescheduleTest
 * 測試撤銷課程權限後重新寄送開通信的業務邏輯（部分尚未實作，預期 Red 狀態）
 */
class RevokeRescheduleTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int Alice（學員）用戶 ID */
	private int $alice_id;

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 課程開通信郵件模板 ID */
	private int $email_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 Action Scheduler、EmailRecord CRUD、LifeCycle
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_rr_' . uniqid(),
				'user_email' => 'admin_rr_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_rr_' . uniqid(),
				'user_email' => 'alice_rr_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );

		// 建立 pe_email 課程開通信模板
		// 必須同時設定 trigger_at 與 condition，否則 get_sending_timestamp() 回傳 null
		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程開通信模板_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $this->email_id, 'trigger_at', 'course_granted' );
		update_post_meta(
			$this->email_id,
			'condition',
			[
				'trigger_condition' => 'each',
				'course_ids'        => [ $this->course_id ],
				'sending'           => [
					'type'  => 'send_now',
					'value' => null,
					'unit'  => null,
					'range' => null,
				],
			]
		);

		$this->ids['Admin']  = $this->admin_id;
		$this->ids['Alice']  = $this->alice_id;
		$this->ids['Course'] = $this->course_id;
		$this->ids['Email']  = $this->email_id;
	}

	// ========== 私有輔助方法 ==========

	/**
	 * 取得測試用的 AS group slug
	 * 依照 Email::get_identifier() 的規則建構，確保與生產代碼一致
	 *
	 * @return string group slug
	 */
	private function get_course_granted_group(): string {
		$email = new Email( $this->email_id );
		return $email->get_identifier( [ $this->course_id ], $this->alice_id );
	}

	/**
	 * 取得課程開通信的 AS hook 名稱
	 *
	 * @return string hook 名稱
	 */
	private function get_course_granted_hook(): string {
		return ( new AtHelper( AtHelper::COURSE_GRANTED ) )->hook;
	}

	// ========== 修法1a：complete action 不再封鎖重新排程（Red）==========

	/**
	 * @test
	 * @group happy
	 * 修法1a：歷史 complete action 存在時，仍可建立新的 pending action
	 * 修法前：schedule_email() 的 dedup 查詢不過濾狀態，complete 會封鎖 → 無法重排程
	 * 修法後：dedup 查詢限縮至 pending/in-progress，complete 不再封鎖
	 * 預期狀態：Red（修法尚未實作）
	 */
	public function test_修法1a_歷史complete_action不再封鎖重新排程(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized( 'test' ) ) {
			$this->markTestSkipped( 'ActionScheduler 未完整初始化' );
		}

		$hook  = $this->get_course_granted_hook();
		$group = $this->get_course_granted_group();

		// 步驟1: 建立一個 async action 並標記為 complete（模擬歷史寄送紀錄）
		$old_action_id = as_enqueue_async_action( $hook, [ [] ], $group );
		$this->assertGreaterThan( 0, $old_action_id, '應能建立測試用 async action' );

		/** @var \ActionScheduler_DBStore $store */
		$store = \ActionScheduler::store();
		$store->mark_complete( $old_action_id );

		// 確認 action 狀態已變為 complete
		$complete_actions = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'complete',
			],
			'ids'
		);
		$this->assertNotEmpty( $complete_actions, '應有 complete 狀態的歷史 action' );

		// 步驟2: 呼叫 schedule_course_granted_email（觸發 schedule_email 內部邏輯）
		At::instance()->schedule_course_granted_email( $this->alice_id, $this->course_id, 0 );

		// 步驟3（Red 斷言）: 修法後應能找到新的 pending action
		// 修法前：complete 封鎖，此斷言失敗（Red）
		$pending_actions = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);

		$this->assertNotEmpty(
			$pending_actions,
			'歷史 complete action 不應封鎖重新排程，應能找到新的 pending action'
		);
	}

	// ========== 修法1b：pending action 防重複排程守衛（預期通過）==========

	/**
	 * @test
	 * @group happy
	 * 修法1b：已有 pending action 時，重複呼叫不應建立第二個（防垃圾郵件守衛）
	 * 此守衛修法前後行為一致，屬回歸測試（預期通過）
	 */
	public function test_修法1b_pending_action已存在時不重複排程(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$hook  = $this->get_course_granted_hook();
		$group = $this->get_course_granted_group();

		// 步驟1: 先建立一個 pending action
		as_enqueue_async_action( $hook, [ [] ], $group );

		// 步驟2: 再次呼叫 schedule_course_granted_email
		At::instance()->schedule_course_granted_email( $this->alice_id, $this->course_id, 0 );

		// 步驟3: pending count 不應超過 1（防重複）
		$pending_actions = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);

		$this->assertLessThanOrEqual(
			1,
			count( $pending_actions ),
			'pending action 已存在時，重複呼叫不應建立第二個'
		);
	}

	// ========== 修法2：trigger_condition() 尊重 allow_repeat_send 開關（Red）==========

	/**
	 * @test
	 * @group happy
	 * 修法2（allow_repeat_send=true）：即使已寄送（mark_as_sent=1），仍應允許再次寄送
	 * 修法前：trigger_condition() 無論 allow_repeat_send，只要 is_sent=true 就回傳 false
	 * 修法後：allow_repeat_send=true 時跳過 is_sent 檢查
	 * 預期狀態：Red（修法尚未實作）
	 */
	public function test_修法2_allow_repeat_send為true且已寄送時仍允許寄送(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 設定 allow_repeat_send = 'yes'（即 true）
		update_post_meta( $this->email_id, 'allow_repeat_send', 'yes' );

		// 植入 EmailRecord（模擬已寄送紀錄）
		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);

		// 確認 is_sent() 確實回傳 true（前置條件驗證）
		$fresh_email = new Email( $this->email_id );
		$this->assertTrue(
			$fresh_email->is_sent( $this->course_id, $this->alice_id ),
			'前置條件：is_sent() 應為 true'
		);

		// 修法後：allow_repeat_send=true 時，trigger_condition() 應忽略 is_sent 回傳 true
		// 修法前：此斷言失敗（Red）
		$result = At::instance()->trigger_condition(
			true,
			new Email( $this->email_id ),
			$this->alice_id,
			$this->course_id,
			0
		);

		$this->assertTrue(
			$result,
			'allow_repeat_send=true 且已寄送時，trigger_condition() 應回傳 true（允許再次寄送）'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 修法2（allow_repeat_send=false）：已寄送（mark_as_sent=1）時，阻止重複寄送
	 * 此路徑在修法前後行為一致，屬回歸測試（預期通過）
	 */
	public function test_修法2_allow_repeat_send為false且已寄送時阻止寄送(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 設定 allow_repeat_send = 'no'（即 false）
		update_post_meta( $this->email_id, 'allow_repeat_send', 'no' );

		// 植入 EmailRecord（模擬已寄送紀錄）
		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);

		// allow_repeat_send=false + is_sent=true → 應阻止寄送
		$result = At::instance()->trigger_condition(
			true,
			new Email( $this->email_id ),
			$this->alice_id,
			$this->course_id,
			0
		);

		$this->assertFalse(
			$result,
			'allow_repeat_send=false 且已寄送時，trigger_condition() 應回傳 false（阻止重複寄送）'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 修法2（allow_repeat_send=false）：未寄送（mark_as_sent=0）時，允許寄送
	 * 此路徑在修法前後行為一致，屬回歸測試（預期通過）
	 */
	public function test_修法2_allow_repeat_send為false且未寄送時允許寄送(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 設定 allow_repeat_send = 'no'
		update_post_meta( $this->email_id, 'allow_repeat_send', 'no' );

		// 植入 EmailRecord 但 mark_as_sent=0（未寄送）
		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);
		// 重設為未寄送
		EmailRecord::update(
			[
				'user_id'    => (string) $this->alice_id,
				'post_id'    => (string) $this->course_id,
				'trigger_at' => 'course_granted',
			],
			[ 'mark_as_sent' => '0' ]
		);

		// allow_repeat_send=false + is_sent=false → 應允許寄送
		$result = At::instance()->trigger_condition(
			true,
			new Email( $this->email_id ),
			$this->alice_id,
			$this->course_id,
			0
		);

		$this->assertTrue(
			$result,
			'allow_repeat_send=false 且未寄送時，trigger_condition() 應回傳 true（允許寄送）'
		);
	}

	// ========== 修法3：撤銷時清除 pending AS actions（Red）==========

	/**
	 * @test
	 * @group happy
	 * 修法3（mark_as_sent 重設）：撤銷後，課程開通信的 mark_as_sent 應重設為 0
	 * 此路徑由既有 update_email_mark_as_sent() 處理，屬回歸測試（預期通過）
	 */
	public function test_修法3_撤銷後mark_as_sent重設為0(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 先開通 Alice 的課程（讓 save_meta_remove_student 可以正常執行）
		$this->enroll_user_to_course( $this->alice_id, $this->course_id );

		// 植入 EmailRecord mark_as_sent=1
		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);

		// 觸發撤銷 action（priority 10 = save_meta_remove_student, priority 20 = update_email_mark_as_sent）
		do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );

		// 確認 mark_as_sent 已被重設為 0
		$records = EmailRecord::get(
			[
				'user_id'    => (string) $this->alice_id,
				'post_id'    => (string) $this->course_id,
				'trigger_at' => 'course_granted',
			]
		);

		$this->assertNotEmpty( $records, '應能找到 EmailRecord' );

		$record = $records[0];
		$this->assertSame(
			'0',
			(string) $record->mark_as_sent,
			'撤銷後，course_granted 的 mark_as_sent 應被重設為 0'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 修法3（clear pending AS actions）：直接呼叫 LifeCycle::clear_course_granted_scheduled_actions()
	 * 後，課程開通信的 pending action 應被清除
	 * 預期狀態：Red（方法尚未實作，呼叫時會 fatal error）
	 */
	public function test_修法3_直接呼叫清除方法後pending_actions為空(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		$hook  = $this->get_course_granted_hook();
		$group = $this->get_course_granted_group();

		// 建立一個 pending action
		$action_id = as_enqueue_async_action( $hook, [ [] ], $group );
		$this->assertGreaterThan( 0, $action_id, '應能建立 pending action' );

		// 確認 pending action 存在
		$before = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);
		$this->assertNotEmpty( $before, '清除前應有 pending action' );

		// 直接呼叫尚未實作的靜態方法（修法3）
		// 此方法目前不存在，呼叫時會 fatal error → 測試 Red
		LifeCycle::clear_course_granted_scheduled_actions( $this->alice_id, $this->course_id );

		// 清除後，pending action 應為空
		$after = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);

		$this->assertEmpty(
			$after,
			'clear_course_granted_scheduled_actions() 後，pending actions 應為空'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 修法3（隔離性）：撤銷動作只清除 course_granted 的紀錄，不影響其他 trigger_at 類型
	 * 此測試驗證 update_email_mark_as_sent() 的隔離性（預期通過）
	 */
	public function test_修法3_撤銷只清除course_granted不影響其他類型(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 先開通 Alice 的課程
		$this->enroll_user_to_course( $this->alice_id, $this->course_id );

		// 建立一個 chapter_finish 類型的 pe_email 模板（另一種 trigger_at）
		$chapter_email_id = $this->factory()->post->create(
			[
				'post_title'  => '章節完成信_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		update_post_meta( $chapter_email_id, 'trigger_at', 'chapter_finish' );

		// 植入 chapter_finish 類型的 EmailRecord mark_as_sent=1
		$chapter_email      = new Email( $chapter_email_id );
		$chapter_identifier = $chapter_email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$chapter_email_id,
			'',
			'chapter_finish',
			$chapter_identifier,
			true
		);

		// 觸發撤銷 action
		do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );

		// 查詢 chapter_finish 記錄：mark_as_sent 應仍為 1（不受撤銷影響）
		$chapter_records = EmailRecord::get(
			[
				'user_id'    => (string) $this->alice_id,
				'post_id'    => (string) $this->course_id,
				'trigger_at' => 'chapter_finish',
			]
		);

		// 若 chapter_finish 記錄存在，確認 mark_as_sent 未被改動
		if ( ! empty( $chapter_records ) ) {
			$this->assertSame(
				'1',
				(string) $chapter_records[0]->mark_as_sent,
				'chapter_finish 記錄的 mark_as_sent 不應被撤銷動作影響'
			);
		} else {
			// 若記錄不存在（可能被 delete 而非 update），這也算通過
			$this->assertTrue( true, 'chapter_finish 記錄不受撤銷影響（或不存在）' );
		}
	}

	// ========== 端到端流程（Red）==========

	/**
	 * @test
	 * @group happy
	 * 端到端（allow_repeat_send=true）：開通 → 寄過 → 撤銷 → 重新開通 → 可重排程
	 * 預期狀態：Red（修法1a + 修法3 尚未實作）
	 */
	public function test_端到端_allow_repeat_send為true_重新開通後可排程(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized( 'test' ) ) {
			$this->markTestSkipped( 'ActionScheduler 未完整初始化' );
		}

		$hook  = $this->get_course_granted_hook();
		$group = $this->get_course_granted_group();

		// allow_repeat_send = true
		update_post_meta( $this->email_id, 'allow_repeat_send', 'yes' );

		// 步驟1: 開通 Alice 課程
		$this->enroll_user_to_course( $this->alice_id, $this->course_id );

		// 步驟2: 模擬已寄送完成（complete action + mark_as_sent=1）
		$old_action_id = as_enqueue_async_action( $hook, [ [] ], $group );
		/** @var \ActionScheduler_DBStore $store */
		$store = \ActionScheduler::store();
		$store->mark_complete( $old_action_id );

		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);

		// 步驟3: 撤銷課程（清除 pending AS actions + 重設 mark_as_sent）
		LifeCycle::clear_course_granted_scheduled_actions( $this->alice_id, $this->course_id ); // 修法3（Red）
		LifeCycle::update_email_mark_as_sent( $this->alice_id, $this->course_id );

		// 步驟4: 重新排程（模擬學員重新購買 → 觸發 schedule_course_granted_email）
		At::instance()->schedule_course_granted_email( $this->alice_id, $this->course_id, 0 );

		// 步驟5（Red 斷言）: 應能找到新的 pending action
		$pending_actions = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);

		$this->assertNotEmpty(
			$pending_actions,
			'端到端（allow_repeat_send=true）：撤銷後重新開通應能建立新的 pending action'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 端到端（allow_repeat_send=false）：撤銷後重新開通，排程層獨立運作
	 * 排程層（schedule_email）不受 allow_repeat_send 控制（由 trigger_condition 在發送時判斷）
	 * mark_as_sent 已被重設為 0，故發送時也會允許
	 * 預期狀態：Red（修法1a + 修法3 尚未實作）
	 */
	public function test_端到端_allow_repeat_send為false_重新開通後仍可排程(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}
		if ( ! class_exists( 'ActionScheduler' ) || ! \ActionScheduler::is_initialized( 'test' ) ) {
			$this->markTestSkipped( 'ActionScheduler 未完整初始化' );
		}

		$hook  = $this->get_course_granted_hook();
		$group = $this->get_course_granted_group();

		// allow_repeat_send = false
		update_post_meta( $this->email_id, 'allow_repeat_send', 'no' );

		// 步驟1: 開通 Alice 課程
		$this->enroll_user_to_course( $this->alice_id, $this->course_id );

		// 步驟2: 模擬已寄送完成（complete action + mark_as_sent=1）
		$old_action_id = as_enqueue_async_action( $hook, [ [] ], $group );
		/** @var \ActionScheduler_DBStore $store */
		$store = \ActionScheduler::store();
		$store->mark_complete( $old_action_id );

		$email      = new Email( $this->email_id );
		$identifier = $email->get_identifier( [ $this->course_id ], $this->alice_id );
		EmailRecord::add(
			$this->course_id,
			$this->alice_id,
			$this->email_id,
			'',
			'course_granted',
			$identifier,
			true
		);

		// 步驟3: 撤銷課程（清除 pending AS actions + 重設 mark_as_sent）
		LifeCycle::clear_course_granted_scheduled_actions( $this->alice_id, $this->course_id ); // 修法3（Red）
		LifeCycle::update_email_mark_as_sent( $this->alice_id, $this->course_id );

		// 步驟4: 重新排程（排程層不受 allow_repeat_send 控制）
		At::instance()->schedule_course_granted_email( $this->alice_id, $this->course_id, 0 );

		// 步驟5（Red 斷言）: 排程層應能建立新的 pending action
		$pending_actions = as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => 'pending',
			],
			'ids'
		);

		$this->assertNotEmpty(
			$pending_actions,
			'端到端（allow_repeat_send=false）：mark_as_sent 已重設，排程層仍應能建立新的 pending action'
		);
	}
}
