<?php
/**
 * 匯入批改結果CSV（內容層）整合測試（Issue #131 第二波 W2-R1）
 *
 * Feature: specs/features/assignment/匯入批改結果CSV.feature（Rule 後置：解析失敗 / 逐列批改 / 狀態別名 / 表頭別名 /
 *          回饋空白 / 副作用 / 缺 id / 狀態無法辨識 / 單列失敗）
 * 依據：第二波計劃 §3 第 5 列、§4 DEC-W2-09 ~ 16、§4.1、§4.2（CsvImport）、§7.3、§8、§13；
 *       用戶裁決 UC-W2-1（空檔 / 只有表頭 / 超過 5000 列 → row 0）、UC-W2-2 = A（整份重匯不防、缺回饋欄清空評語）、
 *       UC-W2-4（Big5 → 「CSV 解析失敗」）；藍圖 ADJ-W2-19（權限 / 檔案層在 ImportGradingCsvApiTest）
 *
 * ─── 分層（計劃 §3：匯入內容直呼 Service）──────────────────────
 * 直接呼叫 J7\PowerCourse\Resources\Assignment\Service\CsvImport::import( string $raw, int $actor_id ): array。
 * 權限與檔案層（401 / 403 / 沒附檔 / 10MB）在 ImportGradingCsvApiTest（dispatch 層）。
 * CSV 由 feature 的 Data Table 組成：第一列表頭、CRLF、無 BOM（BOM 與其他換行另有專屬測試）。
 *
 * ─── 防說謊（計劃 §13、W2:RED-PHP 補充 a～g）──────────────────
 * - 每個測試先斷言「回傳的摘要」（操作成功 / 期望的錯誤明細，含 total / updated / skipped / failed 四個 int 與
 *   errors 的 row 與 zh_TW 訊息字面值），再斷言 DB；「沒有被處理」的 Example 一律回頭斷言 500 / 501 完全沒被動到。
 * - 「狀態寫成 pending」會讓 500 的 status 看起來沒變，所以每個狀態例子都另斷言 feedback / graded_by / graded_at
 *   已被寫入，證明那一列真的被批改過。
 * - 不存在的 ID 用 nonexistent_id()，不用字面 9999。
 * - 寄信：capture_mail() → 匯入 → run_scheduled_actions( 'power_email_send_assignment_graded' ) → assert_mail_sent_to()。
 * - graded_at 斷言字面值 '2026-09-17 10:00:00'（set_now 注入）；finished_at 因 Progress::mark_finished() 用真實 wp_date()，
 *   以 freeze_wp_date_now() 固定後斷言字面值（見 AssignmentWave2TestCase::freeze_wp_date_now()）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class ImportGradingCsvTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class ImportGradingCsvTest extends AssignmentWave2TestCase {

	/** 批改作業完成後排信的 Action Scheduler hook（AssignmentGradedEmailTest::GRADED_HOOK） */
	private const GRADED_HOOK = 'power_email_send_assignment_graded';

	/** 匯出批改用 CSV 的可改兩欄表頭（feature 的 Data Table 欄名） */
	private const HEADER = [ 'submission_id', '狀態(可改 pending/pass/fail)', '講師回饋(可改)' ];

	/** zh_TW 訊息字面值（feature 文字，不用 __() 包期望值） */
	private const MSG_PARSE_FAILED   = 'CSV 解析失敗';
	private const MSG_MISSING_ID     = '缺少 submission_id';
	private const MSG_NOT_FOUND      = '繳交紀錄不存在';
	private const MSG_STATUS_OK      = '狀態無法辨識（ok），需為 pending/pass/fail';
	private const MSG_STATUS_BLANK   = '狀態無法辨識（空白），需為 pending/pass/fail';
	private const MSG_FEEDBACK_LONG  = 'feedback 不可超過 10000 字';

	/**
	 * 建立 Background（匯入批改結果CSV feature）：Admin/Alice/Bob、課程 100、章節 201、作業 400、
	 * 繳交 500 / 501 皆 pending、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_import_fixture();
	}

	/**
	 * 以 HEADER（或指定表頭）加資料列組出 CRLF、無 BOM 的 CSV
	 *
	 * @param array<int, array<int, string|int>> $data_rows 資料列
	 * @param array<int, string>|null            $header    表頭，預設 HEADER
	 * @return string
	 */
	private function csv_of( array $data_rows, ?array $header = null ): string {
		return $this->build_csv( \array_merge( [ $header ?? self::HEADER ], $data_rows ) );
	}

	/**
	 * Given 系統中有繳交 502（作業 400、Admin 本人，fail、評語「舊評語」、graded_by Admin、graded_at 2026-09-16 10:00:00）
	 *
	 * @return int 繳交 502 的實際 ID
	 */
	private function seed_submission_502(): int {
		return $this->seed_submission(
			[
				'assignment_id' => $this->fx['assignment'],
				'user_id'       => $this->fx['admin'],
				'text_body'     => '管理員試交',
				'status'        => 'fail',
				'feedback'      => '舊評語',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => $this->fx['admin'],
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);
	}

	/**
	 * 斷言繳交已被「批改」：status / feedback / graded_by / graded_at（graded_at 為 set_now 的字面值）
	 *
	 * @param int         $submission_id 繳交 ID
	 * @param string      $status        期望 status
	 * @param string|null $feedback      期望 feedback（null / '' = 已清空）
	 * @param string      $graded_at     期望 graded_at
	 */
	private function assert_graded( int $submission_id, string $status, ?string $feedback, string $graded_at = self::NOW ): void {
		$row = $this->get_submission_row( $submission_id );
		$this->assertIsArray( $row, "繳交 {$submission_id} 應存在" );

		$this->assertSame( $status, $row['status'], "繳交 {$submission_id} 的 status 不符" );
		$this->assertSame( (string) $feedback, (string) $row['feedback'], "繳交 {$submission_id} 的 feedback 不符" );
		$this->assertSame( (string) $this->fx['admin'], (string) $row['graded_by'], "繳交 {$submission_id} 的 graded_by 應為操作者 Admin" );
		$this->assertSame( $graded_at, $row['graded_at'], "繳交 {$submission_id} 的 graded_at 不符" );
	}

	// ========== 後置（回應）- CSV 無法解析時不處理任何列，回傳第 0 列的解析錯誤 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- CSV 無法解析時不處理任何列，回傳第 0 列的解析錯誤
	 * Example: 上傳無法解析的檔案（引號到檔尾沒有閉合）
	 */
	public function test_上傳無法解析的檔案(): void {
		$csv = self::HEADER[0] . ',' . self::HEADER[1] . ',' . self::HEADER[2] . "\r\n"
			. "500,pass,\"寫得很好\r\n"
			. "501,fail,請補充型別說明\r\n";

		$result = $this->import_csv( $csv );

		$this->assert_import_summary( $result, 0, 0, 0, 0, [ [ 'row' => 0, 'message' => self::MSG_PARSE_FAILED ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unparseable_csv_provider(): array {
		$header = 'submission_id,狀態(可改 pending/pass/fail),講師回饋(可改)' . "\r\n";

		return [
			'Big5 編碼（非 UTF-8，UC-W2-4）'          => [ $header . "500,pass,\xb3\x71\xb9\x4c\r\n" ],
			'Big5 編碼出現在表頭'                      => [ "submission_id,\xb3\x71\xb9\x4c,講師回饋\r\n500,pass,好\r\n" ],
			'UTF-16LE（Excel 的 Unicode 文字，BOM FF FE）' => [ "\xFF\xFE" . \mb_convert_encoding( $header . "500,pass,好\r\n", 'UTF-16LE', 'UTF-8' ) ],
			'閉合引號後接非分隔字元'                    => [ $header . "500,\"pass\"x,評語\r\n" ],
			'未加引號的欄位中間出現引號'                => [ $header . "500,pa\"ss,評語\r\n" ],
			'引號開在表頭且到檔尾未閉合'                => [ "submission_id,\"狀態\r\n500,pass\r\n" ],
		];
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider unparseable_csv_provider
	 *
	 * Rule: 後置（回應）- CSV 無法解析時不處理任何列，回傳第 0 列的解析錯誤
	 * 其他「無法解析」寫法（DEC-W2-11 的 (a)(c)(d)）：整檔一律回 row 0 的「CSV 解析失敗」，不處理任何列、不寫 DB
	 *
	 * @param string $csv CSV 原始內容
	 */
	public function test_各種無法解析的檔案都回傳第0列解析錯誤( string $csv ): void {
		$result = $this->import_csv( $csv );

		$this->assert_import_summary( $result, 0, 0, 0, 0, [ [ 'row' => 0, 'message' => self::MSG_PARSE_FAILED ] ] );
		$this->assert_import_untouched();
	}

	// ========== 後置（回應）- 空檔 / 只有表頭 / 超過 5000 列（UC-W2-1，用戶裁決） ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: （UC-W2-1，DEC-W2-12）空檔 → row 0、不處理任何列
	 * Example: 上傳空檔案（0 bytes）
	 */
	public function test_上傳空檔案時回傳第0列檔案為空(): void {
		$result = $this->import_csv( '' );

		$this->assert_import_summary( $result, 0, 0, 0, 0, [ [ 'row' => 0, 'message' => 'CSV 檔案為空' ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function header_only_csv_provider(): array {
		$header = 'submission_id,狀態(可改 pending/pass/fail),講師回饋(可改)';

		return [
			'表頭後有 CRLF'   => [ $header . "\r\n" ],
			'表頭後沒有換行'  => [ $header ],
			'BOM + 表頭'     => [ "\xEF\xBB\xBF" . $header . "\r\n" ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider header_only_csv_provider
	 *
	 * Rule: （UC-W2-1，DEC-W2-12）只有表頭沒有資料列 → row 0「CSV 沒有任何資料列」、不處理任何列
	 *
	 * @param string $csv CSV 原始內容
	 */
	public function test_只有表頭時回傳第0列沒有任何資料列( string $csv ): void {
		$result = $this->import_csv( $csv );

		$this->assert_import_summary( $result, 0, 0, 0, 0, [ [ 'row' => 0, 'message' => 'CSV 沒有任何資料列' ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: （UC-W2-1，DEC-W2-12）超過 5000 列 → row 0「單批匯入上限 5000 筆，請拆分後重試（目前 N 筆）」、不處理任何列
	 * Example: 5001 筆資料列
	 *
	 * 前兩列是會把 500 / 501 批改為 pass / fail 的有效列：若實作「先處理前 5000 列再報錯」，這兩筆就會被改到。
	 */
	public function test_超過5000列時整批拒絕且不處理任何列(): void {
		$rows   = [
			[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
			[ (string) $this->fx['sub_501'], 'fail', '請補充型別說明' ],
		];
		for ( $i = 3; $i <= 5001; $i++ ) {
			$rows[] = [ (string) $this->fx['sub_500'], 'ok', "filler {$i}" ];
		}
		$this->assertCount( 5001, $rows, '前置條件不成立：應有 5001 筆資料列' );

		$result = $this->import_csv( $this->csv_of( $rows ) );

		$this->assert_import_summary( $result, 0, 0, 0, 0, [ [ 'row' => 0, 'message' => '單批匯入上限 5000 筆，請拆分後重試（目前 5001 筆）' ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: （UC-W2-1，DEC-W2-12）上限邊界：剛好 5000 筆資料列照常處理
	 *
	 * 第 1 列是有效的 pass；其餘 4999 列狀態寫 ok（辨識失敗即略過，不碰 DB，所以這支測試很快）。
	 */
	public function test_剛好5000列時照常處理(): void {
		$rows            = [ [ (string) $this->fx['sub_500'], 'pass', '寫得很好' ] ];
		$expected_errors = [];
		for ( $i = 2; $i <= 5000; $i++ ) {
			$rows[]            = [ (string) $this->fx['sub_501'], 'ok', "filler {$i}" ];
			$expected_errors[] = [
				'row'     => $i + 1,
				'message' => self::MSG_STATUS_OK,
			];
		}
		$this->assertCount( 5000, $rows, '前置條件不成立：應有 5000 筆資料列' );

		$result = $this->import_csv( $this->csv_of( $rows ) );

		$this->assert_import_summary( $result, 5000, 1, 4999, 0, $expected_errors );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
		$row_501 = $this->get_submission_row( $this->fx['sub_501'] );
		$this->assertIsArray( $row_501 );
		$this->assertSame( 'pending', $row_501['status'], '501 的列全是辨識失敗，不應被批改' );
	}

	// ========== 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋
	 * Example: 兩列分別批改為通過與未通過
	 */
	public function test_兩列分別批改為通過與未通過(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
					[ (string) $this->fx['sub_501'], 'fail', '請補充型別說明' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 2, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
		$this->assert_graded( $this->fx['sub_501'], 'fail', '請補充型別說明' );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋
	 * 邊界：graded_by 是傳入的操作者（$actor_id），不是「目前登入的使用者」
	 * （Service 層被 API 層以 get_current_user_id() 呼叫；這裡故意讓兩者不同來區分）
	 */
	public function test_graded_by為傳入的操作者而非目前登入者(): void {
		\wp_set_current_user( $this->fx['bob'] );

		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], 'pass', '寫得很好' ] ] ), $this->fx['admin'] );

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
	}

	// ========== 後置（狀態）- 狀態欄接受中英文多種寫法（不分大小寫） ==========

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function status_spelling_provider(): array {
		return [
			'pending → pending' => [ 'pending', 'pending' ],
			'待批改 → pending'   => [ '待批改', 'pending' ],
			'PASS → pass'       => [ 'PASS', 'pass' ],
			'passed → pass'     => [ 'passed', 'pass' ],
			'通過 → pass'        => [ '通過', 'pass' ],
			'fail → fail'       => [ 'fail', 'fail' ],
			'failed → fail'     => [ 'failed', 'fail' ],
			'未通過 → fail'      => [ '未通過', 'fail' ],
			'不通過 → fail'      => [ '不通過', 'fail' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider status_spelling_provider
	 *
	 * Rule: 後置（狀態）- 狀態欄接受中英文多種寫法（不分大小寫）
	 * Scenario Outline: 狀態欄寫 <寫法> 時批改為 <status>
	 *
	 * 500 起始就是 pending，所以 pending 的兩個例子光看 status 分辨不出「有處理」與「沒處理」，
	 * assert_graded() 另斷言 feedback / graded_by / graded_at 都已寫入。
	 *
	 * @param string $spelling 狀態欄的寫法
	 * @param string $status   期望批改後的 status
	 */
	public function test_狀態欄接受中英文多種寫法( string $spelling, string $status ): void {
		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], $spelling, '評語' ] ] ) );

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], $status, '評語' );
	}

	// ========== 後置（狀態）- 表頭接受欄位名稱別名 ==========

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function header_alias_provider(): array {
		return [
			// feature 的兩個 Example
			'submissionId / 狀態(可改) / 講師回饋'      => [ 'submissionId', '狀態(可改)', '講師回饋' ],
			'submission_id / status / 講師回饋'        => [ 'submission_id', 'status', '講師回饋' ],
			// DEC-W2-09 的其餘別名與正規化（大小寫、英文 msgid）
			'submission_id / 狀態 / 講師回饋(可改)'      => [ 'submission_id', '狀態', '講師回饋(可改)' ],
			'SUBMISSION_ID / STATUS / 講師回饋（大小寫）' => [ 'SUBMISSION_ID', 'STATUS', '講師回饋' ],
			'英文 msgid 的狀態與回饋欄'                  => [ 'submission_id', 'Status (editable: pending/pass/fail)', 'Feedback (editable)' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider header_alias_provider
	 *
	 * Rule: 後置（狀態）- 表頭接受欄位名稱別名
	 * Scenario Outline: 使用表頭 <狀態欄> 與 <回饋欄>
	 *
	 * @param string $id_header       id 欄表頭
	 * @param string $status_header   狀態欄表頭
	 * @param string $feedback_header 回饋欄表頭
	 */
	public function test_表頭接受欄位名稱別名( string $id_header, string $status_header, string $feedback_header ): void {
		$result = $this->import_csv(
			$this->csv_of(
				[ [ (string) $this->fx['sub_500'], 'pass', '寫得很好' ] ],
				[ $id_header, $status_header, $feedback_header ]
			)
		);

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 表頭接受欄位名稱別名（DEC-W2-09：依序取第一個「非空」的值）
	 * 同一份 CSV 同時有「狀態(可改 pending/pass/fail)」與「status」兩欄：
	 * 前者有值時以前者為準；前者空白時退而取後者
	 */
	public function test_多個狀態欄別名時取第一個非空值(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $this->fx['sub_500'], '', 'pass', '前者空白取後者' ],
					[ (string) $this->fx['sub_501'], 'fail', 'pass', '前者有值取前者' ],
				],
				[ 'submission_id', '狀態(可改 pending/pass/fail)', 'status', '講師回饋(可改)' ]
			)
		);

		$this->assert_import_summary( $result, 2, 2, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '前者空白取後者' );
		$this->assert_graded( $this->fx['sub_501'], 'fail', '前者有值取前者' );
	}

	// ========== 後置（狀態）- 講師回饋欄空白時清空評語 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 講師回饋欄空白時清空評語
	 * Example: 回饋欄空白
	 */
	public function test_回饋欄空白時清空評語(): void {
		$submission_502_id = $this->seed_submission_502();

		$result = $this->import_csv( $this->csv_of( [ [ (string) $submission_502_id, 'pass', '' ] ] ) );

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $submission_502_id, 'pass', '' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 講師回饋欄空白時清空評語（DEC-W2-15 / UC-W2-2 = A：照參考實作的現行行為）
	 * 邊界：CSV 根本沒有回饋欄時，所有被處理的列評語一律被清空（不是「保留舊評語」）。
	 * 這是用戶裁決接受的行為（整份重匯不防、不加提醒文字），測試把它釘住，改動時必須有意識地改測試。
	 */
	public function test_沒有回饋欄時評語被清空(): void {
		$submission_502_id = $this->seed_submission_502();

		$result = $this->import_csv(
			$this->csv_of(
				[ [ (string) $submission_502_id, 'pass' ] ],
				[ 'submission_id', '狀態(可改 pending/pass/fail)' ]
			)
		);

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $submission_502_id, 'pass', '' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function verbatim_feedback_provider(): array {
		return [
			'含逗號'                  => [ 'a,b,c' ],
			'含雙引號'                => [ '他說 "寫得很好" 然後離開' ],
			'只有逗號與引號'          => [ '",",' ],
			'含 LF 換行'              => [ "第一行\n第二行" ],
			'含 CRLF 換行'            => [ "第一行\r\n第二行" ],
			'Windows 路徑與結尾反斜線' => [ 'C:\path "q"\\' ],
			'HTML 與 script'          => [ '<b>粗體</b> & <script>alert(1)</script>' ],
			'單引號與 SQL 字元'       => [ "it's'; DROP TABLE x; --" ],
			'emoji'                   => [ '很棒 😀 繼續加油' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider verbatim_feedback_provider
	 *
	 * Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋
	 * 邊界：講師回饋含逗號 / 雙引號 / 換行 / 反斜線 / HTML / 單引號 / emoji 時逐字保存
	 * （解析器不改寫格內容；寫入不 sanitize、不多一層 slash，memory project_wp_slash_symmetry）
	 *
	 * @param string $feedback 評語原文
	 */
	public function test_回饋含特殊字元時逐字保存( string $feedback ): void {
		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], 'pass', $feedback ] ] ) );

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', $feedback );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋
	 * 邊界：評語剛好 10000 字成功（Grading 的上限是「不可超過」）
	 */
	public function test_評語剛好10000字時成功(): void {
		$feedback = \str_repeat( '評', 10000 );

		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], 'pass', $feedback ] ] ) );

		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', $feedback );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 批改失敗的列計入失敗並記錄錯誤，不影響其他列
	 * 邊界：評語 10001 字 → 該列 failed，訊息沿用批改作業（Grading）的「feedback 不可超過 10000 字」；其他列照常
	 */
	public function test_評語超過10000字時該列失敗(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $this->fx['sub_500'], 'pass', \str_repeat( '評', 10001 ) ],
					[ (string) $this->fx['sub_501'], 'fail', '請補充型別說明' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 1, 0, 1, [ [ 'row' => 2, 'message' => self::MSG_FEEDBACK_LONG ] ] );
		$row_500 = $this->get_submission_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row_500 );
		$this->assertSame( 'pending', $row_500['status'], '評語過長的那一列不得寫入任何東西' );
		$this->assertNull( $row_500['graded_at'] );
		$this->assert_graded( $this->fx['sub_501'], 'fail', '請補充型別說明' );
	}

	// ========== 後置（狀態）- 每一列觸發與批改作業相同的副作用 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 每一列觸發與批改作業相同的副作用
	 * Example: 匯入通過時自動完成章節並寄送批改通知
	 *
	 * finished_at：Progress::mark_finished() 用真實 wp_date()，先 freeze_wp_date_now() 才能斷言 feature 的字面值。
	 */
	public function test_匯入通過時自動完成章節並寄送批改通知(): void {
		$this->freeze_wp_date_now( self::NOW );
		$this->assertEmpty(
			$this->get_chapter_meta( $this->fx['chapter'], $this->fx['alice'], 'finished_at' ),
			'前置條件不成立：Alice 在章節 201 應無 finished_at'
		);
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'trigger_at' => 'assignment_graded',
				'course_ids' => [],
			]
		);
		$this->capture_mail();

		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], 'pass', '寫得很好' ] ] ) );
		$this->assert_import_summary( $result, 1, 1, 0, 0 );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertSame(
			'2026-09-17 10:00:00',
			$this->get_chapter_meta( $this->fx['chapter'], $this->fx['alice'], 'finished_at' ),
			'匯入通過後章節 201 應自動標記完成，finished_at 應為 2026-09-17 10:00:00'
		);
		$this->assert_mail_sent_to( 'alice@test.com' );
		$this->assertCount( 1, $this->captured_mails, '應恰好寄出一封批改通知' );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function other_status_side_effect_provider(): array {
		return [
			'fail：寄批改通知，但不完成章節'   => [ 'fail', true ],
			'pending：不寄批改通知，也不完成章節' => [ 'pending', false ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider other_status_side_effect_provider
	 *
	 * Rule: 後置（狀態）- 每一列觸發與批改作業相同的副作用
	 * 邊界：fail 只寄信、不完成章節；pending 兩者都不做（與「批改作業」同一套規則，匯入必須走 Grading::grade()）
	 *
	 * @param string $status      狀態欄的值
	 * @param bool   $expect_mail 是否應寄出批改通知
	 */
	public function test_匯入fail與pending時的副作用與批改作業一致( string $status, bool $expect_mail ): void {
		$this->freeze_wp_date_now( self::NOW );
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'trigger_at' => 'assignment_graded',
				'course_ids' => [],
			]
		);
		$this->capture_mail();

		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], $status, '評語' ] ] ) );
		$this->assert_import_summary( $result, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], $status, '評語' );

		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertEmpty(
			$this->get_chapter_meta( $this->fx['chapter'], $this->fx['alice'], 'finished_at' ),
			"狀態 {$status} 不應自動完成章節"
		);
		if ( $expect_mail ) {
			$this->assert_mail_sent_to( 'alice@test.com' );
		} else {
			$this->assertSame( [], $this->captured_mails, "狀態 {$status} 不應寄送批改通知" );
		}
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 每一列觸發與批改作業相同的副作用（DEC-W2-15 / UC-W2-2 = A：每一列都是一次批改）
	 * 邊界：同一份 CSV 匯入兩次（兩次的批改時間不同），pass 列每次都再排一次批改通知，Alice 共收到兩封。
	 * 這是用戶裁決接受的行為（整份重匯不防），測試把它釘住。
	 */
	public function test_同一份CSV匯入兩次時通過列各寄一封批改通知(): void {
		$this->freeze_wp_date_now( self::NOW );
		$this->create_email_template(
			[
				'post_title' => '作業批改結果通知',
				'trigger_at' => 'assignment_graded',
				'course_ids' => [],
			]
		);
		$this->capture_mail();
		$csv = $this->csv_of( [ [ (string) $this->fx['sub_500'], 'pass', '寫得很好' ] ] );

		$first = $this->import_csv( $csv );
		$this->assert_import_summary( $first, 1, 1, 0, 0 );
		$this->run_scheduled_actions( self::GRADED_HOOK );
		$this->assertCount( 1, $this->captured_mails, '第一次匯入後應寄出一封批改通知' );
		$this->assert_mail_sent_to( 'alice@test.com' );

		$this->set_now( '2026-09-17 11:00:00' );
		$second = $this->import_csv( $csv );
		$this->assert_import_summary( $second, 1, 1, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好', '2026-09-17 11:00:00' );
		$this->run_scheduled_actions( self::GRADED_HOOK );

		$this->assertCount( 2, $this->captured_mails, '第二次匯入（graded_at 不同）應再寄出一封，共兩封' );
	}

	// ========== 後置（回應）- 缺少 submission_id 的列略過並記錄錯誤 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 缺少 submission_id 的列略過並記錄錯誤
	 * Example: 第 2 列缺少 submission_id
	 */
	public function test_第2列缺少submission_id(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[
					[ '', 'pass', '寫得很好' ],
					[ (string) $this->fx['sub_501'], 'pass', '很好' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 1, 1, 0, [ [ 'row' => 2, 'message' => self::MSG_MISSING_ID ] ] );
		$this->assert_graded( $this->fx['sub_501'], 'pass', '很好' );
		$row_500 = $this->get_submission_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row_500 );
		$this->assertSame( 'pending', $row_500['status'], '缺 id 的列不得影響任何繳交' );
		$this->assertNull( $row_500['graded_at'] );
	}

	// ========== 後置（回應）- 狀態無法辨識的列略過並記錄錯誤 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 狀態無法辨識的列略過並記錄錯誤
	 * Example: 狀態寫成 ok
	 */
	public function test_狀態寫成ok(): void {
		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], 'ok', '寫得很好' ] ] ) );

		$this->assert_import_summary( $result, 1, 0, 1, 0, [ [ 'row' => 2, 'message' => self::MSG_STATUS_OK ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function unrecognized_status_provider(): array {
		return [
			'大寫 OK 原樣顯示（不轉小寫）'      => [ 'OK', 'OK' ],
			'英文單字 Done'                    => [ 'Done', 'Done' ],
			'中文「很好」'                      => [ '很好', '很好' ],
			'數字 1'                           => [ '1', '1' ],
			'前後空白被 trim 後顯示'           => [ '  Maybe  ', 'Maybe' ],
			'接近但不在清單內的 passs'          => [ 'passs', 'passs' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider unrecognized_status_provider
	 *
	 * Rule: 後置（回應）- 狀態無法辨識的列略過並記錄錯誤（DEC-W2-16：訊息帶入 trim 後的原值，不轉小寫）
	 *
	 * @param string $status   狀態欄的值
	 * @param string $expected 訊息中顯示的原值
	 */
	public function test_狀態無法辨識時訊息帶入原值( string $status, string $expected ): void {
		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], $status, '寫得很好' ] ] ) );

		$this->assert_import_summary(
			$result,
			1,
			0,
			1,
			0,
			[
				[
					'row'     => 2,
					'message' => "狀態無法辨識（{$expected}），需為 pending/pass/fail",
				],
			]
		);
		$this->assert_import_untouched();
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function blank_status_provider(): array {
		return [
			'空字串'       => [ '' ],
			'只有空白字元' => [ '   ' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider blank_status_provider
	 *
	 * Rule: 後置（回應）- 狀態無法辨識的列略過並記錄錯誤（DEC-W2-16：空值時原值顯示為「空白」）
	 *
	 * @param string $status 狀態欄的值
	 */
	public function test_狀態空白時略過並標示空白( string $status ): void {
		$result = $this->import_csv( $this->csv_of( [ [ (string) $this->fx['sub_500'], $status, '寫得很好' ] ] ) );

		$this->assert_import_summary( $result, 1, 0, 1, 0, [ [ 'row' => 2, 'message' => self::MSG_STATUS_BLANK ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 狀態無法辨識的列略過並記錄錯誤
	 * 邊界：CSV 完全沒有任何狀態欄（只有「目前狀態」這種不是別名的欄）時，每列都是「狀態無法辨識（空白）」，
	 * 「目前狀態」欄（匯出時的翻譯標籤）不可被誤當成狀態欄使用（DEC-W2-10）
	 */
	public function test_沒有狀態欄時每列都略過且目前狀態欄不被誤用(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[ [ (string) $this->fx['sub_500'], '通過', '寫得很好' ] ],
				[ 'submission_id', '目前狀態', '講師回饋(可改)' ]
			)
		);

		$this->assert_import_summary( $result, 1, 0, 1, 0, [ [ 'row' => 2, 'message' => self::MSG_STATUS_BLANK ] ] );
		$this->assert_import_untouched();
	}

	// ========== 後置（回應）- 批改失敗的列計入失敗並記錄錯誤，不影響其他列 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 後置（回應）- 批改失敗的列計入失敗並記錄錯誤，不影響其他列
	 * Example: 第 2 列繳交不存在、第 3 列正常（feature 的 9999 改用 nonexistent_id()）
	 */
	public function test_第2列繳交不存在且第3列正常(): void {
		$missing_id = $this->nonexistent_id();

		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $missing_id, 'pass', '很好' ],
					[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 1, 0, 1, [ [ 'row' => 2, 'message' => self::MSG_NOT_FOUND ] ] );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_submission_id_provider(): array {
		// {id} 會被換成繳交 500 的「實際 ID」：feature 的 500 只是代號，auto_increment 的真實值不是 500，
		// 若直接寫字面 500.0 / 500abc，被 (int) 轉型的實作也只會得到一個不存在的 ID，測不出「誤批別人的繳交」。
		return [
			'英文字 abc'                      => [ 'abc' ],
			'小數（實際 ID 後接 .0）'          => [ '{id}.0' ],
			'數字後夾雜文字（實際 ID 後接 abc）' => [ '{id}abc' ],
			'負數（負的實際 ID）'              => [ '-{id}' ],
			'負數 -1'                         => [ '-1' ],
			'零'                              => [ '0' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider invalid_submission_id_provider
	 *
	 * Rule: 後置（回應）- 批改失敗的列計入失敗並記錄錯誤（DEC-W2-14）
	 * 邊界：submission_id 非空但不是正整數 → failed「繳交紀錄不存在」（不是 skipped「缺少 submission_id」），
	 * 而且不得被 (int) 轉型成 500 去批改別人的繳交
	 *
	 * @param string $submission_id CSV 中的 submission_id（{id} = 繳交 500 的實際 ID）
	 */
	public function test_submission_id不是正整數時該列失敗( string $submission_id ): void {
		$submission_id = \str_replace( '{id}', (string) $this->fx['sub_500'], $submission_id );

		$result = $this->import_csv( $this->csv_of( [ [ $submission_id, 'pass', '寫得很好' ] ] ) );

		$this->assert_import_summary( $result, 1, 0, 0, 1, [ [ 'row' => 2, 'message' => self::MSG_NOT_FOUND ] ] );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 批改失敗的列計入失敗並記錄錯誤，不影響其他列
	 * 邊界：所屬作業在垃圾桶的繳交視同不存在（與批改作業一致，Grading::grade()）
	 */
	public function test_作業在垃圾桶的繳交該列失敗(): void {
		$trash_assignment_id = $this->create_assignment(
			$this->fx['chapter'],
			[
				'title'       => '已刪除練習',
				'menu_order'  => 10,
				'post_status' => 'trash',
			]
		);
		$trash_submission_id = $this->seed_submission(
			[
				'assignment_id' => $trash_assignment_id,
				'user_id'       => $this->fx['bob'],
				'text_body'     => '舊作業',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);
		$this->assertGreaterThan( 0, $trash_submission_id, '前置條件不成立：垃圾桶作業的繳交應已寫入' );

		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $trash_submission_id, 'pass', '很好' ],
					[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 1, 0, 1, [ [ 'row' => 2, 'message' => self::MSG_NOT_FOUND ] ] );
		$trash_row = $this->get_submission_row( $trash_submission_id );
		$this->assertIsArray( $trash_row );
		$this->assertSame( 'pending', $trash_row['status'], '垃圾桶作業的繳交不得被批改' );
		$this->assertNull( $trash_row['graded_at'] );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 依 submission_id 逐列套用狀態與講師回饋（DEC-W2-14）
	 * 邊界：同一個 submission_id 出現兩列 → 依序套用、最後一列為準，兩列都計入 updated
	 */
	public function test_同一個submission_id出現兩列時最後一列為準(): void {
		$result = $this->import_csv(
			$this->csv_of(
				[
					[ (string) $this->fx['sub_500'], 'pass', '第一次評語' ],
					[ (string) $this->fx['sub_500'], 'fail', '第二次評語' ],
				]
			)
		);

		$this->assert_import_summary( $result, 2, 2, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'fail', '第二次評語' );
	}

	// ========== 全空列（DEC-W2-11） ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（回應）- 缺少 submission_id / 狀態無法辨識 / 批改失敗的列都記錄錯誤（DEC-W2-11：列號規則）
	 * 邊界：夾在中間的全空列（空行與 ",,"）略過、不計入 total；錯誤列號 = 「非空資料列序號 + 2」，不是檔案的實體行號
	 *
	 * 資料列（非空）：#1 → 第 2 列（有效 pass）；#2 → 第 3 列（不存在的 id）；#3 → 第 4 列（狀態 ok）。
	 * 實體行號則分別是 3 / 5 / 7，兩者刻意不同。
	 */
	public function test_中間夾全空列時不計入total且列號依非空資料列序號(): void {
		$missing_id = $this->nonexistent_id();
		$csv        = 'submission_id,狀態(可改 pending/pass/fail),講師回饋(可改)' . "\r\n"
			. "\r\n"
			. "{$this->fx['sub_500']},pass,寫得很好\r\n"
			. ",,\r\n"
			. "{$missing_id},pass,x\r\n"
			. "\r\n"
			. "{$this->fx['sub_501']},ok,y\r\n";

		$result = $this->import_csv( $csv );

		$this->assert_import_summary(
			$result,
			3,
			1,
			1,
			1,
			[
				[ 'row' => 3, 'message' => self::MSG_NOT_FOUND ],
				[ 'row' => 4, 'message' => self::MSG_STATUS_OK ],
			]
		);
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
	}

	// ========== BOM 與換行（Excel 實際產出） ==========

	/**
	 * @return array<string, array{0: bool, 1: string}>
	 */
	public static function bom_and_eol_provider(): array {
		return [
			'BOM + CRLF（Excel / 本外掛匯出）' => [ true, "\r\n" ],
			'BOM + LF'                        => [ true, "\n" ],
			'無 BOM + LF'                     => [ false, "\n" ],
			'無 BOM + CR（舊版 Mac）'          => [ false, "\r" ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider bom_and_eol_provider
	 *
	 * Rule: 後置（狀態）- 依 submission_id 逐列套用狀態與講師回饋
	 * 邊界：UTF-8 BOM 不可汙染第一欄表頭（否則 submission_id 欄對不到，整份全部「缺少 submission_id」）；
	 * CRLF / LF / CR 三種換行都能解析（R-W2-04：Excel 實際產出的檔案）
	 *
	 * @param bool   $bom 是否帶 UTF-8 BOM
	 * @param string $eol 列分隔
	 */
	public function test_BOM與各種換行都能匯入( bool $bom, string $eol ): void {
		$csv = $this->build_csv(
			[
				self::HEADER,
				[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
				[ (string) $this->fx['sub_501'], 'fail', '請補充型別說明' ],
			],
			$bom,
			$eol
		);

		$result = $this->import_csv( $csv );

		$this->assert_import_summary( $result, 2, 2, 0, 0 );
		$this->assert_graded( $this->fx['sub_500'], 'pass', '寫得很好' );
		$this->assert_graded( $this->fx['sub_501'], 'fail', '請補充型別說明' );
	}
}
