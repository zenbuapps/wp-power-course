<?php
/**
 * 匯出單一作業繳交CSV 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/匯出單一作業繳交CSV.feature
 * 依據：計劃 §9 P4、§4 DEC-03/DEC-08/DEC-26、§12 R-15/R-21
 *
 * ─── 分層策略（主窗口指示，詳見回報「介面契約」段）────────────────────
 * 權限 / 存在性 / 下載檔名（標頭）→ REST 層 dispatch()，只斷言 status、標頭、權限。
 * CSV 實際內容（BOM、CRLF、雙引號跳脫、資料列數）→ 直接呼叫
 * J7\PowerCourse\Resources\Assignment\Service\Csv::write_submission_csv( int $assignment_id ): resource
 * （test-creator 決定的方法簽名，計劃只寫「Service/Csv.php 寫入 php://temp」未給精確簽名），
 * 取得 stream resource 後用本檔基底新增的 read_csv_stream() / parse_csv_rows() /
 * assert_csv_bom_and_crlf() 斷言，不透過 REST（避免真的把 5001 筆資料序列化成 HTTP response）。
 * ──────────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Service\Csv;

/**
 * Class ExportAssignmentSubmissionsCsvTest
 *
 * @group assignment
 * @group issue-131
 */
class ExportAssignmentSubmissionsCsvTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 作業 400（練習一：變數宣告） */
	private int $assignment_400_id;

	/** @var int 作業 402（已刪除練習，垃圾桶） */
	private int $assignment_402_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100、章節 201、作業 400/402(trash)、
	 * 附件 700、繳交 500(pending,附件700)/501(pass,feedback含逗號)、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_expcsv_' . \uniqid(),
				'user_email' => 'admin_expcsv_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_expcsv_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_expcsv_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_201_id  = $this->create_chapter( $this->course_id, [ 'post_title' => '1-1 變數' ] );

		$this->assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一：變數宣告', 'allow_file' => 'yes', 'menu_order' => 0 ] );
		$this->assignment_402_id = $this->create_assignment( $chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 10, 'post_status' => 'trash' ] );

		$this->set_now( '2026-09-17 10:00:00' );
	}

	/**
	 * 匯出單一作業繳交 CSV（REST）
	 *
	 * @param int $assignment_id 作業 ID
	 * @return \WP_REST_Response
	 */
	private function export_via_rest( int $assignment_id ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignments/' . $assignment_id . '/submissions/export' );
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

		$response = $this->dispatch( 'GET', '/power-course/assignments/' . $this->assignment_400_id . '/submissions/export' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provider_不存在或垃圾桶
	 *
	 * Rule: 前置（狀態）- 作業必須存在，在垃圾桶中的作業視為不存在
	 *
	 * @param string $scenario 'not_exist' | 'trashed'
	 */
	public function test_匯出情境作業的繳交時操作失敗( string $scenario ): void {
		$assignment_id = 'not_exist' === $scenario ? 9999 : $this->assignment_402_id;

		$response = $this->export_via_rest( $assignment_id );

		$this->assert_operation_failed_response( $response, '作業不存在', 404, 'rest_not_found' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provider_不存在或垃圾桶(): array {
		return [
			'不存在'     => [ 'not_exist' ],
			'已在垃圾桶' => [ 'trashed' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 下載檔名為 assignment-{作業標題}-{UTC 時間戳}.csv，標題中非文字字元換成底線
	 * Example: 作業標題含全形冒號
	 */
	public function test_下載檔名為assignment加標題加UTC時間戳(): void {
		$response = $this->export_via_rest( $this->assignment_400_id );

		$this->assert_success_response( $response );

		$headers              = $response->get_headers();
		$content_disposition  = (string) ( $headers['Content-Disposition'] ?? '' );
		$expected_filename    = 'assignment-練習一_變數宣告-2026-09-17-02-00-00.csv';

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
	 * @group happy
	 *
	 * Rule: 後置（回應）- 依繳交時間由新到舊輸出繳交清單，狀態以中文標籤呈現，含逗號的值以雙引號包覆
	 *       （同一 Example 內另含 CSV 應以 UTF-8 BOM 開頭且換行為 CRLF）
	 * Example: 匯出作業 400 的繳交
	 */
	public function test_匯出作業400的繳交(): void {
		$attachment_700_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '作業截圖.png',
				'mime_type'     => 'image/png',
				'file_size'     => 20480,
			]
		);
		$this->seed_submission(
			[
				'assignment_id'  => $this->assignment_400_id,
				'user_id'        => $this->alice_id,
				'text_body'      => 'let x = 1;',
				'attachment_ids' => [ $attachment_700_id ],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'const y = 2;',
				'status'        => 'pass',
				'feedback'      => '寫得很好, 繼續保持',
				'submitted_at'  => '2026-09-16 15:30:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-17 09:30:00',
			]
		);

		$stream = $this->call_static_or_fail( Csv::class, 'write_submission_csv', [ $this->assignment_400_id ] );
		$raw    = $this->read_csv_stream( $stream );

		$this->assert_csv_bom_and_crlf( $raw );

		$rows = $this->parse_csv_rows( $raw );
		$this->assertSame(
			[
				[ '繳交時間', '學員 Email', '學員姓名', '狀態', '文字內容', '附件數', '附件清單', '批改時間', '講師回饋' ],
				[ '2026-09-16T07:30:00.000Z', 'bob@test.com', 'Bob', '通過', 'const y = 2;', '0', '', '2026-09-17T01:30:00.000Z', '寫得很好, 繼續保持' ],
				[ '2026-09-16T01:00:00.000Z', 'alice@test.com', 'Alice', '待批改', 'let x = 1;', '1', '作業截圖.png', '', '' ],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 含雙引號的值以雙引號包覆並將內部雙引號重複一次
	 * Example: 文字內容含雙引號
	 */
	public function test_文字內容含雙引號(): void {
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'echo "hello";',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 09:00:00',
			]
		);

		$stream = $this->call_static_or_fail( Csv::class, 'write_submission_csv', [ $this->assignment_400_id ] );
		$raw    = $this->read_csv_stream( $stream );

		$this->assertStringContainsString( '"echo ""hello"";"', $raw, 'CSV 內容應包含雙引號跳脫後的儲存格' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 最多輸出 5000 筆繳交
	 * Example: 作業有 5001 筆繳交時只輸出 5000 筆
	 */
	public function test_作業有5001筆繳交時只輸出5000筆(): void {
		$this->bulk_seed_submissions( $this->assignment_400_id, 5001 );

		$stream = $this->call_static_or_fail( Csv::class, 'write_submission_csv', [ $this->assignment_400_id ] );
		$raw    = $this->read_csv_stream( $stream );
		$rows   = $this->parse_csv_rows( $raw );

		// 扣掉標題列，資料列數應為 5000。
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

		$now         = '2026-09-16 09:00:00';
		$value_sql   = [];
		$placeholders = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$placeholders[] = '(%d, %d, %s, %s, %s, %s, %s, %s)';
			$value_sql[]     = $assignment_id;
			$value_sql[]     = 100000 + $i;
			$value_sql[]     = "bulk {$i}";
			$value_sql[]     = '[]';
			$value_sql[]     = $now;
			$value_sql[]     = 'pending';
			$value_sql[]     = $now;
			$value_sql[]     = $now;
		}

		$sql = "INSERT INTO `{$table}` (assignment_id, user_id, text_body, attachments, submitted_at, status, created_at, updated_at) VALUES "
			. \implode( ', ', $placeholders ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->query( $wpdb->prepare( $sql, $value_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
