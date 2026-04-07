<?php
/**
 * 切換章節完成狀態 整合測試
 * Feature: specs/features/progress/切換章節完成狀態.feature
 *
 * @group progress
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Progress;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class ChapterToggleFinishedTest
 * 測試章節完成狀態切換邏輯
 */
class ChapterToggleFinishedTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 章節 1 ID */
	private int $chapter_1_id;

	/** @var int 章節 2 ID */
	private int $chapter_2_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 MetaCRUD 和 CourseUtils
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

		// 建立 2 個章節
		$this->chapter_1_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第一章',
				'menu_order' => 1,
			]
		);
		$this->chapter_2_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第二章',
				'menu_order' => 2,
			]
		);

		// 建立 Alice 用戶並加入課程
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		// Alice 加入課程（永久存取）
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
	}

	/**
	 * 清除快取
	 */
	public function tear_down(): void {
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );
		parent::tear_down();
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 成功標記章節為完成
	 * Rule: 後置（狀態）- 標記完成時新增 finished_at
	 */
	public function test_成功標記章節為完成(): void {
		// Given Alice 在章節 200 無 finished_at（預設狀態）

		// When 標記章節為完成（設定 finished_at）
		try {
			AVLChapterMeta::update( $this->chapter_1_id, $this->alice_id, 'finished_at', wp_date( 'Y-m-d H:i:s' ) );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 章節的 chaptermeta finished_at 應不為空
		$finished_at = $this->get_chapter_meta( $this->chapter_1_id, $this->alice_id, 'finished_at' );
		$this->assertNotEmpty( $finished_at, 'finished_at 應不為空' );
	}

	/**
	 * @test
	 * @group happy
	 * 成功取消章節完成（刪除 finished_at）
	 * Rule: 後置（狀態）- 取消完成時刪除 finished_at
	 */
	public function test_成功取消章節完成(): void {
		// Given Alice 已完成第一章
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );

		// When 取消完成（刪除 finished_at）
		try {
			AVLChapterMeta::delete( $this->chapter_1_id, $this->alice_id, 'finished_at' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And finished_at 應為空
		$finished_at = $this->get_chapter_meta( $this->chapter_1_id, $this->alice_id, 'finished_at' );
		$this->assertEmpty( $finished_at, 'finished_at 應為空' );
	}

	/**
	 * @test
	 * @group happy
	 * 完成所有章節後課程進度達 100%
	 * Rule: 後置（狀態）- 切換後重新計算課程進度
	 */
	public function test_完成所有章節後課程進度達100(): void {
		// Given Alice 已完成第一章
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );

		// And 第二章未完成

		// When 完成第二章
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, wp_date( 'Y-m-d H:i:s' ) );

		// 清除快取讓進度重新計算
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// Then 進度應為 100
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );
		$this->assertSame( 100.0, $progress, '完成所有章節後進度應為 100%' );
	}

	/**
	 * @test
	 * @group happy
	 * 觸發 chapter_finished action hook 後應被記錄
	 * Rule: 後置（狀態）- 標記完成時觸發 chapter_finished action
	 */
	public function test_完成章節後觸發chapter_finished_action(): void {
		$fired_args = null;
		$action_name = 'power_course_chapter_finished';

		// power_course_chapter_finished action 簽章：($chapter_id, $course_id, $user_id)
		add_action(
			$action_name,
			function ( $chapter_id, $course_id, $user_id ) use ( &$fired_args ) {
				$fired_args = [ 'chapter_id' => $chapter_id, 'course_id' => $course_id, 'user_id' => $user_id ];
			},
			99, // 高優先級，在 add_chapter_finish_log 之後觸發
			3
		);

		// add_chapter_finish_log 內部呼叫 CourseUtils::get_course_progress 不帶 user_id
		// 會 fallback 到 get_current_user_id()，須先設定當前用戶
		wp_set_current_user( $this->alice_id );

		// When 觸發 chapter_finished action（帶入正確的三個參數）
		do_action( $action_name, $this->chapter_1_id, $this->course_id, $this->alice_id );

		// 還原用戶
		wp_set_current_user( 0 );

		// Then action 應已被觸發
		$this->assert_action_fired( $action_name );
		$this->assertNotNull( $fired_args );
		$this->assertSame( $this->chapter_1_id, $fired_args['chapter_id'] );
		$this->assertSame( $this->course_id, $fired_args['course_id'] );
		$this->assertSame( $this->alice_id, $fired_args['user_id'] );
	}

	/**
	 * @test
	 * @group happy
	 * 觸發 course_finished action 後應記錄 finished_at
	 * Rule: 後置（狀態）- 課程完成時記錄 finished_at
	 */
	public function test_課程完成後觸發course_finished_action並記錄(): void {
		// 確保 LifeCycle 的 save_finished_time hook 已掛載
		$this->assertNotFalse(
			has_action( LifeCycle::COURSE_FINISHED_ACTION, [ LifeCycle::class, 'save_finished_time' ] ),
			'course_finished action 未掛載 save_finished_time'
		);

		// When 觸發 course_finished action
		do_action( LifeCycle::COURSE_FINISHED_ACTION, $this->course_id, $this->alice_id );

		// Then 課程的 coursemeta finished_at 應不為空
		$finished_at = $this->get_course_meta( $this->course_id, $this->alice_id, 'finished_at' );
		$this->assertNotEmpty( $finished_at, '課程完成後 finished_at 應不為空' );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 * 未加入課程的用戶不應有 finished_at
	 * Rule: 前置（狀態）- 學員必須已有該章節所屬課程的存取權
	 */
	public function test_未加入課程用戶沒有finished_at(): void {
		// Given Bob 未加入課程
		$bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
			]
		);

		// When 查詢 Bob 的章節 finished_at
		$finished_at = $this->get_chapter_meta( $this->chapter_1_id, $bob_id, 'finished_at' );

		// Then 應為空
		$this->assertEmpty( $finished_at, '未加入課程的用戶不應有 finished_at' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * AVLChapterMeta update/delete 的幂等性
	 */
	public function test_chapter_meta_幂等更新(): void {
		// 多次設定同一個 meta 值應不產生多筆記錄
		$finish_time = '2025-06-01 10:00:00';
		AVLChapterMeta::update( $this->chapter_1_id, $this->alice_id, 'finished_at', $finish_time );
		AVLChapterMeta::update( $this->chapter_1_id, $this->alice_id, 'finished_at', $finish_time );

		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}pc_avl_chaptermeta WHERE post_id = %d AND user_id = %d AND meta_key = 'finished_at'",
				$this->chapter_1_id,
				$this->alice_id
			)
		);

		$this->assertSame( '1', (string) $count, '相同 meta 不應產生多筆記錄' );
	}

	/**
	 * @test
	 * @group edge
	 * 完成章節後再次完成（幂等）進度仍為 100%
	 */
	public function test_重複完成章節進度仍正確(): void {
		// Given 兩個章節都完成
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, '2025-06-01 11:00:00' );

		// When 再次設定第一章完成（幂等操作）
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 12:00:00' );

		// 清除快取
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// Then 進度仍為 100%
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );
		$this->assertSame( 100.0, $progress, '重複完成章節後進度仍應為 100%' );
	}
}
