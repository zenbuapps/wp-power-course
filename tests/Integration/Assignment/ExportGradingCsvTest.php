<?php
/**
 * 匯出批改用CSV 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/匯出批改用CSV.feature
 * 依據：計劃 §9 P4、§4 DEC-03/DEC-08/DEC-26、§12 R-15/R-21
 *
 * 分層策略同 ExportAssignmentSubmissionsCsvTest.php：權限 / 檔名（標頭）走 REST dispatch()，
 * CSV 內容（含 submission_id 篩選結果、BOM/CRLF、5000 筆上限）直接呼叫
 * J7\PowerCourse\Resources\Assignment\Service\Csv::write_grading_csv( array $filters ): resource
 * （test-creator 決定的方法簽名；$filters = ['course_id' => ?int, 'status' => ?string]）。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Service\Csv;

/**
 * Class ExportGradingCsvTest
 *
 * @group assignment
 * @group issue-131
 */
class ExportGradingCsvTest extends AssignmentTestCase {

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

	/** @var int 作業 400（練習一：變數宣告，課程 100） */
	private int $assignment_400_id;

	/** @var int 繳交 500（作業 400，Alice，pending） */
	private int $submission_500_id;

	/** @var int 繳交 501（作業 400，Bob，pass） */
	private int $submission_501_id;

	/** @var int 繳交 503（作業 420，Alice，fail） */
	private int $submission_503_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100/101、作業 400/402(trash)/420、
	 * 繳交 500(pending)/501(pass)/503(fail)/504(trash 關聯,pending)、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_expgrd_' . \uniqid(),
				'user_email' => 'admin_expgrd_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_expgrd_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_expgrd_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_100_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );

