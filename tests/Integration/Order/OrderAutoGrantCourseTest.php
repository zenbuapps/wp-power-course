<?php
/**
 * 訂單自動開通課程 整合測試
 * Feature: specs/features/order/訂單自動開通課程.feature
 *
 * @group order
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Order;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\LifeCycle;
use J7\PowerCourse\Resources\Course\Service\AddStudent;
use J7\PowerCourse\Resources\Course\Limit;

/**
 * Class OrderAutoGrantCourseTest
 * 測試訂單自動開通課程邏輯
 *
 * 注意：此測試聚焦在「業務核心邏輯」而非 WooCommerce 訂單完整流程
 * 完整的訂單流程（woocommerce_order_status_completed hook）由 E2E 測試覆蓋
 */
class OrderAutoGrantCourseTest extends TestCase {

	/** @var int 無限制課程 ID */
	private int $unlimited_course_id;

	/** @var int 固定時間課程 ID（30天）*/
	private int $fixed_course_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 WordPress actions 與 AddStudent service
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立無限制課程
		$this->unlimited_course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
				'limit_type' => 'unlimited',
			]
		);

		// 建立固定時間課程（30天）
		$this->fixed_course_id = $this->create_course(
			[
				'post_title' => 'React 課程',
				'_is_course' => 'yes',
				'limit_type' => 'fixed',
				'limit_value' => 30,
				'limit_unit'  => 'day',
			]
		);

		// 建立 Alice 用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * AddStudent 類別的基本運作：add_item 後 do_action 應觸發 hooks
	 */
	public function test_AddStudent_基本運作(): void {
		$add_student = new AddStudent();

		$action_triggered = false;
		add_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			function () use ( &$action_triggered ) {
				$action_triggered = true;
			}
		);

		$add_student->add_item( $this->alice_id, $this->unlimited_course_id, 0, null );
		$add_student->do_action();

		$this->assertTrue( $action_triggered, 'AddStudent::do_action() 應觸發 ADD_STUDENT_TO_COURSE_ACTION' );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 直接觸發 add_student_to_course action 開通無限制課程
	 * 驗證 expire_date = 0（無限制）
	 */
	public function test_開通無限制課程(): void {
		// When 觸發開通課程 action
		try {
			do_action(
				LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
				$this->alice_id,
				$this->unlimited_course_id,
				0,
				null
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 用戶有課程存取權
		$this->assert_user_has_course_access( $this->alice_id, $this->unlimited_course_id );

		// And expire_date 應為 0
		$expire_date = $this->get_course_meta( $this->unlimited_course_id, $this->alice_id, 'expire_date' );
		$this->assertSame( '0', (string) $expire_date );
	}

	/**
	 * @test
	 * @group happy
	 * Limit::calc_expire_date 計算固定時間（30天）的到期日
	 * Rule: 後置（狀態）- 訂單完成後應自動開通課程（固定到期日）
	 */
	public function test_Limit計算固定30天到期日(): void {
		$limit = new Limit( 'fixed', 30, 'day' );

		// When 計算到期日（不傳 order，因為 fixed 不需要）
		$expire_date = $limit->calc_expire_date( null );

		// Then 到期日應在 30 天後附近
		$this->assertIsInt( $expire_date );
		$expected_min = strtotime( '+29 days' );
		$expected_max = strtotime( '+31 days' );
		$this->assertGreaterThan( $expected_min, $expire_date, '到期日應在 30 天後' );
		$this->assertLessThan( $expected_max, $expire_date, '到期日不應超過 31 天後' );
	}

	/**
	 * @test
	 * @group happy
	 * Limit::calc_expire_date 計算無限制的到期日（應為 0）
	 */
	public function test_Limit計算無限制到期日(): void {
		$limit = new Limit( 'unlimited', null, null );

		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date, '無限制的到期日應為 0' );
	}

	/**
	 * @test
	 * @group happy
	 * 同時開通多個課程
	 * Rule: 後置（狀態）- 訂單完成後自動開通綁定的所有課程
	 */
	public function test_同時開通多個課程(): void {
		$add_student = new AddStudent();

		// When 加入兩個課程
		$add_student->add_item( $this->alice_id, $this->unlimited_course_id, 0, null );
		$add_student->add_item( $this->alice_id, $this->fixed_course_id, 1893456000, null );

		try {
			$add_student->do_action();
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 兩個課程都應被開通
		$this->assert_operation_succeeded();
		$this->assert_user_has_course_access( $this->alice_id, $this->unlimited_course_id );
		$this->assert_user_has_course_access( $this->alice_id, $this->fixed_course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 開通課程後觸發 power_course_after_add_student_to_course action
	 * Rule: 後置（狀態）- 開通課程後觸發 LifeCycle::ADD_STUDENT_TO_COURSE_ACTION
	 */
	public function test_開通課程後觸發after_add_action(): void {
		$after_action_count = 0;
		add_action(
			LifeCycle::AFTER_ADD_STUDENT_TO_COURSE_ACTION,
			function () use ( &$after_action_count ) {
				$after_action_count++;
			}
		);

		// When 開通課程
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$this->alice_id,
			$this->unlimited_course_id,
			0,
			null
		);

		// Then after_add action 應被觸發一次
		$this->assertGreaterThan( 0, $after_action_count, 'power_course_after_add_student_to_course 應被觸發' );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 * AddStudent 去重功能：相同 user + course 不應重複加入
	 * Rule: 後置（狀態）- 透過 AddStudent 服務去重
	 */
	public function test_AddStudent_去重功能(): void {
		$add_student = new AddStudent();

		// 加入同一課程兩次
		$add_student->add_item( $this->alice_id, $this->unlimited_course_id, 0, null );
		$add_student->add_item( $this->alice_id, $this->unlimited_course_id, 1893456000, null ); // 不同 expire_date，但 user+course 相同

		$action_count = 0;
		add_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			function ( $user_id, $course_id ) use ( $add_student, &$action_count ) {
				if ( $user_id === $this->alice_id && $course_id === $this->unlimited_course_id ) {
					$action_count++;
				}
			},
			5, // 比預設優先級更高
			2
		);

		$add_student->do_action();

		// Then 相同 user+course 只應執行一次
		$this->assertSame( 1, $action_count, '相同 user+course 只應執行一次 action' );

		// 檢查 avl_course_ids 只有一筆記錄
		$avl_course_ids = get_user_meta( $this->alice_id, 'avl_course_ids' );
		$course_count   = count(
			array_filter(
				$avl_course_ids,
				fn( $id ) => (int) $id === $this->unlimited_course_id
			)
		);
		$this->assertSame( 1, $course_count, 'avl_course_ids 應只有一筆課程記錄' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 已有課程權限的用戶重複開通不應產生多筆記錄
	 * Rule: 後置（狀態）- 透過 AddStudent 服務去重
	 */
	public function test_已有課程的用戶重複開通不重複(): void {
		// Given Alice 已有課程存取權
		$this->enroll_user_to_course( $this->alice_id, $this->unlimited_course_id, 0 );

		// When 再次觸發開通
		do_action(
			LifeCycle::ADD_STUDENT_TO_COURSE_ACTION,
			$this->alice_id,
			$this->unlimited_course_id,
			0,
			null
		);

		// Then avl_course_ids 應只有一筆記錄
		$avl_course_ids = get_user_meta( $this->alice_id, 'avl_course_ids' );
		$course_count   = count(
			array_filter(
				$avl_course_ids,
				fn( $id ) => (int) $id === $this->unlimited_course_id
			)
		);
		$this->assertSame( 1, $course_count, '重複開通後 avl_course_ids 應只有一筆記錄' );
	}

	/**
	 * @test
	 * @group edge
	 * Limit instance() 從 WooCommerce Product meta 正確讀取設定
	 */
	public function test_Limit_instance_從product讀取設定(): void {
		// When
		$limit = Limit::instance( $this->fixed_course_id );

		// Then
		$this->assertSame( 'fixed', $limit->limit_type );
		$this->assertSame( 30, $limit->limit_value );
		$this->assertSame( 'day', $limit->limit_unit );
	}
}
