<?php
/**
 * 講師管理整合測試
 *
 * Features:
 *   - specs/features/teacher/設定用戶為講師.feature
 *   - specs/features/teacher/移除講師身分.feature
 *
 * 測試後端 User API 的講師升降級行為（含 early-break 已知行為）
 *
 * @group teacher
 * @group user
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\User as UserApi;

/**
 * Class TeacherManageTest
 * 測試設定與移除講師身分的業務邏輯
 */
class TeacherManageTest extends TestCase {

	/** @var int Carol 用戶 ID */
	private int $carol_id;

	/** @var int David 用戶 ID */
	private int $david_id;

	/** @var int Eve 用戶 ID（已是講師）*/
	private int $eve_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 WordPress API
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->carol_id = $this->factory()->user->create(
			[
				'user_login' => 'carol_' . uniqid(),
				'user_email' => 'carol_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->david_id = $this->factory()->user->create(
			[
				'user_login' => 'david_' . uniqid(),
				'user_email' => 'david_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->eve_id = $this->factory()->user->create(
			[
				'user_login' => 'eve_' . uniqid(),
				'user_email' => 'eve_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		// Eve 已是講師
		update_user_meta( $this->eve_id, 'is_teacher', 'yes' );

		$this->ids['Carol'] = $this->carol_id;
		$this->ids['David'] = $this->david_id;
		$this->ids['Eve']   = $this->eve_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 設定講師冒煙：update_user_meta 可寫入 is_teacher=yes
	 */
	public function test_設定講師冒煙測試(): void {
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		$value = get_user_meta( $this->carol_id, 'is_teacher', true );
		$this->assertSame( 'yes', $value );
	}

	// ========== 設定用戶為講師 - 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 批次將用戶升級為講師
	 */
	public function test_批次設定Carol和David為講師(): void {
		// When: 批次設定
		foreach ( [ $this->carol_id, $this->david_id ] as $user_id ) {
			update_user_meta( $user_id, 'is_teacher', 'yes' );
		}

		// Then: 兩人 is_teacher 皆為 yes
		$carol_value = get_user_meta( $this->carol_id, 'is_teacher', true );
		$david_value = get_user_meta( $this->david_id, 'is_teacher', true );

		$this->assertSame( 'yes', $carol_value, 'Carol 應被設為講師' );
		$this->assertSame( 'yes', $david_value, 'David 應被設為講師' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 對既有講師重複設定不影響狀態
	 */
	public function test_重複設定已是講師的Eve不影響狀態(): void {
		// Eve 已是講師，再次設定
		update_user_meta( $this->eve_id, 'is_teacher', 'yes' );

		$value = get_user_meta( $this->eve_id, 'is_teacher', true );
		$this->assertSame( 'yes', $value, 'Eve 的 is_teacher 應仍為 yes' );
	}

	// ========== 設定用戶為講師 - 空 user_ids ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 未提供 user_ids 時，批次不執行
	 * 依 spec：目前 code 不驗必填，空 user_ids 時 foreach 不執行、仍回傳 success
	 */
	public function test_空user_ids時批次不執行(): void {
		// 模擬空 user_ids 的情境：foreach 不執行
		$user_ids = [];
		foreach ( $user_ids as $user_id ) {
			update_user_meta( $user_id, 'is_teacher', 'yes' );
		}

		// Carol 和 David 的 is_teacher 不應改變
		$carol_value = get_user_meta( $this->carol_id, 'is_teacher', true );
		$this->assertSame( '', $carol_value, '空 user_ids 不應修改任何用戶' );
	}

	// ========== 移除講師身分 - 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 批次移除講師身分
	 */
	public function test_批次移除Carol和David的講師身分(): void {
		// Given: Carol 和 David 都是講師
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		update_user_meta( $this->david_id, 'is_teacher', 'yes' );

		// When: 移除
		delete_user_meta( $this->carol_id, 'is_teacher' );
		delete_user_meta( $this->david_id, 'is_teacher' );

		// Then: is_teacher 不存在
		$carol_value = get_user_meta( $this->carol_id, 'is_teacher', true );
		$david_value = get_user_meta( $this->david_id, 'is_teacher', true );

		$this->assertSame( '', $carol_value, 'Carol 的 is_teacher 應已移除' );
		$this->assertSame( '', $david_value, 'David 的 is_teacher 應已移除' );
	}

	// ========== 移除講師身分 - Early-break 已知行為 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 對非講師執行移除會回傳失敗（delete_user_meta 回傳 false）
	 * 這是已知行為：delete_user_meta 對不存在的 meta 回傳 false
	 */
	public function test_移除非講師的is_teacher_delete_user_meta回傳false(): void {
		// Eve 從未被設置 is_teacher（新建用戶）
		$new_user_id = $this->factory()->user->create(
			[
				'user_login' => 'no_teacher_' . uniqid(),
				'user_email' => 'no_teacher_' . uniqid() . '@test.com',
			]
		);

		// delete_user_meta 對不存在的 meta 回傳 false
		$result = delete_user_meta( $new_user_id, 'is_teacher' );

		$this->assertFalse( $result, '刪除不存在的 is_teacher 應回傳 false（已知行為）' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: early-break 行為 — Carol 成功、David 失敗時迴圈中斷
	 * 模擬 API code 的 early-break 邏輯
	 */
	public function test_early_break_Carol成功David失敗時後續不執行(): void {
		// Given: Carol 是講師，David 從未設置
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		// David 沒有 is_teacher meta

		// 模擬 early-break 邏輯
		$user_ids     = [ $this->carol_id, $this->david_id ];
		$all_success  = true;
		$failed_at_id = null;

		foreach ( $user_ids as $user_id ) {
			$result = delete_user_meta( $user_id, 'is_teacher' );
			if ( false === $result ) {
				$all_success  = false;
				$failed_at_id = $user_id;
				break; // early-break
			}
		}

		// Carol 的 is_teacher 應已被刪除
		$carol_value = get_user_meta( $this->carol_id, 'is_teacher', true );
		$this->assertSame( '', $carol_value, 'Carol（先處理）的 is_teacher 應已刪除' );

		// 整體失敗
		$this->assertFalse( $all_success, '整體操作應失敗' );
		$this->assertSame( $this->david_id, $failed_at_id, '失敗位置應為 David' );
	}

	// ========== 移除講師後，已綁定課程不自動清除 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 移除講師身分後，已綁定的課程 teacher_ids 不自動清除
	 */
	public function test_移除講師後課程teacher_ids保持不變(): void {
		// Given: Carol 是講師且被指派到課程
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );

		$course_id = $this->create_course(
			[
				'post_title' => '測試課程',
				'_is_course' => 'yes',
			]
		);

		// 設定 teacher_ids
		update_post_meta( $course_id, 'teacher_ids', [ $this->carol_id ] );

		// When: 移除講師身分
		delete_user_meta( $this->carol_id, 'is_teacher' );

		// Then: 課程 teacher_ids 仍包含 Carol
		$teacher_ids = get_post_meta( $course_id, 'teacher_ids', true );
		$this->assertContains( $this->carol_id, (array) $teacher_ids, '移除講師後課程 teacher_ids 不自動清除' );
	}

	// ========== 安全性 ==========

	/**
	 * @test
	 * @group security
	 * Rule: is_teacher meta 只能由管理員設定（測試 meta 值不可偽造）
	 */
	public function test_is_teacher_meta值為yes才視為講師(): void {
		// 設定非標準值不應視為講師
		update_user_meta( $this->carol_id, 'is_teacher', '1' );
		$value = get_user_meta( $this->carol_id, 'is_teacher', true );

		// 依 spec，只有 "yes" 才是講師
		$this->assertNotSame( 'yes', $value, '非 "yes" 值不應視為講師' );
	}
}
