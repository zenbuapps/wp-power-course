<?php
/**
 * 查詢作業批改統計 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/查詢作業批改統計.feature
 * 依據：計劃 §9 P4、§4 DEC-13/DEC-14
 *
 * avg_grading_seconds 的精確秒數（55800 / 64800 / 35100）以 wp_date() 站台時區字串
 * 差值計算，與 feature 逐字核對過（見各測試方法內註解換算）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentSubmissionStatsTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentSubmissionStatsTest extends AssignmentTestCase {

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

	/** @var int 作業 420（課程 101） */
	private int $assignment_420_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100/101、作業 400/401/402(trash)/420、
	 * 繳交 500(pending)/501(pass)/502(fail)/503(pending)/504(trash 關聯，pending)
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_stats_' . \uniqid(),
				'user_email' => 'admin_stats_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_stats_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_stats_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_stats_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_stats_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_100_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );

		$chapter_201_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '1-1 變數' ] );
		$chapter_300_id = $this->create_chapter( $this->course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$this->assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一：變數宣告', 'menu_order' => 0 ] );
		$this->assignment_401_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習二：上傳截圖', 'allow_file' => 'yes', 'required_file' => 'yes', 'max_files' => 2, 'menu_order' => 10 ] );
		$assignment_402_trash_id = $this->create_assignment( $chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 20, 'post_status' => 'trash' ] );
		$this->assignment_420_id = $this->create_assignment( $chapter_300_id, [ 'title' => 'JS 練習', 'menu_order' => 0 ] );

		// 繳交 500：assignment 400，Alice，pending（未批改，不計入平均批改時間）
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);
		// 繳交 501：assignment 400，Bob，pass，submitted 09-16 15:30 → graded 09-17 09:30，18h = 64800s
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'const y = 2;',
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 15:30:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-17 09:30:00',
			]
		);
		// 繳交 502：assignment 401，Alice，fail，submitted 09-15 20:00 → graded 09-16 09:00，13h = 46800s
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'status'        => 'fail',
				'feedback'      => '請補充型別說明',
				'submitted_at'  => '2026-09-15 20:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 09:00:00',
			]
		);
		// 繳交 503：assignment 420（課程 101），Alice，pending
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_420_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let z = 3;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 09:30:00',
			]
		);
		// 繳交 504：assignment 402（垃圾桶），Bob，pending，不應計入任何統計
		$this->seed_submission(
			[
				'assignment_id' => $assignment_402_trash_id,
				'user_id'       => $this->bob_id,
				'text_body'     => '舊作業',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 09:00:00',
			]
		);

		$this->set_now( '2026-09-17 10:00:00' );
	}

	/**
	 * 建立過濾參數，null 表示不帶該參數
	 *
	 * @param int|null $course_id     course_id
	 * @param int|null $assignment_id assignment_id
	 * @return array<string, int>
	 */
	private function build_params( ?int $course_id, ?int $assignment_id ): array {
		$params = [];
		if ( null !== $course_id ) {
			$params['course_id'] = $course_id;
		}
		if ( null !== $assignment_id ) {
			$params['assignment_id'] = $assignment_id;
		}

		return $params;
	}

	/**
	 * 查詢作業批改統計
	 *
	 * @param array<string, mixed> $params 查詢參數
	 * @return \WP_REST_Response
	 */
	private function query_stats( array $params = [] ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignment-submissions/stats', $params );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員查詢批改統計時操作失敗
	 */
	public function test_非管理員查詢批改統計時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions/stats' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 未提供範圍時統計全站，不含垃圾桶中作業的繳交
	 * Example: 全站批改統計
	 */
	public function test_全站批改統計(): void {
		$response = $this->query_stats( [ 'course_id' => '', 'assignment_id' => '' ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'total'                => 4,
				'pending'              => 2,
				'pass'                 => 1,
				'fail'                 => 1,
				'pass_rate'            => 50,
				'avg_grading_seconds'  => 55800,
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider provider_範圍統計
	 *
	 * Rule: 後置（回應）- 依課程與作業範圍統計，兩者同時提供時取交集
	 *
	 * @param string   $course_key     'course_101' | ''
	 * @param string   $assignment_key 'assignment_400' | ''
	 * @param array<string, int|null> $expected 期望統計結果
	 */
	public function test_依課程與作業範圍統計( string $course_key, string $assignment_key, array $expected ): void {
		$course_id     = 'course_101' === $course_key ? $this->course_101_id : null;
		$assignment_id = 'assignment_400' === $assignment_key ? $this->assignment_400_id : null;

		$response = $this->query_stats( $this->build_params( $course_id, $assignment_id ) );

		$this->assert_success_response( $response );
		$this->assert_response_includes( $response, $expected );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<string, int|null>}>
	 */
	public static function provider_範圍統計(): array {
		return [
			// course_id=101（僅繳交 503，pending）
			'course101_only'            => [ 'course_101', '', [ 'total' => 1, 'pending' => 1, 'pass' => 0, 'fail' => 0, 'pass_rate' => 0, 'avg_grading_seconds' => null ] ],
			// course_id=101 + assignment_id=400（400 屬課程 100，交集為空）
			'course101_assignment400'   => [ 'course_101', 'assignment_400', [ 'total' => 0, 'pending' => 0, 'pass' => 0, 'fail' => 0, 'pass_rate' => 0, 'avg_grading_seconds' => null ] ],
			// assignment_id=400（繳交 500 pending、501 pass，avg = 64800）
			'assignment400_only'        => [ '', 'assignment_400', [ 'total' => 2, 'pending' => 1, 'pass' => 1, 'fail' => 0, 'pass_rate' => 100, 'avg_grading_seconds' => 64800 ] ],
		];
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 平均批改時間計入所有 graded_at 不為空的繳交，包含被改回待批改者
	 * Example: 改回待批改的繳交仍計入平均批改時間
	 */
	public function test_改回待批改的繳交仍計入平均批改時間(): void {
		// 繳交 505：assignment 401，Bob，pending 但有 graded_at（submitted 09-16 09:00 → graded 09-16 15:30，6.5h = 23400s）
		// 與既有 502（46800s）平均 = (46800+23400)/2 = 35100
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'Bob 答案',
				'status'        => 'pending',
				'feedback'      => '再看看',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 15:30:00',
			]
		);

		$response = $this->query_stats( [ 'assignment_id' => $this->assignment_401_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'total'               => 2,
				'pending'             => 1,
				'pass'                => 0,
				'fail'                => 1,
				'pass_rate'           => 0,
				'avg_grading_seconds' => 35100,
			]
		);
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 課程底下沒有任何作業時所有數字為 0
	 * Example: 查詢沒有作業的課程
	 */
	public function test_課程無任何作業時全部為0(): void {
		$course_102_id = $this->create_course( [ 'post_title' => 'CSS 入門課' ] );

		$response = $this->query_stats( [ 'course_id' => $course_102_id ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'total'               => 0,
				'pending'             => 0,
				'pass'                => 0,
				'fail'                => 0,
				'pass_rate'           => 0,
				'avg_grading_seconds' => null,
			]
		);
	}
}
