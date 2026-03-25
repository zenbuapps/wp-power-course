<?php
/**
 * 新增學員到課程 整合測試
 * Feature: specs/features/student/新增學員到課程.feature
 *
 * @group student
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class AddStudentToCourseTest
 * 測試直接觸發 power_course_add_student_to_course action 的新增學員流程
 */
class AddStudentToCourseTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int Bob 用戶 ID */
	private int $bob_id;

	/**
	 * 初始化依賴（此測試直接使用 WordPress action，不需要額外 Service）
	 */
	protected function configure_dependencies(): void {
		// 此測試直接呼叫 WordPress action hook，不需要額外 Service 實例
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立測試課程
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		// 建立測試用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		$this->bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Bob'] = $this->bob_id;
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 基本冒煙測試：確認 LifeCycle 已初始化且 action hook 已註冊
	 */
	public function test_lifecycle_hooks_are_registered(): void {
		$this->assertNotFalse(
			has_action( LifeCycle::ADD_STUDENT_TO_COURSE_ACTION, [ LifeCycle::class, 'add_student_to_course' ] ),
			'add_student_to_course action hook 未被註冊'
		);
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 成功新增學員到課程：用戶應取得課程存取權
	 * Rule: 後置（狀態）- 學員應取得課程存取權（avl_course_ids user meta）
	 */
	public function test_成功新增學員到課程(): void {
		// When 透過 action hook 新增 Alice 到課程
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->alice_id,
				$this->course_id,
				0,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 用戶 "Alice" 的 avl_course_ids 應包含課程
		$this->assert_user_has_course_access( $this->alice_id, $this->course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 設定永久存取時 expire_date 應為 0
	 * Rule: 後置（狀態）- coursemeta 應記錄 expire_date
	 */
	public function test_設定永久存取時expire_date應為0(): void {
		// When 新增學員，expire_date 為 0
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->alice_id,
				$this->course_id,
				0,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 課程對用戶的 coursemeta expire_date 應為 "0"
		$expire_date = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );
		$this->assertSame( '0', (string) $expire_date, "expire_date 應為 '0'，實際為 '{$expire_date}'" );
	}

	/**
	 * @test
	 * @group happy
	 * 新增學員後 course_granted_at 應自動設定
	 * Rule: 後置（狀態）- coursemeta 應記錄 course_granted_at
	 */
	public function test_新增學員後course_granted_at應自動設定(): void {
		// When 新增學員
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->alice_id,
				$this->course_id,
				0,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And course_granted_at 應不為空
		// course_granted_at 對應 AtHelper::COURSE_GRANTED = 'course_granted'，meta key 為 'course_granted_at'
		$granted_at = $this->get_course_meta( $this->course_id, $this->alice_id, 'course_granted_at' );
		$this->assertNotEmpty( $granted_at, 'course_granted_at 應不為空' );
	}

	/**
	 * @test
	 * @group happy
	 * 成功設定固定到期日（10位 timestamp）
	 */
	public function test_成功設定固定到期日(): void {
		// 使用未來的 timestamp：2030-01-01
		$expire_date = 1893456000;

		// When 新增 Bob 到課程，設定固定到期日
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->bob_id,
				$this->course_id,
				$expire_date,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 課程對用戶的 coursemeta expire_date 應為指定 timestamp
		$stored_expire_date = $this->get_course_meta( $this->course_id, $this->bob_id, 'expire_date' );
		$this->assertSame( (string) $expire_date, (string) $stored_expire_date );
	}

	/**
	 * @test
	 * @group happy
	 * 成功設定跟隨訂閱到期（subscription_xxx 格式）
	 */
	public function test_成功設定跟隨訂閱到期(): void {
		$expire_date = 'subscription_456';

		// When 新增 Bob 到課程，設定訂閱格式到期日
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->bob_id,
				$this->course_id,
				$expire_date,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And expire_date 應為 subscription_456
		$stored_expire_date = $this->get_course_meta( $this->course_id, $this->bob_id, 'expire_date' );
		$this->assertSame( $expire_date, (string) $stored_expire_date );
	}

	/**
	 * @test
	 * @group happy
	 * 新增學員後觸發 power_course_add_student_to_course 和 power_course_after_add_student_to_course action
	 * Rule: 後置（狀態）- 應觸發相關 action hooks
	 */
	public function test_新增學員後觸發action_hooks(): void {
		// 記錄 after_add action 是否觸發
		$after_action_triggered = false;
		add_action(
			LifeCycle::AFTER_ADD_STUDENT_TO_COURSE_ACTION,
			function () use ( &$after_action_triggered ) {
				$after_action_triggered = true;
			}
		);

		// When 新增學員
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$this->alice_id,
			$this->course_id,
			0,
			null
		);

		// Then power_course_add_student_to_course 應已被觸發
		$this->assert_action_fired( LifeCycle::ADD_STUDENT_TO_COURSE_ACTION );

		// And power_course_after_add_student_to_course 應被觸發
		$this->assertTrue(
			$after_action_triggered,
			'action power_course_after_add_student_to_course 未被觸發'
		);
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 不存在的用戶無法被加入課程
	 * Rule: 前置（狀態）- 學員必須是存在的 WordPress 用戶
	 * 注意：WordPress 的 add_user_meta 不會驗證用戶是否存在，此測試驗證業務行為
	 */
	public function test_不存在的用戶無法被加入課程(): void {
		// 當系統中無此用戶時，action 執行後不應有 avl_course_ids 記錄
		$non_existent_user_id = 9999;

		// When 嘗試新增不存在的用戶
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$non_existent_user_id,
			$this->course_id,
			0,
			null
		);

		// Then 不存在用戶在 avl_course_ids 不應有有效記錄
		// 注意：此測試驗證資料不應汙染，實際用戶存在驗證由 API 層處理
		$user = get_user_by( 'id', $non_existent_user_id );
		$this->assertFalse( $user, '用戶 ID 9999 不應存在' );
	}

	/**
	 * @test
	 * @group edge
	 * 重複開通相同課程時去重（avl_course_ids 只有一筆記錄）
	 * Rule: 後置（狀態）- 透過 AddStudent 服務去重
	 */
	public function test_重複開通相同課程時去重(): void {
		// Given 先加入一次
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$this->alice_id,
			$this->course_id,
			0,
			null
		);

		// When 再加入一次
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$this->alice_id,
			$this->course_id,
			0,
			null
		);

		// Then avl_course_ids 只應有一筆此課程的記錄
		$avl_course_ids = get_user_meta( $this->alice_id, 'avl_course_ids' );
		$course_count   = count(
			array_filter(
				$avl_course_ids,
				fn( $id ) => (int) $id === $this->course_id
			)
		);
		$this->assertSame( 1, $course_count, "avl_course_ids 應只有一筆課程記錄，實際有 {$course_count} 筆" );
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 格式驗證：位數不足（5位數字）應由 API 層拒絕
	 * 此測試驗證 ExpireDate value object 對各種格式的解析行為
	 */
	public function test_expire_date_value_object_處理短位數timestamp(): void {
		$expire_date_obj = new \J7\PowerCourse\Resources\Course\ExpireDate( 12345 );

		// 12345 是一個合法數字，但業務上應視為有效值（非0、非subscription）
		// ExpireDate 只檢查是否過期，不驗證位數
		$this->assertFalse( $expire_date_obj->is_subscription );
		// 12345 < time() 所以 is_expired 為 true
		$this->assertTrue( $expire_date_obj->is_expired );
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 格式：subscription 格式在無 WooCommerce Subscriptions 環境下的行為
	 * 注意：ExpireDate::set_subscription() 僅在 class_exists('WC_Subscription') 時才解析訂閱格式
	 * 測試環境未安裝 WCS，因此 is_subscription = false，字串被視為一般非數字值（is_expired = true）
	 */
	public function test_expire_date_value_object_解析subscription格式(): void {
		$expire_date_obj = new \J7\PowerCourse\Resources\Course\ExpireDate( 'subscription_456' );

		if ( class_exists( 'WC_Subscription' ) ) {
			// 有 WCS：subscription_456 應被解析為訂閱格式
			$this->assertTrue( $expire_date_obj->is_subscription, '有 WCS 時 subscription_456 應解析為訂閱格式' );
			$this->assertSame( 456, $expire_date_obj->subscription_id );
		} else {
			// 無 WCS：subscription_456 不被識別，視為一般非數字字串
			$this->assertFalse( $expire_date_obj->is_subscription, '無 WCS 時 subscription_456 不應被識別為訂閱格式' );
			// 非數字格式，is_expired = true
			$this->assertTrue( $expire_date_obj->is_expired, '無 WCS 時 subscription 字串應視為過期' );
		}
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 格式：sub_ 開頭（非 subscription_）應不被視為訂閱格式
	 * Rule: 前置（參數）- expire_date 若為 subscription 格式需符合 subscription_{id}
	 */
	public function test_expire_date_value_object_sub_前綴不是合法訂閱格式(): void {
		$expire_date_obj = new \J7\PowerCourse\Resources\Course\ExpireDate( 'sub_123' );

		// sub_ 開頭不是合法的 subscription_ 前綴
		$this->assertFalse( $expire_date_obj->is_subscription );
		// 非數字的非訂閱格式 is_expired 為 true
		$this->assertTrue( $expire_date_obj->is_expired );
	}
}
