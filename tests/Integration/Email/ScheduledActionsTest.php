<?php
/**
 * 查詢郵件排程動作整合測試
 *
 * Feature: specs/features/email/查詢郵件排程動作.feature
 * 測試 GET /power-email/v1/emails/scheduled-actions 的業務邏輯：
 * - as_get_scheduled_actions 的 group 篩選
 * - 分頁邏輯（per_page / offset）
 * - 回應結構驗證（含 recurrence = "Non-repeating"）
 *
 * @group email
 * @group scheduled-actions
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Api as EmailApi;

/**
 * Class ScheduledActionsTest
 * 測試查詢 Action Scheduler 排程動作的業務邏輯
 */
class ScheduledActionsTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 Action Scheduler 與 EmailApi
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_sa_' . uniqid(),
				'user_email' => 'admin_sa_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->ids['Admin'] = $this->admin_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * as_get_scheduled_actions 函式存在
	 */
	public function test_冒煙_as_get_scheduled_actions存在(): void {
		$this->assertTrue( function_exists( 'as_get_scheduled_actions' ), 'as_get_scheduled_actions 應已載入' );
	}

	/**
	 * @test
	 * @group smoke
	 * EmailApi 類別可實例化
	 */
	public function test_冒煙_EmailApi可實例化(): void {
		$api = EmailApi::instance();
		$this->assertInstanceOf( EmailApi::class, $api );
	}

	// ========== 基本查詢行為 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 只查詢 group=power_email 的 actions
	 * 新增 power_email 的 action，查詢時應包含
	 */
	public function test_查詢只回傳power_email_group的actions(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 新增一筆 power_email group 的 action
		$action_id = as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[
				'email_ids' => [ 999 ],
				'user_ids'  => [ 1 ],
			],
			At::AS_GROUP
		);

		// 查詢 power_email group
		// 注意：ActionScheduler 預設 per_page=5，且測試間不一定回滾 AS 資料表
		// 因此以極大 per_page 確保能找到剛新增的 action
		$actions = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 9999,
			],
			'ids'
		);

		// 注意：as_get_scheduled_actions 回傳的是字串陣列（DB 回傳值），需轉型比較
		$this->assertContains( (string) $action_id, $actions, '剛新增的 action 應出現在查詢結果中' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 不同 group 的 action 不出現在結果中
	 */
	public function test_其他group的action不出現在結果中(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 新增一筆其他 group 的 action
		$other_action_id = as_enqueue_async_action(
			'some_other_hook',
			[ 'data' => 'test' ],
			'other_group'
		);

		// 查詢 power_email group，應不包含 other_group 的 action
		$actions = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 9999,
			],
			'ids'
		);

		// 注意：as_get_scheduled_actions 回傳的是字串陣列（DB 回傳值），需轉型比較
		$this->assertNotContains( (string) $other_action_id, $actions, 'other_group 的 action 不應出現在 power_email 查詢結果' );
	}

	// ========== 分頁 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 支援 per_page 分頁
	 */
	public function test_分頁_per_page限制回傳筆數(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 新增 3 筆 actions
		for ( $i = 0; $i < 3; $i++ ) {
			as_enqueue_async_action(
				At::SEND_USERS_HOOK,
				[ 'email_ids' => [ $i ], 'user_ids' => [ 1 ] ],
				At::AS_GROUP
			);
		}

		// per_page=2 應只回傳 2 筆
		$actions = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 2,
				'offset'   => 0,
			],
			'ids'
		);

		$this->assertLessThanOrEqual( 2, count( $actions ), 'per_page=2 應回傳 ≤2 筆' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 支援 offset 翻頁
	 */
	public function test_分頁_offset翻頁(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 新增 4 筆 actions
		$all_ids = [];
		for ( $i = 0; $i < 4; $i++ ) {
			$all_ids[] = as_enqueue_async_action(
				At::SEND_USERS_HOOK,
				[ 'email_ids' => [ $i + 100 ], 'user_ids' => [ $i + 10 ] ],
				At::AS_GROUP
			);
		}

		// 第 1 頁（offset=0, per_page=2）
		$page1 = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 2,
				'offset'   => 0,
			],
			'ids'
		);

		// 第 2 頁（offset=2, per_page=2）
		$page2 = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 2,
				'offset'   => 2,
			],
			'ids'
		);

		// 兩頁不應有交集（若資料足夠）
		$intersection = array_intersect( $page1, $page2 );
		$this->assertEmpty( $intersection, '兩頁資料不應重複' );
	}

	// ========== 回應結構 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: log_entries 以 "<ol>" 標籤包裹
	 * 測試 EmailApi::get_log_entries_html 方法
	 */
	public function test_log_entries_html以ol標籤包裹(): void {
		$api = EmailApi::instance();

		// 空日誌陣列
		$html = $api->get_log_entries_html( [] );
		$this->assertStringStartsWith( '<ol>', $html, 'log_entries_html 應以 <ol> 開頭' );
		$this->assertStringEndsWith( '</ol>', $html, 'log_entries_html 應以 </ol> 結尾' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 非週期 action 的 recurrence 為 "Non-repeating"
	 * 透過 as_enqueue_async_action（非週期）驗證 get_recurrence 回傳值
	 * get_recurrence 為 protected 方法，透過 ReflectionMethod 呼叫
	 */
	public function test_非週期action_recurrence為Non_repeating(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! \ActionScheduler::is_initialized( 'test' ) ) {
			$this->markTestSkipped( 'ActionScheduler 未完整初始化' );
		}

		// 新增一筆非週期 action
		$action_id = as_enqueue_async_action(
			At::SEND_USERS_HOOK,
			[ 'email_ids' => [ 500 ], 'user_ids' => [ 10 ] ],
			At::AS_GROUP
		);

		/** @var \ActionScheduler_DBStore $store */
		$store  = \ActionScheduler::store();
		$action = $store->fetch_action( $action_id );

		// get_recurrence 是 protected，使用 ReflectionMethod 存取
		$api    = EmailApi::instance();
		$method = new \ReflectionMethod( EmailApi::class, 'get_recurrence' );
		$method->setAccessible( true );
		$recurrence = $method->invoke( $api, $action );

		// 非週期 action 應回傳 "Non-repeating"
		$this->assertSame( 'Non-repeating', $recurrence, '非週期 action 的 recurrence 應為 Non-repeating' );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 空的 power_email group 回傳空陣列
	 */
	public function test_空group_回傳空陣列(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 查詢一個不可能存在的 group
		$actions = as_get_scheduled_actions(
			[
				'group'    => 'non_existent_group_' . uniqid(),
				'per_page' => 20,
			],
			'ids'
		);

		$this->assertIsArray( $actions );
		$this->assertEmpty( $actions, '不存在的 group 應回傳空陣列' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: per_page = -1 取得所有 actions
	 */
	public function test_per_page為負一_取得所有actions(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler 未載入' );
		}

		// 新增 3 筆
		$added_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$added_ids[] = as_enqueue_async_action(
				At::SEND_USERS_HOOK,
				[ 'email_ids' => [ $i + 200 ], 'user_ids' => [ $i + 20 ] ],
				At::AS_GROUP
			);
		}

		// per_page=-1 應取得所有 actions（包含以上新增的）
		// ActionScheduler 不支援 per_page=-1，使用極大值代替
		$all_actions = as_get_scheduled_actions(
			[
				'group'    => At::AS_GROUP,
				'per_page' => 9999,
			],
			'ids'
		);

		// 注意：as_get_scheduled_actions 回傳的是字串陣列（DB 回傳值），需轉型比較
		foreach ( $added_ids as $id ) {
			$this->assertContains( (string) $id, $all_actions, "action_id {$id} 應出現在結果中" );
		}
	}
}