		$chapter_201_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '1-1 變數' ] );
		$chapter_300_id = $this->create_chapter( $this->course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$this->assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一：變數宣告', 'menu_order' => 0 ] );
		$assignment_402_trash_id = $this->create_assignment( $chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 10, 'post_status' => 'trash' ] );
		$assignment_420_id       = $this->create_assignment( $chapter_300_id, [ 'title' => 'JS 練習', 'menu_order' => 0 ] );

		$this->submission_500_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);
		$this->submission_501_id = $this->seed_submission(
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
		$this->submission_503_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_420_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let z = 3;',
				'status'        => 'fail',
				'feedback'      => '再補充',
				'submitted_at'  => '2026-09-17 09:30:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-17 09:45:00',
			]
		);
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
	 * 匯出批改用 CSV（REST）
	 *
	 * @param array<string, mixed> $params 查詢參數
	 * @return \WP_REST_Response
	 */
	private function export_via_rest( array $params = [] ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignment-submissions/export', $params );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員匯出時操作失敗
	 */
	public function test_非管理員匯出時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions/export' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- status 不是 all、pending、pass、fail 時視同 all
	 * Example: status 為非法值時匯出全部
	 */
	public function test_status為非法值時匯出全部(): void {
		$stream = $this->call_static_or_fail( Csv::class, 'write_grading_csv', [ [ 'status' => 'graded' ] ] );
		$raw    = $this->read_csv_stream( $stream );
		$rows   = $this->parse_csv_rows( $raw );

		$ids = \array_map( 'intval', $this->extract_csv_column( $rows, 'submission_id' ) );
		$this->assertSame( [ $this->submission_503_id, $this->submission_501_id, $this->submission_500_id ], $ids );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 未篩選時依繳交時間由新到舊匯出全部繳交，不含垃圾桶中作業的繳交
	 *       （同一 Example 內另含 CSV 應以 UTF-8 BOM 開頭且換行為 CRLF）
	 * Example: 匯出全部繳交
	 */
	public function test_匯出全部繳交(): void {
		$stream = $this->call_static_or_fail( Csv::class, 'write_grading_csv', [ [] ] );
		$raw    = $this->read_csv_stream( $stream );

		$this->assert_csv_bom_and_crlf( $raw );

		$rows = $this->parse_csv_rows( $raw );
		$this->assertSame(
			[
				[ 'submission_id', '學員 Email', '學員姓名', '作業', '繳交時間', '目前狀態', '狀態(可改 pending/pass/fail)', '講師回饋(可改)' ],
				[ (string) $this->submission_503_id, 'alice@test.com', 'Alice', 'JS 練習', '2026-09-17T01:30:00.000Z', '未通過', 'fail', '再補充' ],
				[ (string) $this->submission_501_id, 'bob@test.com', 'Bob', '練習一：變數宣告', '2026-09-16T07:30:00.000Z', '通過', 'pass', '寫得很好' ],
				[ (string) $this->submission_500_id, 'alice@test.com', 'Alice', '練習一：變數宣告', '2026-09-16T01:00:00.000Z', '待批改', 'pending', '' ],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_課程與狀態篩選
	 *
	 * Rule: 後置（回應）- 依課程與狀態篩選
	 *
	 * @param string          $course_key 'course_100' | ''
	 * @param string          $status     status 參數
	 * @param array<int, int> $expected_indexes 期望回傳的 id（相對索引，0=500,1=501,2=503）
	 */
	public function test_依課程與狀態篩選( string $course_key, string $status, array $expected_indexes ): void {
		$ids_by_index = [ $this->submission_500_id, $this->submission_501_id, $this->submission_503_id ];
		$expected     = \array_map( static fn( int $i ): int => $ids_by_index[ $i ], $expected_indexes );

		$filters = [ 'status' => $status ];
		if ( 'course_100' === $course_key ) {
			$filters['course_id'] = $this->course_100_id;
		}

		$stream = $this->call_static_or_fail( Csv::class, 'write_grading_csv', [ $filters ] );
		$raw    = $this->read_csv_stream( $stream );
		$rows   = $this->parse_csv_rows( $raw );

		$ids = \array_map( 'intval', $this->extract_csv_column( $rows, 'submission_id' ) );
		$this->assertSame( $expected, $ids );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<int, int>}>
	 */
	public static function provider_課程與狀態篩選(): array {
		return [
			'course100_all'     => [ 'course_100', 'all', [ 1, 0 ] ],
			'all_pending'       => [ '', 'pending', [ 0 ] ],
			'course100_pass'    => [ 'course_100', 'pass', [ 1 ] ],
		];
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 下載檔名為 submissions-grades-{UTC 時間戳}.csv
	 * Example: 匯出檔名
	 */
	public function test_下載檔名為submissionsGrades加UTC時間戳(): void {
		$response = $this->export_via_rest( [ 'status' => 'all' ] );

		$this->assert_success_response( $response );

		$headers             = $response->get_headers();
		$content_disposition = (string) ( $headers['Content-Disposition'] ?? '' );
		$expected_filename   = 'submissions-grades-2026-09-17-02-00-00.csv';

		$this->assertStringContainsString( 'attachment', $content_disposition, 'Content-Disposition 應為 attachment（強制下載）' );
		$this->assertStringContainsString(
			\rawurlencode( $expected_filename ),
			$content_disposition,
			\sprintf( 'Content-Disposition 應含下載檔名，實際：%s', $content_disposition )
		);
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] ?? null, 'X-Content-Type-Options 應為 nosniff（DEC-08）' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 最多匯出 5000 筆
	 * Example: 有 5001 筆繳交時只匯出 5000 筆
	 */
	public function test_有5001筆繳交時只匯出5000筆(): void {
		$this->bulk_seed_submissions( $this->assignment_400_id, 5001 );

		$stream = $this->call_static_or_fail( Csv::class, 'write_grading_csv', [ [ 'status' => 'all' ] ] );
		$raw    = $this->read_csv_stream( $stream );
		$rows   = $this->parse_csv_rows( $raw );

		$this->assertCount( 5000, \array_slice( $rows, 1 ) );
	}

	/**
	 * 以單一多列 INSERT 塞入大量繳交，user_id 用不存在的 100000+i，避免建立 5001 個 WP user
	 *
	 * @param int $assignment_id 作業 ID
	 * @param int $count         筆數
	 */
	private function bulk_seed_submissions( int $assignment_id, int $count ): void {
		global $wpdb;
		$table = $wpdb->prefix . \J7\PowerCourse\Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		$now          = '2026-09-16 09:00:00';
		$placeholders = [];
		$value_sql    = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s)';
			$value_sql[]    = $assignment_id;
			$value_sql[]    = 100000 + $i;
			$value_sql[]    = "bulk {$i}";
			$value_sql[]    = '[]';
			$value_sql[]    = $now;
			$value_sql[]    = 'pending';
			$value_sql[]    = $now;
			$value_sql[]    = $now;
		}

		$sql = "INSERT INTO `{$table}` (assignment_id, user_id, text_body, attachments, submitted_at, status, created_at, updated_at) VALUES "
			. \implode( ', ', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->query( $wpdb->prepare( $sql, $value_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
