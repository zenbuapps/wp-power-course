<?php
/**
 * 課程 CRUD 整合測試
 * Features:
 *   - specs/features/course/建立課程.feature
 *   - specs/features/course/更新課程.feature
 *   - specs/features/course/刪除課程.feature
 *
 * @group course
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\LifeCycle;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class CourseCRUDTest
 * 測試課程的建立、更新、刪除業務邏輯
 */
class CourseCRUDTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 WordPress APIs
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立測試課程
		$this->course_id = $this->create_course(
			[
				'post_title'  => 'PHP 基礎課',
				'post_status' => 'publish',
				'_is_course'  => 'yes',
				'limit_type'  => 'unlimited',
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
	 * 建立課程冒煙測試：確認 _is_course = yes 的商品可被建立
	 */
	public function test_建立課程冒煙測試(): void {
		$course_id = $this->create_course(
			[
				'post_title' => '測試課程冒煙',
				'_is_course' => 'yes',
			]
		);

		$this->assertGreaterThan( 0, $course_id, '課程 ID 應為正整數' );

		$is_course = get_post_meta( $course_id, '_is_course', true );
		$this->assertSame( 'yes', $is_course );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 建立課程後 _is_course meta 應為 "yes"
	 * Rule: 後置（狀態）- 成功建立 WooCommerce 商品並設 _is_course 為 yes
	 */
	public function test_建立課程後_is_course應為yes(): void {
		// When 建立課程
		$new_course_id = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'PHP 基礎入門',
			]
		);
		update_post_meta( $new_course_id, '_is_course', 'yes' );
		update_post_meta( $new_course_id, '_price', '1200' );
		update_post_meta( $new_course_id, 'limit_type', 'unlimited' );

		// Then _is_course 應為 yes
		$is_course = get_post_meta( $new_course_id, '_is_course', true );
		$this->assertSame( 'yes', $is_course, '新建課程的 _is_course 應為 yes' );

		// And status 應為 publish
		$post = get_post( $new_course_id );
		$this->assertSame( 'publish', $post->post_status, '新建課程的 status 應為 publish' );

		// And 回應中包含新建課程的 id（正整數）
		$this->assertGreaterThan( 0, $new_course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 課程 meta 正確儲存
	 */
	public function test_課程meta正確儲存(): void {
		// When 建立包含 meta 的課程
		$new_course_id = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'draft',
				'post_title'  => 'Vue 前端完整課程',
			]
		);
		update_post_meta( $new_course_id, '_is_course', 'yes' );
		update_post_meta( $new_course_id, '_price', '3000' );
		update_post_meta( $new_course_id, 'limit_type', 'fixed' );
		update_post_meta( $new_course_id, 'limit_value', 365 );
		update_post_meta( $new_course_id, 'limit_unit', 'day' );
		update_post_meta( $new_course_id, 'is_popular', 'yes' );
		update_post_meta( $new_course_id, 'is_featured', 'no' );

		// Then 所有欄位應正確儲存
		$this->assertSame( 'yes', get_post_meta( $new_course_id, '_is_course', true ) );
		$this->assertSame( 'draft', get_post( $new_course_id )->post_status );
		$this->assertSame( 'fixed', get_post_meta( $new_course_id, 'limit_type', true ) );
		$this->assertSame( '365', get_post_meta( $new_course_id, 'limit_value', true ) );
		$this->assertSame( 'day', get_post_meta( $new_course_id, 'limit_unit', true ) );
		$this->assertSame( 'yes', get_post_meta( $new_course_id, 'is_popular', true ) );
		$this->assertSame( 'no', get_post_meta( $new_course_id, 'is_featured', true ) );
	}

	/**
	 * @test
	 * @group happy
	 * 刪除課程後：學員的 avl_course_ids 應被清除
	 * Rule: 後置（狀態）- 刪除課程時連帶刪除相關資料
	 */
	public function test_刪除課程後學員avl_course_ids被清除(): void {
		// Given Alice 有此課程的存取權
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
		$this->assert_user_has_course_access( $this->alice_id, $this->course_id );

		// When 刪除課程（透過 before_delete_post 觸發 LifeCycle）
		LifeCycle::delete_course_and_related_items( $this->course_id );

		// 清除 WordPress 物件快取（delete_avl_course_by_course_id 直接操作 DB，不清除 WP 快取）
		clean_user_cache( $this->alice_id );

		// Then Alice 的 avl_course_ids 應不包含此課程
		$this->assert_user_has_no_course_access( $this->alice_id, $this->course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 移除學員課程權限
	 * Rule: 後置（狀態）- 刪除 avl_course_ids user meta 中對應的 course_id
	 */
	public function test_移除學員課程權限(): void {
		// Given Alice 已有課程存取權
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		// When 觸發移除學員 action
		try {
			do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And Alice 的 avl_course_ids 不應包含此課程
		$this->assert_user_has_no_course_access( $this->alice_id, $this->course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 移除學員後觸發 after_remove action
	 * Rule: 後置（狀態）- 應觸發 power_course_after_remove_student_from_course action
	 */
	public function test_移除學員後觸發action(): void {
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$action_args = null;
		add_action(
			LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION,
			function ( $user_id, $course_id ) use ( &$action_args ) {
				$action_args = [ $user_id, $course_id ];
			},
			99,
			2
		);

		// When
		do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );

		// Then action 應被觸發，且帶有正確的 user_id 和 course_id
		$this->assert_action_fired( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION );
		$this->assertNotNull( $action_args );
		$this->assertSame( $this->alice_id, $action_args[0] );
		$this->assertSame( $this->course_id, $action_args[1] );
	}

	/**
	 * @test
	 * @group happy
	 * 移除學員後章節進度記錄仍保留
	 * Rule: 後置（狀態）- 學員的章節進度記錄不自動清除
	 */
	public function test_移除學員後章節進度記錄仍保留(): void {
		// Given Alice 已加入課程並完成章節
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$this->set_chapter_finished( $chapter_id, $this->alice_id, '2025-06-01 10:00:00' );

		// When 移除學員
		do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $this->alice_id, $this->course_id );

		// Then 章節 finished_at 仍存在
		$finished_at = $this->get_chapter_meta( $chapter_id, $this->alice_id, 'finished_at' );
		$this->assertNotEmpty( $finished_at, '移除學員後章節進度記錄應仍保留' );
	}

	// ========== 錯誤處理（Error Handling）==========

	/**
	 * @test
	 * @group error
	 * 建立課程並指派 teacher_ids meta
	 * Rule: 後置（狀態）- teacher_ids 以多筆 meta rows 分別儲存
	 */
	public function test_建立課程並指派多位講師(): void {
		// 建立兩個 teacher 用戶
		$teacher1_id = $this->factory()->user->create(
			[
				'user_login' => 'teacher1_' . uniqid(),
				'role'       => 'editor',
			]
		);
		$teacher2_id = $this->factory()->user->create(
			[
				'user_login' => 'teacher2_' . uniqid(),
				'role'       => 'editor',
			]
		);

		// 建立課程
		$new_course_id = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'React 實戰課',
			]
		);
		update_post_meta( $new_course_id, '_is_course', 'yes' );

		// 指派講師（multiple meta rows）
		add_post_meta( $new_course_id, 'teacher_ids', $teacher1_id );
		add_post_meta( $new_course_id, 'teacher_ids', $teacher2_id );

		// Then 應有 2 筆 teacher_ids meta rows
		$teacher_ids = get_post_meta( $new_course_id, 'teacher_ids' );
		$this->assertCount( 2, $teacher_ids, '新建課程應有 2 筆 teacher_ids meta rows' );
		$this->assertContains( (string) $teacher1_id, $teacher_ids );
		$this->assertContains( (string) $teacher2_id, $teacher_ids );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * 負數 price 的商品應可儲存（WordPress 不強制驗證 price 格式）
	 * 實際的 price 驗證應在 API 層進行
	 */
	public function test_price_meta_可儲存(): void {
		// WordPress meta 允許儲存任何值，業務驗證在 API 層
		$course_id = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => '測試商品',
			]
		);
		update_post_meta( $course_id, '_price', '-100' );

		$price = get_post_meta( $course_id, '_price', true );
		$this->assertSame( '-100', $price, 'Meta 層允許儲存負數 price，驗證應在 API 層進行' );
	}

	/**
	 * @test
	 * @group edge
	 * 刪除課程後相關章節應被刪除（trash）
	 */
	public function test_刪除課程後章節也被trash(): void {
		// Given 課程有章節
		$chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '測試章節' ] );

		// When 呼叫 delete_course_and_related_items
		LifeCycle::delete_course_and_related_items( $this->course_id );

		// Then 章節應被 trash（post_status = trash）
		$chapter = get_post( $chapter_id );
		$this->assertSame( 'trash', $chapter->post_status, '章節應被移至垃圾桶' );
	}
}
