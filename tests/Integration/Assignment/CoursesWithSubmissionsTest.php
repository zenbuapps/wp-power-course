<?php
/**
 * 查詢有繳交紀錄的課程 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/查詢有繳交紀錄的課程.feature
 * 依據：計劃 §9 P4、§4 DEC-14
 *
 * 回應為扁平陣列（非 {items,total}），欄位為 id / title / count（依 feature Data Table 字面欄名，
 * test-creator 決定：欄位名稱直接取自 feature，不另外包裝分頁）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class CoursesWithSubmissionsTest
 *
 * @group assignment
 * @group issue-131
 */
class CoursesWithSubmissionsTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_100_id;

	/** @var int 課程 101（JS 入門課） */
	private int $course_101_id;

	/** @var int 作業 400（課程 100） */
	private int $assignment_400_id;

	/** @var int 作業 401（課程 100） */
	private int $assignment_401_id;

	/** @var int 作業 402（課程 100，垃圾桶） */
	private int $assignment_402_id;

	/** @var int 作業 420（課程 101） */
	private int $assignment_420_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100/101/102、作業 400/401/402(trash)/420
	 * （不預先建立繳交，各測試依需要各自 seed）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_courses_' . \uniqid(),
				'user_email' => 'admin_courses_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_courses_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_courses_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_courses_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_courses_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_100_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );
		$this->create_course( [ 'post_title' => 'CSS 入門課' ] ); // 課程 102，本 feature 不使用，僅補齊 Background。

		$chapter_201_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '1-1 變數' ] );
		$chapter_300_id = $this->create_chapter( $this->course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$this->assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一：變數宣告', 'menu_order' => 0 ] );
		$this->assignment_401_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習二：上傳截圖', 'allow_file' => 'yes', 'required_file' => 'yes', 'max_files' => 2, 'menu_order' => 10 ] );
		$this->assignment_402_id = $this->create_assignment( $chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 20, 'post_status' => 'trash' ] );
		$this->assignment_420_id = $this->create_assignment( $chapter_300_id, [ 'title' => 'JS 練習', 'menu_order' => 0 ] );
	}

	/**
	 * 查詢有繳交紀錄的課程
	 *
	 * @return \WP_REST_Response
	 */
	private function query_courses(): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignment-submissions/courses' );
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

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions/courses' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳有繳交紀錄的課程與繳交數，依繳交數由多到少排序，不含垃圾桶中作業的繳交
	 * Example: PHP 基礎課 3 筆、JS 入門課 1 筆
	 */
	public function test_依繳交數排序(): void {
		$this->seed_submission( [ 'assignment_id' => $this->assignment_400_id, 'user_id' => $this->alice_id, 'text_body' => 'A', 'status' => 'pending', 'submitted_at' => '2026-09-16 09:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $this->assignment_400_id, 'user_id' => $this->bob_id, 'text_body' => 'B', 'status' => 'pending', 'submitted_at' => '2026-09-16 10:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $this->assignment_401_id, 'user_id' => $this->alice_id, 'text_body' => 'C', 'status' => 'pending', 'submitted_at' => '2026-09-16 11:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $this->assignment_420_id, 'user_id' => $this->alice_id, 'text_body' => 'D', 'status' => 'pending', 'submitted_at' => '2026-09-16 12:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $this->assignment_402_id, 'user_id' => $this->bob_id, 'text_body' => 'E', 'status' => 'pending', 'submitted_at' => '2026-09-16 13:00:00' ] );

		$response = $this->query_courses();

		$this->assert_success_response( $response );
		$list = $this->get_response_list( $response );

		$this->assertSame(
			[
				[ 'id' => $this->course_100_id, 'title' => 'PHP 基礎課', 'count' => 3 ],
				[ 'id' => $this->course_101_id, 'title' => 'JS 入門課', 'count' => 1 ],
			],
			\array_map(
				static fn( array $row ): array => [ 'id' => (int) $row['id'], 'title' => $row['title'], 'count' => (int) $row['count'] ],
				$list
			)
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 繳交數相同時依課程名稱排序
	 * Example: 兩門課各 1 筆時依名稱排序
	 */
	public function test_繳交數相同時依課程名稱排序(): void {
		$this->seed_submission( [ 'assignment_id' => $this->assignment_400_id, 'user_id' => $this->alice_id, 'text_body' => 'A', 'status' => 'pending', 'submitted_at' => '2026-09-16 09:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $this->assignment_420_id, 'user_id' => $this->alice_id, 'text_body' => 'D', 'status' => 'pending', 'submitted_at' => '2026-09-16 12:00:00' ] );

		$response = $this->query_courses();

		$this->assert_success_response( $response );
		$list = $this->get_response_list( $response );

		$this->assertSame(
			[
				[ 'id' => $this->course_101_id, 'title' => 'JS 入門課', 'count' => 1 ],
				[ 'id' => $this->course_100_id, 'title' => 'PHP 基礎課', 'count' => 1 ],
			],
			\array_map(
				static fn( array $row ): array => [ 'id' => (int) $row['id'], 'title' => $row['title'], 'count' => (int) $row['count'] ],
				$list
			)
		);
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 沒有任何繳交時回傳空列表
	 * Example: 全站尚無繳交
	 */
	public function test_沒有任何繳交時回傳空列表(): void {
		$response = $this->query_courses();

		$this->assert_success_response( $response );
		$this->assertSame( [], $this->get_response_list( $response ) );
	}
}
