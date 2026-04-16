<?php
/**
 * 退課清除 章節續播進度 整合測試
 * Feature: specs/features/student/移除學員課程權限.feature
 * 測試退課 hook 觸發時，pc_chapter_progress 與 last_visit_info 同步清除
 *
 * @group chapter-progress
 * @group cleanup
 */

declare( strict_types=1 );

namespace Tests\Integration\ChapterProgress;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\ChapterProgress\Service\Repository as ChapterProgressRepository;
use J7\PowerCourse\Resources\Course\LifeCycle;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class RemoveStudentCleanupTest
 * 測試退課時章節 progress 是否正確清除
 */
final class RemoveStudentCleanupTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 章節 1 ID */
	private int $chapter_1_id;

	/** @var int 章節 2 ID */
	private int $chapter_2_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int Bob 用戶 ID */
	private int $bob_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接操作 WordPress action hook，不需要額外 Service 實例
	}

	/**
	 * 每個測試前建立 Background 資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background: 建立測試課程
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		// Background: 建立章節
		$this->chapter_1_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第一章' ]
		);
		update_post_meta( $this->chapter_1_id, 'chapter_video', [ 'type' => 'bunny', 'id' => 'bunny-1' ] );

		$this->chapter_2_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第二章' ]
		);
		update_post_meta( $this->chapter_2_id, 'chapter_video', [ 'type' => 'bunny', 'id' => 'bunny-2' ] );

		// Background: 建立用戶
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

		// Background: Alice 與 Bob 皆已加入課程
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
		$this->enroll_user_to_course( $this->bob_id, $this->course_id, 0 );
	}

	/**
	 * 清理 chapter_progress 表
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION hook 已被 LifeCycle 監聽
	 */
	public function test_remove_student_hook已被註冊(): void {
		$this->assertNotFalse(
			has_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION ),
			'AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION hook 未被 LifeCycle 監聽'
		);
	}

	// ========== 退課清除測試（Q9）==========

	/**
	 * @test
	 * @group happy
	 * 觸發退課 action 後，Alice 所有章節 progress 應被清除
	 * Feature: 移除學員課程權限 → 清除 chapter_progress
	 */
	public function test_退課後chapter_progress被清除(): void {
		// Given: Alice 有多個章節的播放紀錄
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_1_id, $this->course_id, 120 );
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_2_id, $this->course_id, 240 );

		// 確認資料已建立
		$this->assertNotNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_1_id ),
			'退課前 chapter_1 progress 應存在'
		);
		$this->assertNotNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_2_id ),
			'退課前 chapter_2 progress 應存在'
		);

		// When: 觸發退課 action（管理員移除 Alice 的課程授權）
		try {
			do_action(
				LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
				$this->alice_id,
				$this->course_id
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then: 操作成功
		$this->assert_operation_succeeded();

		// And: Alice 的所有 chapter_progress 應被清除
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_1_id ),
			'退課後 chapter_1 progress 應被清除'
		);
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_2_id ),
			'退課後 chapter_2 progress 應被清除'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 觸發退課 action 後，Alice 的 last_visit_info 應被清除
	 * Feature: 移除學員課程權限 → 清除 last_visit_info（pc_avl_coursemeta）
	 */
	public function test_退課後last_visit_info被清除(): void {
		// Given: Alice 有 last_visit_info
		AVLCourseMeta::update(
			$this->course_id,
			$this->alice_id,
			'last_visit_info',
			[
				'chapter_id'    => $this->chapter_1_id,
				'last_visit_at' => '2026-04-15 10:00:00',
			]
		);

		// 確認 last_visit_info 已設定
		$before = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'last_visit_info', true );
		$this->assertNotEmpty( $before, '退課前 last_visit_info 應存在' );

		// When: 觸發退課 action
		try {
			do_action(
				LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
				$this->alice_id,
				$this->course_id
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then: 操作成功
		$this->assert_operation_succeeded();

		// And: Alice 的 last_visit_info 應不存在（coursemeta 整列應已刪除）
		$after = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'last_visit_info', true );
		$this->assertEmpty( $after, '退課後 last_visit_info 應被清除' );
	}

	/**
	 * @test
	 * @group happy
	 * 退課不應影響其他課程的 chapter_progress
	 */
	public function test_退課僅清除指定課程progress(): void {
		// Given: 另一個課程及其章節
		$other_course_id = $this->create_course(
			[
				'post_title' => '其他課程',
				'_is_course' => 'yes',
			]
		);
		$other_chapter_id = $this->create_chapter( $other_course_id, [ 'post_title' => '其他章節' ] );

		// Alice 加入另一個課程
		$this->enroll_user_to_course( $this->alice_id, $other_course_id, 0 );

		// 建立兩個課程的 chapter_progress
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_1_id, $this->course_id, 60 );
		ChapterProgressRepository::upsert( $this->alice_id, $other_chapter_id, $other_course_id, 90 );

		// When: 退出課程（不退出 other_course）
		do_action(
			LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
			$this->alice_id,
			$this->course_id
		);

		// Then: course 的 chapter_progress 被清除
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_1_id ),
			'退課後課程 chapter_progress 應被清除'
		);

		// And: other_course 的 chapter_progress 不受影響
		$other_record = ChapterProgressRepository::find( $this->alice_id, $other_chapter_id );
		$this->assertNotNull( $other_record, '其他課程的 chapter_progress 不應被清除' );
		$this->assertSame( 90, $other_record->last_position_seconds );
	}

	/**
	 * @test
	 * @group happy
	 * 退課不應影響其他用戶的 chapter_progress
	 */
	public function test_退課僅清除指定用戶progress(): void {
		// Given: Alice 與 Bob 各有 chapter_progress
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_1_id, $this->course_id, 60 );
		ChapterProgressRepository::upsert( $this->bob_id, $this->chapter_1_id, $this->course_id, 90 );

		// When: 退出 Alice 的課程授權
		do_action(
			LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
			$this->alice_id,
			$this->course_id
		);

		// Then: Alice 的 progress 被清除
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_1_id ),
			'Alice 退課後 chapter_progress 應被清除'
		);

		// And: Bob 的 progress 不受影響
		$bob_record = ChapterProgressRepository::find( $this->bob_id, $this->chapter_1_id );
		$this->assertNotNull( $bob_record, 'Bob 的 chapter_progress 不應被清除' );
		$this->assertSame( 90, $bob_record->last_position_seconds );
	}

	/**
	 * @test
	 * @group edge
	 * 退課時無 chapter_progress 亦不出錯
	 */
	public function test_退課時無progress亦不出錯(): void {
		// Given: Alice 無任何 chapter_progress

		// When: 觸發退課 action
		try {
			do_action(
				LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
				$this->alice_id,
				$this->course_id
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then: 不應出錯
		$this->assert_operation_succeeded();
	}
}
