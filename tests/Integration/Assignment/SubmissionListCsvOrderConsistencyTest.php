<?php
/**
 * 列表與 CSV 順序一致性 交叉整合測試（Issue #131 第一波 P4）
 *
 * 依據：計劃 §9 P4「成功標準」——「列表與 CSV 對同一組篩選的 ID 順序一致（加一條交叉測試）」。
 * 不對應單一 .feature（跨 查詢作業繳交列表.feature 與 匯出批改用CSV.feature 的一致性驗證），
 * 是計劃明文要求的守門測試，非 feature 逐字 Example（test-creator 決定測試手法）。
 *
 * 驗證方式：以同一組 course_id 篩選，分別呼叫
 *   - REST `GET /power-course/assignment-submissions?course_id=…`（Service\Reporting::list_submissions()）
 *   - 直接呼叫 `Service\Csv::write_grading_csv(['course_id' => …])`
 * 取出兩者的 id / submission_id 順序，斷言完全一致。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Service\Csv;

/**
 * Class SubmissionListCsvOrderConsistencyTest
 *
 * @group assignment
 * @group issue-131
 */
class SubmissionListCsvOrderConsistencyTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/**
	 * 建立 Background：Admin、課程 100、章節 201、作業 400/401、
	 * 4 筆繳交（不同 submitted_at，交錯不同狀態，確保排序不是巧合）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_consistency_' . \uniqid(),
				'user_email' => 'admin_consistency_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_consistency_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_consistency_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_consistency_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_consistency_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_201_id  = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一', 'menu_order' => 0 ] );
		$assignment_401_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習二', 'menu_order' => 10 ] );

		$this->seed_submission( [ 'assignment_id' => $assignment_400_id, 'user_id' => $alice_id, 'text_body' => 'A', 'status' => 'pending', 'submitted_at' => '2026-09-16 09:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $assignment_401_id, 'user_id' => $bob_id, 'text_body' => 'B', 'status' => 'pass', 'feedback' => '好', 'submitted_at' => '2026-09-16 15:30:00', 'graded_by' => $this->admin_id, 'graded_at' => '2026-09-17 09:30:00' ] );
		$this->seed_submission( [ 'assignment_id' => $assignment_400_id, 'user_id' => $bob_id, 'text_body' => 'C', 'status' => 'fail', 'feedback' => '再補充', 'submitted_at' => '2026-09-15 20:00:00', 'graded_by' => $this->admin_id, 'graded_at' => '2026-09-16 09:00:00' ] );
		$this->seed_submission( [ 'assignment_id' => $assignment_401_id, 'user_id' => $alice_id, 'text_body' => 'D', 'status' => 'pending', 'submitted_at' => '2026-09-17 09:30:00' ] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 計劃 §9 P4 成功標準：列表與 CSV 對同一組篩選（course_id）的 id 順序一致
	 */
	public function test_列表與CSV對同一組篩選的id順序一致(): void {
		\wp_set_current_user( $this->admin_id );

		$list_response = $this->dispatch( 'GET', '/power-course/assignment-submissions', [ 'course_id' => $this->course_id ] );
		$this->assert_success_response( $list_response );
		$list_ids = \array_map(
			static fn( array $item ): int => (int) ( $item['id'] ?? 0 ),
			$this->get_response_items( $list_response )
		);

		$stream    = $this->call_static_or_fail( Csv::class, 'write_grading_csv', [ [ 'course_id' => $this->course_id ] ] );
		$raw       = $this->read_csv_stream( $stream );
		$rows      = $this->parse_csv_rows( $raw );
		$csv_ids   = \array_map( 'intval', $this->extract_csv_column( $rows, 'submission_id' ) );

		$this->assertNotEmpty( $list_ids, '列表應至少有一筆結果，交叉比對才有意義' );
		$this->assertSame( $list_ids, $csv_ids, '列表與 CSV 對同一組篩選的 id 順序應一致' );
	}
}
