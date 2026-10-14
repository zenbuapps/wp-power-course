<?php
/**
 * 匯入批改結果CSV 的 API 層（權限 / 檔案）整合測試（Issue #131 第二波 W2-R1）
 *
 * Feature: specs/features/assignment/匯入批改結果CSV.feature 前三條 Rule（權限、必須上傳、10MB 上限）
 * 依據：第二波計劃 §4 DEC-W2-02（驗證在 callback 內、permission 之後）、DEC-W2-13（上傳層檢查順序）、§7.3、§8；
 *       藍圖 ADJ-W2-14 / ADJ-W2-19；specs/api/api.yml（importAssignmentGradesCsv）
 *
 * ─── 本層「能驗什麼、不能驗什麼」────────────────────────────
 * 全部走 rest_get_server()->dispatch()（含 wp_set_current_user），permission_callback 才會真的被執行。
 * 檔案以 WP_REST_Request::set_file_params() 帶入，不碰 $_FILES 全域。
 * PHPUnit CLI 下 is_uploaded_file() 對任何路徑都是 false（R-W2-08），所以這裡只驗得到
 * 「沒檔案 / 大小 / PHP 上傳錯誤碼 / is_uploaded_file()」這幾道上傳層檢查；
 * 真正讀暫存檔、解析、批改（actor = 目前使用者）要靠 017 的真實 multipart E2E。
 *
 * ─── 防說謊（ADJ-W2-14）────────────────────────────────────
 * 「size = 10485760」那一條寫成正向斷言：400「請選擇要匯入的 CSV 檔案」。
 * 不可寫成「訊息不是檔案過大」——路由還不存在時 404 也滿足「不是檔案過大」，Red 階段會意外綠。
 * 同一條的 tmp_name 指向一個「真的會把 500 批改為 pass」的 CSV，並斷言 500 仍是 pending：
 * 守住「大小檢查通過後仍必須 is_uploaded_file()，不可讀取任意本機路徑」（路徑任意讀取是上傳端點的經典漏洞）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class ImportGradingCsvApiTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class ImportGradingCsvApiTest extends AssignmentWave2TestCase {

	/**
	 * 建立 Background（匯入批改結果CSV feature）：Admin/Alice/Bob、課程 100、章節 201、作業 400、
	 * 繳交 500 / 501 皆 pending、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_import_fixture();
	}

	/**
	 * 一份「若真的被處理就會把 500 批改為 pass」的 CSV（用來證明上傳層沒有越過 is_uploaded_file()）
	 *
	 * @return string
	 */
	private function grading_csv_for_500(): string {
		return $this->build_csv(
			[
				[ 'submission_id', '狀態(可改 pending/pass/fail)', '講師回饋(可改)' ],
				[ (string) $this->fx['sub_500'], 'pass', '寫得很好' ],
			]
		);
	}

	// ========== 前置（狀態）- 操作者必須為管理員 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員匯入時操作失敗
	 */
	public function test_非管理員匯入時操作失敗(): void {
		$response = $this->dispatch_with_file( $this->import_route(), null, $this->fx['alice'] );

		$this->assert_permission_denied( $response );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員
	 * 邊界：非管理員即使帶了「看起來合法」的檔案也一律 403，繳交完全不被動到
	 * （權限先於上傳層檢查，DEC-W2-02 / DEC-W2-13）
	 */
	public function test_非管理員帶檔案匯入時一律403且不處理(): void {
		$file     = $this->make_upload_file( $this->grading_csv_for_500() );
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['alice'] );

		$this->assert_permission_denied( $response );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * 邊界：只有 manage_woocommerce 的店長（shop_manager）不被權限擋下——到得了 callback，
	 * 沒附檔案時是 400「請選擇要匯入的 CSV 檔案」而不是 403
	 */
	public function test_只有manage_woocommerce的店長可呼叫匯入(): void {
		$manager_id = $this->factory()->user->create(
			[
				'user_login' => 'manager_imp_' . \uniqid(),
				'user_email' => 'manager_imp_' . \uniqid() . '@test.com',
				'role'       => 'shop_manager',
			]
		);
		$this->assertTrue( \user_can( $manager_id, 'manage_woocommerce' ), '前置條件不成立：shop_manager 應具 manage_woocommerce' );
		$this->assertFalse( \user_can( $manager_id, 'manage_options' ), '前置條件不成立：shop_manager 不應具 manage_options（否則測不到 manage_woocommerce 這條）' );

		$response = $this->dispatch_with_file( $this->import_route(), null, $manager_id );

		$this->assert_operation_failed_response( $response, '請選擇要匯入的 CSV 檔案', 400, 'rest_invalid_param' );
	}

	// ========== 前置（參數）- 必須上傳 CSV 檔案 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 必須上傳 CSV 檔案
	 * Example: 未附檔案時操作失敗
	 */
	public function test_未附檔案時操作失敗(): void {
		$response = $this->dispatch_with_file( $this->import_route(), null, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, '請選擇要匯入的 CSV 檔案', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 必須上傳 CSV 檔案
	 * 邊界：PHP 回報 UPLOAD_ERR_NO_FILE（表單送出了空的 file 欄位）與「沒有檔案」同一句訊息
	 */
	public function test_PHP回報沒有上傳檔案時同樣要求選擇檔案(): void {
		$file     = $this->make_upload_file(
			'',
			[
				'tmp_name' => '',
				'error'    => \UPLOAD_ERR_NO_FILE,
				'size'     => 0,
			]
		);
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, '請選擇要匯入的 CSV 檔案', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	// ========== 前置（參數）- 檔案大小不可超過 10MB ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 檔案大小不可超過 10MB
	 * Example: 檔案超過 10MB 時操作失敗
	 *
	 * tmp_name 指向一個很小的真實暫存檔，size 欄位宣稱 10485761 bytes：
	 * 大小檢查必須在 is_uploaded_file() 之前（DEC-W2-13），否則 PHPUnit 下會變成「請選擇要匯入的 CSV 檔案」。
	 */
	public function test_檔案超過10MB時操作失敗(): void {
		$file     = $this->make_upload_file( $this->grading_csv_for_500(), [ 'size' => self::IMPORT_MAX_BYTES + 1 ] );
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, '檔案過大（上限 10MB）', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 檔案大小不可超過 10MB
	 * 整個請求 body 超過 post_max_size 時 PHP 會把 $_FILES 整個丟掉（file 參數根本不存在），
	 * 此時要回「檔案過大」而不是「請選擇要匯入的 CSV 檔案」（DEC-W2-13）。
	 * 以 $_SERVER['CONTENT_LENGTH'] 模擬（API 層讀同一個值判斷），用完還原。
	 */
	public function test_整個請求超過post_max_size時回覆檔案過大(): void {
		$post_max = (int) \wp_convert_hr_to_bytes( (string) \ini_get( 'post_max_size' ) );
		$this->assertGreaterThan( 0, $post_max, '前置條件不成立：環境的 post_max_size 應大於 0 才能模擬超過' );

		$original = $_SERVER['CONTENT_LENGTH'] ?? null;
		try {
			$_SERVER['CONTENT_LENGTH'] = (string) ( $post_max + 1 );
			$response                  = $this->dispatch_with_file( $this->import_route(), null, $this->fx['admin'] );
		} finally {
			if ( null === $original ) {
				unset( $_SERVER['CONTENT_LENGTH'] );
			} else {
				$_SERVER['CONTENT_LENGTH'] = $original;
			}
		}

		$this->assert_operation_failed_response( $response, '檔案過大（上限 10MB）', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function php_upload_error_provider(): array {
		return [
			'UPLOAD_ERR_INI_SIZE（超過 upload_max_filesize）'   => [ \UPLOAD_ERR_INI_SIZE, '檔案過大（上限 10MB）' ],
			'UPLOAD_ERR_FORM_SIZE（超過 MAX_FILE_SIZE）'        => [ \UPLOAD_ERR_FORM_SIZE, '檔案過大（上限 10MB）' ],
		];
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider php_upload_error_provider
	 *
	 * Rule: 前置（參數）- 檔案大小不可超過 10MB
	 * 主機的 PHP 上傳上限先擋下檔案時（error = INI_SIZE / FORM_SIZE，此時 tmp_name 為空、size 為 0），
	 * 回「檔案過大」而不是「請選擇要匯入的 CSV 檔案」（DEC-W2-13）。
	 *
	 * @param int    $php_error PHP 上傳錯誤碼
	 * @param string $message   期望訊息
	 */
	public function test_PHP上傳上限擋下檔案時回覆檔案過大( int $php_error, string $message ): void {
		$file     = $this->make_upload_file(
			'',
			[
				'tmp_name' => '',
				'error'    => $php_error,
				'size'     => 0,
			]
		);
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, $message, 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- 檔案大小不可超過 10MB（邊界：剛好 10485760 bytes 不算過大）
	 *
	 * 正向斷言（ADJ-W2-14）：剛好 10MB 通過大小檢查，接著 is_uploaded_file() 在 PHPUnit 下必為 false，
	 * 所以是 400「請選擇要匯入的 CSV 檔案」。500 仍是 pending，證明沒有越過 is_uploaded_file() 去讀 tmp_name。
	 */
	public function test_檔案剛好10MB時通過大小檢查但非上傳檔仍被擋下(): void {
		$file     = $this->make_upload_file( $this->grading_csv_for_500(), [ 'size' => self::IMPORT_MAX_BYTES ] );
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, '請選擇要匯入的 CSV 檔案', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（參數）- 必須上傳 CSV 檔案
	 * 邊界：大小合法、error = UPLOAD_ERR_OK，但 tmp_name 不是 PHP 真的收到的上傳暫存檔
	 * （任意本機路徑）時一律拒絕，不得讀取內容（is_uploaded_file()）
	 */
	public function test_tmp_name不是上傳暫存檔時一律拒絕(): void {
		$file     = $this->make_upload_file( $this->grading_csv_for_500() );
		$response = $this->dispatch_with_file( $this->import_route(), $file, $this->fx['admin'] );

		$this->assert_operation_failed_response( $response, '請選擇要匯入的 CSV 檔案', 400, 'rest_invalid_param' );
		$this->assert_import_untouched();
	}
}
