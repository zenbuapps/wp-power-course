<?php
/**
 * 取得課程進度 整合測試
 * Feature: specs/features/progress/取得課程進度.feature
 * 測試 Utils\Course::get_course_progress()
 *
 * @group progress
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Progress;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class CourseProgressTest
 * 測試課程進度計算邏輯
 */
class CourseProgressTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 章節 1 ID */
	private int $chapter_1_id;

	/** @var int 章節 2 ID */
	private int $chapter_2_id;

	/** @var int 章節 3 ID */
	private int $chapter_3_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// CourseUtils 為靜態工具類別，不需要實例化
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

		// 建立 3 個章節
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
		$this->chapter_3_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第三章',
				'menu_order' => 3,
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

		// Alice 加入課程
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
	}

	/**
	 * 清除快取（測試後）
	 */
	public function tear_down(): void {
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 基本冒煙測試：確認 get_course_progress 可被呼叫
	 */
	public function test_get_course_progress_可被呼叫(): void {
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );
		$this->assertIsFloat( $progress );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 查詢部分完成的課程進度
	 * Rule: 後置（回應）- 應回傳進度百分比和已完成章節列表
	 * 完成 1/3 章節 = 33.33%
	 */
	public function test_查詢部分完成的課程進度(): void {
		// Given Alice 完成第一章
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );

		// 清除快取
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// When 查詢課程進度
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then 進度應為 33.3%（1/3 章節完成）
		$this->assertEqualsWithDelta( 33.3, $progress, 0.1, '完成1/3章節的進度應約為33.3%' );
	}

	/**
	 * @test
	 * @group happy
	 * 查詢全部完成的課程進度應為 100%
	 */
	public function test_全部章節完成進度應為100(): void {
		// Given Alice 完成所有章節
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, '2025-06-01 11:00:00' );
		$this->set_chapter_finished( $this->chapter_3_id, $this->alice_id, '2025-06-01 12:00:00' );

		// 清除快取
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// When 查詢課程進度
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then 進度應為 100
		$this->assertSame( 100.0, $progress, '完成所有章節後進度應為 100%' );
	}

	/**
	 * @test
	 * @group happy
	 * 未完成任何章節時進度應為 0
	 */
	public function test_未完成任何章節進度應為0(): void {
		// Given 未完成任何章節

		// 清除快取
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// When
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then
		$this->assertSame( 0.0, $progress, '未完成任何章節時進度應為 0' );
	}

	/**
	 * @test
	 * @group happy
	 * 取得已完成章節列表
	 * Rule: 後置（回應）- 應回傳進度百分比和已完成章節列表
	 */
	public function test_取得已完成章節列表(): void {
		// Given Alice 完成第一章
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );

		// When 取得已完成章節 IDs
		$finished_chapters = CourseUtils::get_finished_sub_chapters( $this->course_id, $this->alice_id, true );

		// Then 應包含第一章的 ID
		$this->assertContains( $this->chapter_1_id, $finished_chapters, '已完成章節列表應包含第一章' );
		$this->assertNotContains( $this->chapter_2_id, $finished_chapters, '已完成章節列表不應包含第二章' );
	}

	/**
	 * @test
	 * @group happy
	 * 查詢永久存取的課程進度時 expire_date 應為 "0"
	 * Rule: 後置（回應）- 應回傳到期時間
	 */
	public function test_查詢永久存取課程的expire_date(): void {
		// Given Alice 以永久存取加入課程（set_up 時已設定 expire_date = 0）

		// When 查詢 expire_date
		$expire_date = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );

		// Then expire_date 應為 "0"
		$this->assertSame( '0', (string) $expire_date, '永久存取的 expire_date 應為 "0"' );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 * 查詢不存在的課程進度應回傳 0
	 * Rule: 前置（參數）- course_id 必須提供且為正整數
	 */
	public function test_查詢不存在課程進度回傳0(): void {
		$non_existent_course_id = 9999;
		wp_cache_delete( "pid_{$non_existent_course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		$progress = CourseUtils::get_course_progress( $non_existent_course_id, $this->alice_id );

		$this->assertSame( 0.0, $progress, '不存在的課程進度應回傳 0' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 進度計算：完成 2/3 章節應為 66.7%
	 */
	public function test_完成兩個章節進度為66_7(): void {
		// Given Alice 完成兩個章節
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, '2025-06-01 11:00:00' );

		// 清除快取
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// When
		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then 應約為 66.7%
		$this->assertEqualsWithDelta( 66.7, $progress, 0.1, '完成2/3章節的進度應約為66.7%' );
	}

	/**
	 * @test
	 * @group edge
	 * 章節完成後重設（刪除 finished_at）進度應減少
	 */
	public function test_取消章節完成後進度應減少(): void {
		// Given Alice 完成第一章
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		$progress_before = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );
		$this->assertGreaterThan( 0, $progress_before );

		// When 刪除 finished_at（取消完成）
		\J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD::delete( $this->chapter_1_id, $this->alice_id, 'finished_at' );
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// Then 進度應回到 0
		$progress_after = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );
		$this->assertSame( 0.0, $progress_after, '取消完成後進度應為 0' );
	}

	/**
	 * @test
	 * @group edge
	 * 快取機制：相同查詢第二次應使用快取
	 */
	public function test_progress_快取機制(): void {
		// Given 完成第一章並清除快取
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		// When 第一次查詢（應設定快取）
		$progress_first = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// 手動修改 chaptermeta（模擬快取不一致情境）
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, '2025-06-01 11:00:00' );

		// When 第二次查詢（應使用快取，不重新計算）
		$progress_second = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then 兩次結果相同（快取有效）
		$this->assertSame( $progress_first, $progress_second, '快取期間內兩次查詢結果應相同' );
	}

	/**
	 * @test
	 * @group edge
	 * 進度上限為 100%（不可超過）
	 */
	public function test_進度最大值為100(): void {
		// Given 完成所有章節
		$this->set_chapter_finished( $this->chapter_1_id, $this->alice_id, '2025-06-01 10:00:00' );
		$this->set_chapter_finished( $this->chapter_2_id, $this->alice_id, '2025-06-01 11:00:00' );
		$this->set_chapter_finished( $this->chapter_3_id, $this->alice_id, '2025-06-01 12:00:00' );
		wp_cache_delete( "pid_{$this->course_id}_uid_{$this->alice_id}", 'pc_course_progress' );

		$progress = CourseUtils::get_course_progress( $this->course_id, $this->alice_id );

		// Then 進度不應超過 100
		$this->assertLessThanOrEqual( 100.0, $progress, '進度不應超過 100%' );
	}
}
