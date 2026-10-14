<?php
/**
 * 查詢作業學員背景資料 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/查詢作業學員背景資料.feature
 * 依據：計劃 §9 P4（student_overview()）、inc/classes/Utils/Datetime.php::to_site_timezone()
 *
 * ─── user_registered 時區備忘（避免 +16 小時 double-shift，主窗口實測指示）───────
 * WordPress 核心以 GMT 寫入 wp_users.user_registered；feature Background 表列出的值
 * （如 Alice 的 "2026-01-05 10:00:00"）是「期望回應顯示的站台時區值」，不是要直接寫入
 * 資料庫的原始值。本檔 seed 時改寫入該值往前 8 小時的 GMT 原始值（"2026-01-05 02:00:00"），
 * 讓 Utils\Datetime::to_site_timezone() 轉換後正好等於 feature 期望的顯示值，
 * 不重演 Issue #233 的 +16 小時 double-shift（見 UserRegisteredTimezoneTest.php 同款手法）。
 * ──────────────────────────────────────────────────────────────
 *
 * 回應為巢狀物件 {user, course, stats, progress}（test-creator 決定巢狀分組，
 * 欄位名稱取自 feature 的 dot path：user.id/user.name/user.email/user.registered_at、
 * course.id/course.title、stats.submitted/pending/pass/fail、progress.completed_chapters/
 * total_chapters/percent），使用新增的 assert_response_includes_nested() 斷言。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentStudentOverviewTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentStudentOverviewTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice（user_registered GMT 2026-01-05 02:00:00 → 站台時區顯示 2026-01-05 10:00:00） */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_100_id;

	/** @var int 課程 101（JS 入門課） */
	private int $course_101_id;

	/** @var int 章節 200（第一章，課程 100 頂層） */
	private int $chapter_200_id;

	/** @var int 章節 201（1-1 變數，章節 200 之下） */
	private int $chapter_201_id;

	/** @var int 作業 400（章節 201） */
	private int $assignment_400_id;

	/** @var int 作業 401（章節 201） */
	private int $assignment_401_id;

	/** @var int 作業 420（章節 300，課程 101） */
	private int $assignment_420_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100/101、章節 200/201/202/300、
	 * 作業 400/401/410/420、繳交 500(pass)/501(fail)/502(pending)/503(pending)、
	 * Alice 在章節 200/201 已完成
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_overview_' . \uniqid(),
				'user_email' => 'admin_overview_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_overview_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_overview_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob@test.com',
				'role'       => 'subscriber',
			]
		);

		global $wpdb;
		$wpdb->update( $wpdb->users, [ 'user_registered' => '2026-01-05 02:00:00' ], [ 'ID' => $this->alice_id ] );
		\clean_user_cache( $this->alice_id );

		$this->course_100_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );

		$this->chapter_200_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '第一章' ] );
		$this->chapter_201_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '1-1 變數', 'post_parent' => $this->chapter_200_id ] );
		$chapter_202_id       = $this->create_chapter( $this->course_100_id, [ 'post_title' => '第二章' ] );
		$chapter_300_id       = $this->create_chapter( $this->course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$this->assignment_400_id = $this->create_assignment( $this->chapter_201_id, [ 'title' => '練習一：變數宣告', 'menu_order' => 0 ] );
		$this->assignment_401_id = $this->create_assignment( $this->chapter_201_id, [ 'title' => '練習二：上傳截圖', 'allow_file' => 'yes', 'required_file' => 'yes', 'max_files' => 2, 'menu_order' => 10 ] );
		$this->create_assignment( $this->chapter_200_id, [ 'title' => '第一章總複習', 'menu_order' => 0 ] );
		$this->assignment_420_id = $this->create_assignment( $chapter_300_id, [ 'title' => 'JS 練習', 'menu_order' => 0 ] );

		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'A',
				'status'        => 'pass',
				'feedback'      => '很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'B',
				'status'        => 'fail',
				'feedback'      => '再補充',
				'submitted_at'  => '2026-09-16 11:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 12:00:00',
			]
		);
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_420_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'C',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 13:00:00',
			]
		);
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'D',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 14:00:00',
			]
		);

		$this->set_chapter_finished( $this->chapter_200_id, $this->alice_id, '2026-09-15 10:00:00' );
		$this->set_chapter_finished( $this->chapter_201_id, $this->alice_id, '2026-09-16 10:00:00' );
	}

	/**
	 * 查詢作業學員背景資料
	 *
	 * @param array<string, mixed> $params 查詢參數
	 * @return \WP_REST_Response
	 */
	private function query_overview( array $params ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignment-submissions/student-overview', $params );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員查詢時操作失敗
	 */
	public function test_非管理員查詢時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions/student-overview' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- user_id 為必填
	 * Example: 未提供 user_id 時操作失敗
	 */
	public function test_未提供user_id時操作失敗(): void {
		$response = $this->query_overview( [ 'course_id' => $this->course_100_id ] );

		$this->assert_operation_failed_response( $response, 'user_id 為必填', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以 course_id 彙整學員基本資料、該學員在本課程作業的狀態統計與學習進度
	 * Example: Alice 在 PHP 基礎課的背景資料
	 */
	public function test_以courseId彙整學員基本資料統計與進度(): void {
		$response = $this->query_overview( [ 'user_id' => $this->alice_id, 'course_id' => $this->course_100_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes_nested(
			$response,
			[
				'user.id'                     => $this->alice_id,
				'user.name'                   => 'Alice',
				'user.email'                  => 'alice@test.com',
				'user.registered_at'          => '2026-01-05 10:00:00',
				'course.id'                   => $this->course_100_id,
				'course.title'                => 'PHP 基礎課',
				'stats.submitted'             => 2,
				'stats.passed'                => 1,
				'stats.pending'               => 0,
				'stats.failed'                => 1,
				'progress.completed_chapters' => 2,
				'progress.total_chapters'     => 3,
			]
		);
		// percent 為四捨五入到小數一位的浮點數，比對既有 CourseProgressTest 慣例用容差比對，不用 assertSame。
		$this->assertEqualsWithDelta( 66.7, $response->get_data()['progress']['percent'] ?? null, 0.1, 'progress.percent 應約為 66.7' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 未提供 course_id 時由 assignment_id 反推所屬課程
	 * Example: 由作業 420 反推到 JS 入門課
	 */
	public function test_未提供courseId時由assignmentId反推(): void {
		$response = $this->query_overview( [ 'user_id' => $this->alice_id, 'assignment_id' => $this->assignment_420_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes_nested(
			$response,
			[
				'course.id'                   => $this->course_101_id,
				'course.title'                => 'JS 入門課',
				'stats.submitted'             => 1,
				'stats.passed'                => 0,
				'stats.pending'               => 1,
				'stats.failed'                => 0,
				'progress.completed_chapters' => 0,
				'progress.total_chapters'     => 1,
			]
		);
		$this->assertEqualsWithDelta( 0.0, $response->get_data()['progress']['percent'] ?? null, 0.1, 'progress.percent 應約為 0' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 學員不存在時學員欄位為空值，不報錯
	 * Example: 查詢不存在的學員
	 */
	public function test_學員不存在時學員欄位為空值(): void {
		$response = $this->query_overview( [ 'user_id' => 9999, 'course_id' => $this->course_100_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes_nested(
			$response,
			[
				'user.id'                      => 9999,
				'user.name'                    => null,
				'user.email'                   => null,
				'user.registered_at'           => null,
				'course.id'                    => $this->course_100_id,
				'stats.submitted'               => 0,
				'progress.completed_chapters'  => 0,
				'progress.total_chapters'       => 3,
			]
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 無法解析課程時課程欄位為空、統計與進度皆為 0
	 * Example: 未提供課程與作業
	 */
	public function test_無法解析課程時課程欄位為空統計與進度皆為0(): void {
		$response = $this->query_overview( [ 'user_id' => $this->alice_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes_nested(
			$response,
			[
				'course.id'                   => null,
				'course.title'                => null,
				'stats.submitted'             => 0,
				'stats.passed'                => 0,
				'stats.pending'               => 0,
				'stats.failed'                => 0,
				'progress.completed_chapters' => 0,
				'progress.total_chapters'     => 0,
			]
		);
		$this->assertEqualsWithDelta( 0.0, $response->get_data()['progress']['percent'] ?? null, 0.1, 'progress.percent 應約為 0' );
	}
}
