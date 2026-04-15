<?php
/**
 * 更新學員到期日 整合測試
 * Feature: specs/features/student/更新學員到期日.feature
 *
 * @group student
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\LifeCycle;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class UpdateStudentExpireDateTest
 * 測試更新學員課程到期日
 */
class UpdateStudentExpireDateTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 此測試直接觸發 WordPress action hook
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立測試課程
		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );

		// 建立 Alice 用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		// 預先加入課程（expire_date = 1893456000）
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 1893456000 );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 成功延長到期日
	 * Rule: 後置（狀態）- 更新 coursemeta 中的 expire_date
	 */
	public function test_成功延長到期日(): void {
		$new_expire_date = 1924992000; // 2031-01-01

		// When 透過 action hook 更新到期日
		try {
			do_action(
				LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION,
				$this->alice_id,
				$this->course_id,
				$new_expire_date
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 課程對用戶的 expire_date 應為新值
		$expire_date = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );
		$this->assertSame( (string) $new_expire_date, (string) $expire_date );
	}

	/**
	 * @test
	 * @group happy
	 * 成功改為永久存取（expire_date = 0）
	 */
	public function test_成功改為永久存取(): void {
		// When 更新 expire_date 為 0
		try {
			do_action(
				LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION,
				$this->alice_id,
				$this->course_id,
				0
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And expire_date 應為 "0"
		$expire_date = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );
		$this->assertSame( '0', (string) $expire_date );
	}

	/**
	 * @test
	 * @group happy
	 * 更新到期日後觸發 power_course_after_update_student_from_course action
	 * Rule: 後置（狀態）- 應觸發 power_course_after_update_student_from_course action
	 */
	public function test_更新到期日後觸發action_hook(): void {
		$action_args = [];
		add_action(
			LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION,
			function ( $user_id, $course_id, $timestamp ) use ( &$action_args ) {
				$action_args = [ $user_id, $course_id, $timestamp ];
			},
			99, // 優先級較高，在 save_meta_update_student 之後
			3
		);

		$new_expire_date = 0;

		// When
		do_action(
			LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION,
			$this->alice_id,
			$this->course_id,
			$new_expire_date
		);

		// Then action 應被觸發且帶有正確參數
		$this->assert_action_fired( LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION );
		$this->assertSame( $this->alice_id, $action_args[0], 'action 第一個參數（user_id）不符' );
		$this->assertSame( $this->course_id, $action_args[1], 'action 第二個參數（course_id）不符' );
		$this->assertSame( $new_expire_date, $action_args[2], 'action 第三個參數（timestamp）不符' );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 * ExpireDate value object 對非整數的處理
	 * Rule: 前置（參數）- timestamp 必須為整數（0 或 10 位 unix timestamp）
	 */
	public function test_expire_date_非整數值的行為(): void {
		$expire_date_obj = new \J7\PowerCourse\Resources\Course\ExpireDate( 'abc' );

		// 非數字格式不是 subscription，視為過期
		$this->assertFalse( $expire_date_obj->is_subscription );
		$this->assertTrue( $expire_date_obj->is_expired );
		$this->assertSame( 'abc', $expire_date_obj->expire_date );
	}

	/**
	 * @test
	 * @group error
	 * 直接更新 AVLCourseMeta expire_date 功能驗證
	 * Rule: 後置（狀態）- 更新 coursemeta 中的 expire_date
	 */
	public function test_直接更新coursemeta的expire_date(): void {
		// 直接操作 MetaCRUD 更新 expire_date
		$new_expire_date = 1924992000;
		$result          = AVLCourseMeta::update( $this->course_id, $this->alice_id, 'expire_date', $new_expire_date );

		// Then 更新應成功
		$this->assertNotFalse( $result, 'AVLCourseMeta::update 應回傳非 false' );

		// And 驗證值已更新
		$stored = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );
		$this->assertSame( (string) $new_expire_date, (string) $stored );
	}
}
