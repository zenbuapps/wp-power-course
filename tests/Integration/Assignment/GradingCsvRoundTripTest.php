<?php
/**
 * 批改用 CSV「匯出 → 匯入」往返 整合測試（Issue #131 第二波 W2-R1）
 *
 * 依據：第二波計劃 §3 第 7 列、§4 DEC-W2-09（R-21 收斂：匯入依表頭名稱定位，別名含中文、英文 msgid、站台語系譯文）、
 *       DEC-W2-10（狀態值辨識、不使用「目前狀態」翻譯標籤）、DEC-W2-15、§14 R-W2-04 / R-W2-05；
 *       第一波 Service\Csv::write_grading_csv()（表頭來源，context 'assignment csv'）、specs/features/assignment/匯出批改用CSV.feature
 *
 * ─── 這份測試守什麼 ──────────────────────────────────────
 * 匯出的「可往返 CSV」必須能原封不動匯回來：
 *   1. 匯出的檔案（UTF-8 BOM + CRLF、表頭依站台語系翻譯、評語含逗號 / 引號 / 換行）整份匯入，全部列都被處理、狀態與評語不變
 *   2. 站台語系不是 zh_TW 時（表頭是英文 msgid，或 ja 等其他語系的譯文）同樣可匯入，不會整份變成「狀態無法辨識（空白）」
 *   3. 「可改」欄才是狀態來源；「目前狀態」欄（翻譯標籤，如「通過」）不是別名，不可被誤用
 * 第一波匯出測試已守住匯出內容本身（ExportGradingCsvTest），本檔只驗「匯出 → 匯入」這條鏈。
 *
 * ─── 防說謊 ──────────────────────────────────────────────
 * - 往返前先斷言匯出確實有資料列（rows > 0），否則「匯入 0 列全成功」會假綠。
 * - 模擬 ja 語系：先斷言匯出的表頭「真的」變成 ja 譯文（且不再是 zh_TW），再改「可改」欄的值為 fail，
 *   斷言結果真的是 fail——只斷言 skipped = 0 擋不住「欄位沒辨識、剛好都被當成缺漏」之外的錯誤。
 * - 不用 __() 包期望值；表頭字面值取自第一波 ExportGradingCsvTest 已斷言的 zh_TW 譯文。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Plugin;

/**
 * Class GradingCsvRoundTripTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class GradingCsvRoundTripTest extends AssignmentWave2TestCase {

	/** Service\Csv 類別 FQCN（第一波） */
	private const CSV_CLASS = 'J7\PowerCourse\Resources\Assignment\Service\Csv';

	/** 匯出表頭欄位位置（Service\Csv::write_grading_csv：submission_id / 學員 Email / 學員姓名 / 作業 / 繳交時間 / 目前狀態 / 可改狀態 / 可改回饋） */
	private const COL_ID             = 0;
	private const COL_CURRENT_STATUS = 5;
	private const COL_STATUS         = 6;
	private const COL_FEEDBACK       = 7;

	/** 批改作業完成後排信的 Action Scheduler hook */
	private const GRADED_HOOK = 'power_email_send_assignment_graded';

	/** @var callable|null 模擬其他語系譯文的 gettext_with_context filter，tear_down 移除 */
	private $translation_filter = null;

	/** @var int 繳交 A（Alice，作業 B，pass，帶評語） */
	private int $sub_a_id;

	/** @var int 繳交 B（Bob，作業 B，fail，帶評語） */
	private int $sub_b_id;

	/**
	 * 建立資料：匯入 feature 的 Background（500 / 501 pending）＋作業 B「JS 練習」上的兩筆已批改繳交（A pass、B fail）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_import_fixture();

		$assignment_b_id = $this->create_assignment(
			$this->fx['chapter'],
			[
				'title'      => 'JS 練習',
				'menu_order' => 10,
			]
		);
		$this->sub_a_id  = $this->seed_submission(
			[
				'assignment_id' => $assignment_b_id,
				'user_id'       => $this->fx['alice'],
				'text_body'     => 'let z = 3;',
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 10:00:00',
				'graded_by'     => $this->fx['admin'],
				'graded_at'     => '2026-09-16 11:00:00',
			]
		);
		$this->sub_b_id  = $this->seed_submission(
			[
				'assignment_id' => $assignment_b_id,
				'user_id'       => $this->fx['bob'],
				'text_body'     => 'var w = 4;',
				'status'        => 'fail',
				'feedback'      => '請補充型別說明',
				'submitted_at'  => '2026-09-16 12:00:00',
				'graded_by'     => $this->fx['admin'],
				'graded_at'     => '2026-09-16 13:00:00',
			]
		);
	}

	/**
	 * 清理：移除模擬語系的 filter
	 */
	public function tear_down(): void {
		if ( null !== $this->translation_filter ) {
			\remove_filter( 'gettext_with_context', $this->translation_filter, 10 );
			$this->translation_filter = null;
		}

		parent::tear_down();
	}

	// ========== helper ==========

	/**
	 * 匯出批改用 CSV（全部繳交），回傳原始位元組
	 *
	 * @return string
	 */
	private function export_raw(): string {
		$stream = $this->call_static_or_fail( self::CSV_CLASS, 'write_grading_csv', [ [] ] );

		return $this->read_csv_stream( $stream );
	}

	/**
	 * 匯出後解析成二維陣列（第一列為表頭）
	 *
	 * @return array<int, array<int, string>>
	 */
	private function export_rows(): array {
		return $this->parse_csv_rows( $this->export_raw() );
	}

	/**
	 * 找出匯出資料列中 submission_id 對應的列索引（含表頭，所以 >= 1）
	 *
	 * @param array<int, array<int, string>> $rows          export_rows() 的結果
	 * @param int                            $submission_id 繳交 ID
	 * @return int
	 */
	private function row_index_of( array $rows, int $submission_id ): int {
		foreach ( $rows as $index => $row ) {
			if ( $index > 0 && (string) $submission_id === ( $row[ self::COL_ID ] ?? null ) ) {
				return $index;
			}
		}

		$this->fail( "匯出的 CSV 找不到 submission_id = {$submission_id} 那一列" );
	}

	/**
	 * 直接改寫某筆繳交（測試資料準備，不經 Service）
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param array<string, mixed> $data          欄位
	 */
	private function update_submission_row( int $submission_id, array $data ): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		$wpdb->update( $table, $data, [ 'id' => $submission_id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * 取某筆繳交目前的 status 與 feedback（feedback 的 NULL 一律當 ''）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array{status: string, feedback: string}
	 */
	private function status_and_feedback( int $submission_id ): array {
		$row = $this->get_submission_row( $submission_id );
		$this->assertIsArray( $row, "繳交 {$submission_id} 應存在" );

		return [
			'status'   => (string) $row['status'],
			'feedback' => (string) $row['feedback'],
		];
	}

	// ========== 匯出 → 匯入 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 匯出的批改用 CSV（BOM + CRLF、站台語系表頭）原封不動餵給匯入：每一列都被處理（updated = 資料列數），
	 * 沒有任何列被略過或失敗，而且每筆繳交的 status 與 feedback 都不變（pending / pass / fail 各至少一筆）。
	 */
	public function test_匯出的批改用CSV原封不動匯入時全部列更新且狀態評語不變(): void {
		$raw    = $this->export_raw();
		$before = [
			$this->fx['sub_500'] => $this->status_and_feedback( $this->fx['sub_500'] ),
			$this->fx['sub_501'] => $this->status_and_feedback( $this->fx['sub_501'] ),
			$this->sub_a_id      => $this->status_and_feedback( $this->sub_a_id ),
			$this->sub_b_id      => $this->status_and_feedback( $this->sub_b_id ),
		];
		$this->assertSame( [ 'pending', 'pending', 'pass', 'fail' ], \array_column( \array_values( $before ), 'status' ), '前置條件不成立：fixture 的狀態組合' );
		$this->assertSame( 4, \count( $this->parse_csv_rows( $raw ) ) - 1, '前置條件不成立：匯出應有 4 筆資料列' );

		$result = $this->import_csv( $raw );

		$this->assert_import_summary( $result, 4, 4, 0, 0 );
		foreach ( $before as $submission_id => $snapshot ) {
			$this->assertSame( $snapshot, $this->status_and_feedback( $submission_id ), "繳交 {$submission_id} 的 status / feedback 不應改變" );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function round_trip_feedback_provider(): array {
		return [
			'含逗號、雙引號與 LF 換行' => [ "第一行,含逗號\n第二行 \"引號\" 與 'it\\'s'" ],
			'含 CRLF 換行'            => [ "第一行\r\n第二行\r\n第三行" ],
			'只有逗號與引號'          => [ '",",' ],
			'emoji 與 HTML'           => [ '很棒 😀 <b>粗體</b> & 繼續加油' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider round_trip_feedback_provider
	 *
	 * 含逗號 / 雙引號 / 換行的評語經「匯出 → 匯入」逐字保留（匯出端的跳脫與解析器必須互相吻合）
	 *
	 * @param string $feedback 評語原文
	 */
	public function test_含特殊字元的評語經往返後逐字保留( string $feedback ): void {
		$this->update_submission_row(
			$this->fx['sub_500'],
			[
				'status'    => 'pass',
				'feedback'  => $feedback,
				'graded_by' => $this->fx['admin'],
				'graded_at' => '2026-09-16 10:00:00',
			]
		);
		$raw = $this->export_raw();
		// 前置條件：匯出檔裡真的有這則評語「依 CSV 規則跳脫後」的樣子（含特殊字元者會被加引號、內部引號加倍）
		$escaped_cell = \substr( $this->build_csv( [ [ $feedback ] ] ), 0, -2 );
		$this->assertStringContainsString( $escaped_cell, $raw, '前置條件不成立：匯出檔應含這則評語（依 CSV 規則跳脫後）' );

		$result = $this->import_csv( $raw );

		$this->assert_import_summary( $result, 4, 4, 0, 0 );
		$this->assertSame(
			[
				'status'   => 'pass',
				'feedback' => $feedback,
			],
			$this->status_and_feedback( $this->fx['sub_500'] ),
			'評語經往返後應與原文逐字相同'
		);
	}

	// ========== 站台語系與表頭（R-21 收斂） ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 英文 msgid 表頭（站台語系為 en_US 時匯出的檔案）可匯入：
	 * 「Status (editable: pending/pass/fail)」「Feedback (editable)」是永遠可用的別名（DEC-W2-09）
	 */
	public function test_英文msgid表頭的CSV可匯入(): void {
		$csv = $this->build_csv(
			[
				[ 'submission_id', 'Student Email', 'Student Name', 'Assignment', 'Submitted At', 'Current Status', 'Status (editable: pending/pass/fail)', 'Feedback (editable)' ],
				[ (string) $this->fx['sub_500'], 'alice@test.com', 'Alice', '練習一：變數宣告', '2026-09-16T01:00:00.000Z', 'Pending', 'pass', '寫得很好' ],
				[ (string) $this->fx['sub_501'], 'bob@test.com', 'Bob', '練習一：變數宣告', '2026-09-16T07:30:00.000Z', 'Pending', 'fail', '請補充型別說明' ],
			],
			true
		);

		$result = $this->import_csv( $csv );

		$this->assert_import_summary( $result, 2, 2, 0, 0 );
		$this->assertSame( [ 'status' => 'pass', 'feedback' => '寫得很好' ], $this->status_and_feedback( $this->fx['sub_500'] ) );
		$this->assertSame( [ 'status' => 'fail', 'feedback' => '請補充型別說明' ], $this->status_and_feedback( $this->fx['sub_501'] ) );
	}

	/**
	 * 模擬站台語系為 ja：把匯出用的 _x(..., 'assignment csv', 'power-course') 譯文換成日文
	 *
	 * @param array<string, string> $map msgid → 譯文
	 */
	private function simulate_ja_translations( array $map ): void {
		$this->translation_filter = static function ( $translation, $text, $context, $domain ) use ( $map ) {
			if ( 'power-course' === $domain && 'assignment csv' === $context && isset( $map[ $text ] ) ) {
				return $map[ $text ];
			}

			return $translation;
		};
		\add_filter( 'gettext_with_context', $this->translation_filter, 10, 4 );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 站台語系為 ja 時匯出的檔案（表頭是日文譯文）可匯入，且「可改」兩欄真的被當成狀態與評語使用：
	 * 先確認匯出的表頭確實變成日文，再把 A（原本 pass）的可改欄改成 fail、評語改掉，匯入後必須真的是 fail。
	 */
	public function test_模擬ja語系匯出的CSV可匯入且可改欄位生效(): void {
		$ja_status   = 'ステータス(変更可 pending/pass/fail)';
		$ja_feedback = 'フィードバック(変更可)';
		$this->simulate_ja_translations(
			[
				'Status (editable: pending/pass/fail)' => $ja_status,
				'Feedback (editable)'                  => $ja_feedback,
				'Current Status'                       => '現在のステータス',
			]
		);

		$rows = $this->export_rows();
		$this->assertSame( $ja_status, $rows[0][ self::COL_STATUS ] ?? null, '前置條件不成立：匯出的狀態欄表頭應為 ja 譯文' );
		$this->assertSame( $ja_feedback, $rows[0][ self::COL_FEEDBACK ] ?? null, '前置條件不成立：匯出的回饋欄表頭應為 ja 譯文' );
		$this->assertNotContains( '狀態(可改 pending/pass/fail)', $rows[0], '前置條件不成立：表頭不應仍是 zh_TW 譯文（否則這支測試沒有驗到 ja）' );

		$index                              = $this->row_index_of( $rows, $this->sub_a_id );
		$rows[ $index ][ self::COL_STATUS ]   = 'fail';
		$rows[ $index ][ self::COL_FEEDBACK ] = '日本語站台改判';

		$result = $this->import_csv( $this->build_csv( $rows, true ) );

		$this->assert_import_summary( $result, 4, 4, 0, 0 );
		$this->assertSame(
			[
				'status'   => 'fail',
				'feedback' => '日本語站台改判',
			],
			$this->status_and_feedback( $this->sub_a_id ),
			'ja 表頭的「可改」兩欄應被辨識並套用'
		);
		$this->assertSame( [ 'status' => 'fail', 'feedback' => '請補充型別說明' ], $this->status_and_feedback( $this->sub_b_id ), '沒改的列應維持原狀' );
	}

	// ========== 「可改」欄 vs「目前狀態」欄（DEC-W2-10） ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 把「可改」欄改成 fail，「目前狀態」欄仍是「通過」→ 結果為 fail（以可改欄為準）
	 */
	public function test_可改欄改成fail而目前狀態欄仍是通過時結果為fail(): void {
		$rows  = $this->export_rows();
		$index = $this->row_index_of( $rows, $this->sub_a_id );
		$this->assertSame( '通過', $rows[ $index ][ self::COL_CURRENT_STATUS ] ?? null, '前置條件不成立：A 的「目前狀態」欄應為「通過」' );
		$this->assertSame( 'pass', $rows[ $index ][ self::COL_STATUS ] ?? null, '前置條件不成立：A 的「可改」欄應為 pass' );
		$rows[ $index ][ self::COL_STATUS ] = 'fail';

		$result = $this->import_csv( $this->build_csv( $rows, true ) );

		$this->assert_import_summary( $result, 4, 4, 0, 0 );
		$this->assertSame( 'fail', $this->status_and_feedback( $this->sub_a_id )['status'], '應以「可改」欄的 fail 為準，不是「目前狀態」欄的「通過」' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 反向：只改「目前狀態」欄（改成「通過」）、「可改」欄仍是 pending → 結果仍是 pending（「目前狀態」欄完全不被使用）
	 */
	public function test_只改目前狀態欄時不影響結果(): void {
		$rows  = $this->export_rows();
		$index = $this->row_index_of( $rows, $this->fx['sub_500'] );
		$this->assertSame( 'pending', $rows[ $index ][ self::COL_STATUS ] ?? null, '前置條件不成立：500 的「可改」欄應為 pending' );
		$rows[ $index ][ self::COL_CURRENT_STATUS ] = '通過';

		$result = $this->import_csv( $this->build_csv( $rows, true ) );

		$this->assert_import_summary( $result, 4, 4, 0, 0 );
		$this->assertSame( 'pending', $this->status_and_feedback( $this->fx['sub_500'] )['status'], '只改「目前狀態」欄不應影響結果' );
		$row = $this->get_submission_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row );
		$this->assertSame( (string) $this->fx['admin'], (string) $row['graded_by'], '前置條件：這一列確實被處理過（graded_by 已寫入），否則「仍是 pending」是假的' );
	}

	// ========== 副作用（DEC-W2-15）：整份重匯的後果 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 匯出整份再匯回，已 pass / fail 的學員會各再收到一封批改通知，pending 的不寄（DEC-W2-15 / UC-W2-2 = A：用戶裁決接受）。
	 * 把這個後果釘成測試，日後若要加「略過未變更列」之類的防護，必須有意識地改這支測試與 feature。
	 */
	public function test_整份匯出再匯回時已批改的學員會再收到一封批改通知(): void {
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'trigger_at' => 'assignment_graded',
				'course_ids' => [],
			]
		);
		$this->capture_mail();
		$raw = $this->export_raw();

		$result = $this->import_csv( $raw );
		$this->assert_import_summary( $result, 4, 4, 0, 0 );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertCount( 2, $this->captured_mails, '兩筆已批改（pass / fail）的繳交各寄一封，兩筆 pending 不寄' );
		$this->assert_mail_sent_to( 'alice@test.com' );
		$this->assert_mail_sent_to( 'bob@test.com' );
	}
}
