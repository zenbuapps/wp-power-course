<?php
/**
 * 作業匯入用 CSV 解析器 單元測試（Issue #131 第二波 W2-R1）
 *
 * 對象：J7\PowerCourse\Resources\Assignment\Utils\CsvParser::parse( string $raw ): array、normalize_header( string $header ): string
 * 依據：第二波計劃 §4 DEC-W2-09（表頭正規化）、DEC-W2-11（自寫嚴格解析器與四種「無法解析」）、§4.2 介面契約、R-W2-04；
 *       用戶裁決 UC-W2-4（Big5 等非 UTF-8 → 當作無法解析）
 *
 * ─── 為什麼要自寫嚴格解析器、為什麼要有這份單元測試 ──────────────
 * PHP 的 fgetcsv / str_getcsv 對「引號沒閉合」「閉合引號後接殘字」「欄位中間出現引號」都不報錯（靜默吞到檔尾或改寫內容），
 * 做不出 feature 要的「CSV 無法解析 → 不處理任何列」。所以四種無法解析一律要丟 \UnexpectedValueException，
 * 這份測試把四種都釘住，並釘住「合法但刁鑽」的寫法（BOM、三種換行、引號內逗號與換行、"" 跳脫）不可被誤判為無法解析。
 *
 * ─── 範圍說明 ─────────────────────────────────────────────
 * 空檔 / 只有表頭 / 超過 5000 列（UC-W2-1）由 CsvImport::import() 轉成 row 0 的訊息，計劃 §4.2 與 §8 對「這三種是由 parse()
 * 丟例外還是由 CsvImport 判斷」的描述略有出入，所以本檔不斷言 parse() 對這三種的行為，只在 ImportGradingCsvTest 驗最終結果。
 *
 * 純單元測試，不碰資料庫；繼承 AssignmentWave2TestCase 只為了沿用 call_static_or_fail() 與統一的測試基底。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentCsvParserTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class AssignmentCsvParserTest extends AssignmentWave2TestCase {

	/**
	 * 呼叫 CsvParser::parse()；class / method 不存在時以 Failure 取代 fatal，解析例外原樣往外拋
	 *
	 * @param string $raw CSV 原始內容
	 * @return array<int, array<string, string>>
	 */
	private function parse( string $raw ): array {
		/** @var array<int, array<string, string>> $rows */
		$rows = $this->call_static_or_fail( self::CSV_PARSER_CLASS, 'parse', [ $raw ] );

		return $rows;
	}

	/**
	 * 斷言解析結果與期望相同：外層必須是從 0 起算的 list、值為字串；每一列的「鍵順序」不屬於契約，比對前先 ksort
	 *
	 * @param array<int, array<string, string>> $expected 期望的資料列
	 * @param array<int, array<string, string>> $actual   parse() 的回傳
	 * @param string                            $message  失敗說明
	 */
	private function assert_rows( array $expected, array $actual, string $message = '' ): void {
		$this->assertSame( \array_keys( $expected ), \array_keys( $actual ), '回傳應為從 0 起算的 list，且列數相符' . $message );

		$sort = static function ( array $rows ): array {
			foreach ( $rows as $index => $row ) {
				\ksort( $row );
				$rows[ $index ] = $row;
			}

			return $rows;
		};

		$this->assertSame( $sort( $expected ), $sort( $actual ), '解析結果不符' . $message );
	}

	/**
	 * 斷言 parse() 對 $raw 丟出 \UnexpectedValueException（無法解析）
	 *
	 * @param string $raw     CSV 原始內容
	 * @param string $context 失敗時的說明
	 */
	private function assert_unparseable( string $raw, string $context ): void {
		$thrown = null;
		try {
			$this->parse( $raw );
		} catch ( \UnexpectedValueException $e ) {
			$thrown = $e;
		}

		$this->assertInstanceOf( \UnexpectedValueException::class, $thrown, "{$context} 應丟出 \\UnexpectedValueException（無法解析）" );
	}

	// ========== 基本 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 鍵為正規化後的表頭（小寫、空白換底線）、值為字串（500 不轉成 int）；回傳是從 0 起算的 list
	 */
	public function test_以正規化表頭為鍵並回傳字串值(): void {
		$rows = $this->parse( "Submission_ID,Student Email,Status\r\n500,alice@test.com,pass\r\n501,bob@test.com,fail\r\n" );

		$this->assert_rows(
			[
				[
					'submission_id' => '500',
					'student_email' => 'alice@test.com',
					'status'        => 'pass',
				],
				[
					'submission_id' => '501',
					'student_email' => 'bob@test.com',
					'status'        => 'fail',
				],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 開頭的 UTF-8 BOM 不可汙染第一個表頭（否則第一欄的鍵會是 "\xEF\xBB\xBFsubmission_id"）
	 */
	public function test_開頭的BOM會被去除(): void {
		$rows = $this->parse( "\xEF\xBB\xBFsubmission_id,status\r\n500,pass\r\n" );

		$this->assert_rows( [ [ 'submission_id' => '500', 'status' => 'pass' ] ], $rows );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function line_ending_provider(): array {
		$cases = [
			'CRLF' => "\r\n",
			'LF'   => "\n",
			'CR'   => "\r",
		];

		$data = [];
		foreach ( $cases as $label => $eol ) {
			$data[ "{$label}，結尾有換行" ]   = [ "a,b{$eol}1,2{$eol}3,4{$eol}" ];
			$data[ "{$label}，結尾沒有換行" ] = [ "a,b{$eol}1,2{$eol}3,4" ];
		}
		$data['CRLF 與 LF 混用'] = [ "a,b\r\n1,2\n3,4\r\n" ];

		return $data;
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider line_ending_provider
	 *
	 * CRLF / LF / CR 與混用都能切出同樣的兩筆資料列，結尾有沒有換行都一樣（不多出空白列）
	 *
	 * @param string $raw CSV 原始內容
	 */
	public function test_各種換行都能切出相同的資料列( string $raw ): void {
		$rows = $this->parse( $raw );

		$this->assert_rows(
			[
				[ 'a' => '1', 'b' => '2' ],
				[ 'a' => '3', 'b' => '4' ],
			],
			$rows
		);
	}

	// ========== 引號 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 引號欄位內的逗號、LF、CRLF、CR 都是格內容，逐字保留（不被當成欄 / 列分隔，也不被正規化成別種換行）
	 */
	public function test_引號內的逗號與換行逐字保留(): void {
		$raw  = "id,feedback\r\n"
			. "1,\"a,b\"\r\n"
			. "2,\"第一行\n第二行\"\r\n"
			. "3,\"第一行\r\n第二行\"\r\n"
			. "4,\"第一行\r第二行\"\r\n";
		$rows = $this->parse( $raw );

		$this->assert_rows(
			[
				[ 'id' => '1', 'feedback' => 'a,b' ],
				[ 'id' => '2', 'feedback' => "第一行\n第二行" ],
				[ 'id' => '3', 'feedback' => "第一行\r\n第二行" ],
				[ 'id' => '4', 'feedback' => "第一行\r第二行" ],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 兩個連續的雙引號是跳脫的雙引號；引號欄位可以是空字串
	 */
	public function test_連續兩個雙引號是跳脫的雙引號(): void {
		$rows = $this->parse( "id,feedback,note\r\n1,\"他說 \"\"好\"\"\",\"\"\r\n2,\"\"\"\",x\r\n" );

		$this->assert_rows(
			[
				[ 'id' => '1', 'feedback' => '他說 "好"', 'note' => '' ],
				[ 'id' => '2', 'feedback' => '"', 'note' => 'x' ],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 未加引號的格前後空白會被 trim；加引號的格，閉合引號後面的空白也允許（DEC-W2-11 只拒絕「接非分隔字元」）
	 */
	public function test_每格前後空白會被trim(): void {
		$rows = $this->parse( "id , status \r\n 500 , pass \r\n501,\"fail\" ,x\r\n" );

		$this->assert_rows(
			[
				[ 'id' => '500', 'status' => 'pass' ],
				[ 'id' => '501', 'status' => 'fail' ],
			],
			$rows
		);
	}

	// ========== 表頭正規化 ==========

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function header_normalization_provider(): array {
		return [
			'大小寫轉小寫'                 => [ 'Status', 'status' ],
			'全大寫'                       => [ 'SUBMISSION_ID', 'submission_id' ],
			'前後空白被 trim'              => [ '  Submission ID  ', 'submission_id' ],
			'半形空白換底線'               => [ 'Student Email', 'student_email' ],
			'連續空白合成一個底線'         => [ 'Student   Email', 'student_email' ],
			'全形空白換底線'               => [ "Student\u{3000}Email", 'student_email' ],
			'tab 換底線'                   => [ "Student\tEmail", 'student_email' ],
			'中文表頭保留，只換空白'       => [ '狀態(可改 pending/pass/fail)', '狀態(可改_pending/pass/fail)' ],
			'英文 msgid 表頭'              => [ 'Status (editable: pending/pass/fail)', 'status_(editable:_pending/pass/fail)' ],
			'中文括號與無空白的表頭不變'   => [ '講師回饋(可改)', '講師回饋(可改)' ],
			'BOM 開頭被去除'               => [ "\xEF\xBB\xBFsubmission_id", 'submission_id' ],
			'非 ASCII 大寫也轉小寫'        => [ 'ÀÉ', 'àé' ],
			'空字串'                       => [ '', '' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider header_normalization_provider
	 *
	 * DEC-W2-09：去 BOM → trim → mb_strtolower → 連續空白（含全形空白）換成 _
	 *
	 * @param string $header   原始表頭
	 * @param string $expected 正規化後的鍵
	 */
	public function test_表頭正規化( string $header, string $expected ): void {
		$actual = $this->call_static_or_fail( self::CSV_PARSER_CLASS, 'normalize_header', [ $header ] );

		$this->assertSame( $expected, $actual );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * parse() 產出的鍵與 normalize_header() 一致（匯入端查別名時用同一個函式正規化，才對得上）
	 */
	public function test_parse產出的鍵與normalize_header一致(): void {
		$header = '狀態(可改 pending/pass/fail)';
		$rows   = $this->parse( $header . "\r\npass\r\n" );

		$normalized = $this->call_static_or_fail( self::CSV_PARSER_CLASS, 'normalize_header', [ $header ] );
		$this->assert_rows( [ [ $normalized => 'pass' ] ], $rows );
	}

	// ========== 欄數不一致 / 重複表頭 / 全空列 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 比表頭多的格忽略；比表頭少的格視為空字串（鍵仍存在）
	 */
	public function test_多出的格忽略且不足的格視為空(): void {
		$rows = $this->parse( "a,b,c\r\n1,2,3,4,5\r\n6\r\n7,8\r\n" );

		$this->assert_rows(
			[
				[ 'a' => '1', 'b' => '2', 'c' => '3' ],
				[ 'a' => '6', 'b' => '', 'c' => '' ],
				[ 'a' => '7', 'b' => '8', 'c' => '' ],
			],
			$rows
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 表頭正規化後撞名時，後面的欄覆蓋前面的（Status 與 STATUS 都是 status → 取後者）
	 */
	public function test_正規化後表頭重複時後者覆蓋(): void {
		$rows = $this->parse( "Status,id,STATUS\r\nfirst,500,second\r\n" );

		$this->assert_rows( [ [ 'status' => 'second', 'id' => '500' ] ], $rows );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 全空的列（空行、",,"、只有空白與逗號）略過，不出現在結果裡；有資料的列前後順序不變
	 */
	public function test_全空列略過(): void {
		$rows = $this->parse( "id,status,note\r\n500,pass,a\r\n\r\n,,\r\n  , ,  \r\n501,fail,b\r\n\r\n" );

		$this->assert_rows(
			[
				[ 'id' => '500', 'status' => 'pass', 'note' => 'a' ],
				[ 'id' => '501', 'status' => 'fail', 'note' => 'b' ],
			],
			$rows
		);
	}

	// ========== 無法解析（DEC-W2-11：四種一律丟 \UnexpectedValueException） ==========

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function unparseable_provider(): array {
		return [
			// (a) 去 BOM 後不是合法 UTF-8
			'(a) Big5 位元組出現在資料列'            => [ "id,feedback\r\n500,\xb3\x71\xb9\x4c\r\n" ],
			'(a) Big5 位元組出現在表頭'              => [ "id,\xb3\x71\xb9\x4c\r\n500,x\r\n" ],
			'(a) UTF-8 BOM 之後才出現非法位元組'      => [ "\xEF\xBB\xBFid,feedback\r\n500,\xff\xfe\r\n" ],
			'(a) UTF-16LE（BOM FF FE）'             => [ "\xFF\xFE" . 'i' . "\0" . 'd' . "\0" ],
			// (b) 引號欄位到檔尾未閉合
			'(b) 引號在資料列開了沒關'                => [ "id,feedback\r\n500,\"pass\r\n501,fail\r\n" ],
			'(b) 引號在表頭開了沒關'                  => [ "id,\"feedback\r\n500,pass\r\n" ],
			'(b) 跳脫的雙引號之後仍未閉合'            => [ "id,feedback\r\n500,\"a\"\"b\r\n" ],
			// (c) 閉合引號後接的不是逗號 / 換行 / 空白
			'(c) 閉合引號後接英文字'                  => [ "id,feedback\r\n500,\"pass\"x\r\n" ],
			'(c) 閉合引號後接另一組引號文字'          => [ "id,feedback\r\n500,\"a\"b\"c\"\r\n" ],
			// (d) 未加引號的欄位中間出現引號
			'(d) 未加引號欄位中間有引號'              => [ "id,feedback\r\n500,pa\"ss\r\n" ],
			'(d) 未加引號欄位結尾有引號'              => [ "id,feedback\r\n500,pass\"\r\n" ],
		];
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider unparseable_provider
	 *
	 * 四種無法解析一律丟 \UnexpectedValueException（不回傳「盡量解析」的結果、不丟別種例外、不靜默吞到檔尾）
	 *
	 * @param string $raw CSV 原始內容
	 */
	public function test_無法解析的檔案一律丟出UnexpectedValueException( string $raw ): void {
		$this->assert_unparseable( $raw, '這份 CSV' );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, array<string, string>>}>
	 */
	public static function tricky_but_valid_provider(): array {
		return [
			'引號欄位內含雙引號與逗號'              => [
				"id,feedback\r\n500,\"a \"\"b\"\", c\"\r\n",
				[ [ 'id' => '500', 'feedback' => 'a "b", c' ] ],
			],
			'引號欄位在列尾'                        => [
				"id,feedback\r\n500,\"pass\"\r\n",
				[ [ 'id' => '500', 'feedback' => 'pass' ] ],
			],
			'引號欄位在檔尾且沒有換行'              => [
				"id,feedback\r\n500,\"pass\"",
				[ [ 'id' => '500', 'feedback' => 'pass' ] ],
			],
			'引號欄位在列首'                        => [
				"id,feedback\r\n\"500\",pass\r\n",
				[ [ 'id' => '500', 'feedback' => 'pass' ] ],
			],
			'含中文與 emoji 的合法 UTF-8'           => [
				"id,feedback\r\n500,寫得很好 😀\r\n",
				[ [ 'id' => '500', 'feedback' => '寫得很好 😀' ] ],
			],
			'欄位中間的單引號不是引號字元'          => [
				"id,feedback\r\n500,it's fine\r\n",
				[ [ 'id' => '500', 'feedback' => "it's fine" ] ],
			],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider tricky_but_valid_provider
	 *
	 * 反向守門：看起來刁鑽但合法的寫法不可被誤判成「無法解析」（嚴格不等於見到引號就丟例外）
	 *
	 * @param string                                $raw      CSV 原始內容
	 * @param array<int, array<string, string>> $expected 期望的解析結果
	 */
	public function test_刁鑽但合法的寫法不被誤判為無法解析( string $raw, array $expected ): void {
		$this->assert_rows( $expected, $this->parse( $raw ) );
	}
}
