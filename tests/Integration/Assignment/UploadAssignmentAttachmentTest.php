<?php
/**
 * 上傳作業附件 整合測試（Issue #131 第一波 P3）
 *
 * Feature: specs/features/assignment/上傳作業附件.feature
 * 依據：計劃 §9 P3、§6.2、§4 DEC-09/DEC-16/DEC-17、§12 R-04/R-13/R-14、
 *       specs/api/api.yml（POST /my/assignment-attachments）、specs/entity/erm.dbml（pc_assignment_attachments）
 *
 * ─── 本檔「測什麼、不測什麼」─────────────────────────────────────
 * 依計劃明文指示，本檔分兩層：
 *   1. 「必須登入」與「未附檔案」兩條 Rule 屬於 API 層（Core\MyApi）的行為（permission_callback /
 *      是否有 $_FILES），走 dispatch() 真實 REST 派發。
 *   2. 其餘（大小、MIME 白名單、寫入紀錄、實體檔案、回應形狀）直接測
 *      `Service\Attachment::store( user_id, tmp_path, original_name, mime_type, file_size )`，
 *      不經 REST——因為 PHPUnit CLI 下 `is_uploaded_file()` 永遠是 false（R-13），
 *      經 REST 派發真實 multipart 檔案在這裡測不出東西，只會全部卡在「請選擇要上傳的檔案」。
 *      50MB 邊界因此也不需要真的產生 50MB 檔案：Service 信任呼叫端傳入的 $file_size 參數
 *      （而非自行 filesize() 暫存檔），所以用一個很小的暫存檔 + 顯式 $file_size 即可觸發邊界。
 *   3. DEC-17 的 PHP 上傳錯誤碼轉換（UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE → 「檔案過大」）
 *      是 API 層一個獨立的純函式，不依賴 $_FILES / is_uploaded_file()，因此可直接呼叫測試，
 *      不必經過 REST 派發模擬 PHP 真的把檔案擋下來的情境。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;

/**
 * Class UploadAssignmentAttachmentTest
 *
 * @group assignment
 * @group issue-131
 */
class UploadAssignmentAttachmentTest extends AssignmentTestCase {

	/** Service\Attachment 類別 FQCN（計劃 §5.1，供動態呼叫 store() 用） */
	private const ATTACHMENT_SERVICE_CLASS = 'J7\PowerCourse\Resources\Assignment\Service\Attachment';

	/** Core\MyApi 類別 FQCN（計劃 §5.1，供 DEC-17 轉換函式測試用） */
	private const MY_API_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\MyApi';

	/** @var int Alice（subscriber） */
	private int $alice_id;

	/** @var int Bob（subscriber，未加入任何課程） */
	private int $bob_id;

	/** @var array<int, string> 本測試建立的暫存來源檔案（tmp_path），tear_down 刪除 */
	private array $created_tmp_files = [];

	/**
	 * 建立 Background：Alice、Bob（皆未加入任何課程；上傳不檢查課程觀看權）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_uaa_' . \uniqid(),
				'user_email' => 'alice_uaa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_uaa_' . \uniqid(),
				'user_email' => 'bob_uaa_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
	}

	/**
	 * 刪除本測試建立的暫存來源檔案
	 */
	public function tear_down(): void {
		foreach ( $this->created_tmp_files as $file ) {
			if ( \file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				\unlink( $file );
			}
		}
		$this->created_tmp_files = [];

		parent::tear_down();
	}

	/**
	 * 建立一個小型暫存來源檔案（供 Service::store() 的 $tmp_path 參數使用）
	 * 內容大小與 $file_size 參數無關：Service 信任呼叫端傳入的 file_size，不對 tmp_path 做 filesize()
	 *
	 * @param string $content 檔案內容，預設一小段字串即可
	 * @return string 暫存檔案絕對路徑
	 */
	private function make_tmp_source_file( string $content = 'power-course upload test' ): string {
		$path = (string) \wp_tempnam( 'pc-assignment-upload-src-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $path, $content );
		$this->created_tmp_files[] = $path;

		return $path;
	}

	/**
	 * 呼叫 Service\Attachment::store()，成功回傳陣列、失敗例外由呼叫端 catch
	 *
	 * @param int    $user_id 上傳者 user ID
	 * @param string $tmp_path 暫存來源檔案路徑
	 * @param string $original_name 原始檔名
	 * @param string $mime_type MIME 類型
	 * @param int    $file_size 檔案大小（bytes，信任呼叫端提供）
	 * @return array<string, mixed>
	 */
	private function call_store( int $user_id, string $tmp_path, string $original_name, string $mime_type, int $file_size ): array {
		if ( ! \class_exists( self::ATTACHMENT_SERVICE_CLASS ) ) {
			$this->fail( self::ATTACHMENT_SERVICE_CLASS . ' 尚未實作' );
		}

		/** @var array<string, mixed> $result */
		$result = \call_user_func(
			[ self::ATTACHMENT_SERVICE_CLASS, 'store' ],
			$user_id,
			$tmp_path,
			$original_name,
			$mime_type,
			$file_size
		);

		return $result;
	}

	/**
	 * 斷言 $this->lastError 是 AssignmentException 且 status / code / message 符合期望
	 *
	 * @param string $message 期望的 zh_TW 錯誤訊息（feature 文字，不可用 __() 包裝期望值）
	 * @param int    $status 期望的 HTTP 狀態碼
	 * @param string $code 期望的錯誤代碼
	 */
	private function assert_store_failed( string $message, int $status, string $code ): void {
		$this->assertInstanceOf(
			AssignmentException::class,
			$this->lastError,
			\sprintf( '預期拋出 AssignmentException，實際：%s', $this->lastError ? \get_class( $this->lastError ) . '：' . $this->lastError->getMessage() : 'null（未拋出例外）' )
		);

		/** @var AssignmentException $exception */
		$exception = $this->lastError;

		$this->assertSame( $status, $exception->get_status(), '例外的 HTTP status 不符' );
		$this->assertSame( $code, $exception->get_error_code(), '例外的 error code 不符' );
		$this->assertSame( $message, $exception->getMessage(), '例外訊息不符' );
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客上傳附件時操作失敗
	 */
	public function test_未登入訪客上傳附件時操作失敗(): void {
		\wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', '/power-course/my/assignment-attachments' );

		$this->assert_not_logged_in( $response );
	}

	// ========== 前置（參數）- 必須提供檔案 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 必須提供檔案
	 * Example: 未附檔案時操作失敗
	 */
	public function test_未附檔案時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'POST', '/power-course/my/assignment-attachments' );

		$this->assert_operation_failed_response( $response, '請選擇要上傳的檔案', 400, 'rest_invalid_param' );
	}

	// ========== 前置（參數）- 檔案大小不可超過 50MB ==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- 檔案大小不可超過 50MB
	 * Example: 檔案超過 50MB 一個位元組時操作失敗
	 * 50MB 邊界用 file_size 參數判斷，不必真的產生 50MB 檔（Service 信任呼叫端傳入的 file_size）
	 */
	public function test_檔案超過50MB一個位元組時操作失敗(): void {
		$tmp_path = $this->make_tmp_source_file();

		try {
			$this->call_store( $this->alice_id, $tmp_path, '期末報告.pdf', 'application/pdf', 52428801 );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_store_failed( '檔案過大（上限 50MB）', 400, 'rest_invalid_param' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 後置（狀態）- 白名單內且不超過 50MB 的檔案皆可上傳（邊界：剛好 50MB）
	 * Example: 未加入任何課程的 Bob 上傳 期末報告.pdf（52428800 bytes，剛好 50MB）
	 */
	public function test_檔案剛好50MB時上傳成功(): void {
		$tmp_path = $this->make_tmp_source_file();

		$result = null;
		try {
			$result           = $this->call_store( $this->bob_id, $tmp_path, '期末報告.pdf', 'application/pdf', 52428800 );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );
	}

	// ========== 前置（參數）- 檔案類型必須為白名單 ==========

	/**
	 * @test
	 * @group security
	 * @dataProvider provide_unsupported_mime_types
	 *
	 * Rule: 前置（參數）- 檔案類型必須為圖片（jpeg / png / webp / gif）、PDF、Word、Excel、
	 *       PowerPoint、純文字、CSV 或 ZIP
	 * Scenario Outline: 上傳 <mime_type> 時操作失敗
	 *
	 * @param string $original_name 原始檔名
	 * @param string $mime_type MIME 類型
	 */
	public function test_上傳不支援的檔案類型時操作失敗( string $original_name, string $mime_type ): void {
		$tmp_path = $this->make_tmp_source_file();

		try {
			$this->call_store( $this->alice_id, $tmp_path, $original_name, $mime_type, 1024 );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_store_failed( \sprintf( '不支援的檔案類型：%s', $mime_type ), 400, 'rest_invalid_param' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_unsupported_mime_types(): array {
		return [
			'示範影片.mp4' => [ '示範影片.mp4', 'video/mp4' ],
			'setup.exe'    => [ 'setup.exe', 'application/x-msdownload' ],
		];
	}

	// ========== 後置（狀態）- 白名單內且不超過 50MB 的檔案皆可上傳 ==========

	/**
	 * @test
	 * @group happy
	 * @dataProvider provide_allowed_uploads
	 *
	 * Rule: 後置（狀態）- 白名單內且不超過 50MB 的檔案皆可上傳，不需指定作業也不檢查課程觀看權
	 * Scenario Outline: 未加入任何課程的 Bob 上傳 <original_name>
	 *
	 * @param string $original_name 原始檔名
	 * @param string $mime_type MIME 類型
	 * @param int    $file_size 檔案大小
	 */
	public function test_未加入任何課程的Bob上傳合法檔案時操作成功( string $original_name, string $mime_type, int $file_size ): void {
		$tmp_path = $this->make_tmp_source_file();

		$result = null;
		try {
			$result           = $this->call_store( $this->bob_id, $tmp_path, $original_name, $mime_type, $file_size );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assertIsArray( $result );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int}>
	 */
	public static function provide_allowed_uploads(): array {
		return [
			'作業截圖.png' => [ '作業截圖.png', 'image/png', 20480 ],
			'成績表.xlsx'  => [ '成績表.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 8192 ],
			'程式碼.zip'   => [ '程式碼.zip', 'application/zip', 1024 ],
		];
	}

	// ========== 後置（狀態）- 建立附件紀錄並把檔案以隨機檔名存入受保護目錄 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 建立附件紀錄並把檔案以隨機檔名存入受保護目錄
	 * Example: 上傳圖片後寫入附件紀錄
	 */
	public function test_上傳圖片後寫入附件紀錄(): void {
		$this->use_temp_storage_dir();
		$tmp_path = $this->make_tmp_source_file();

		$result = $this->call_store( $this->alice_id, $tmp_path, '作業截圖.png', 'image/png', 20480 );

		$this->assertArrayHasKey( 'attachment_id', $result, '回應應包含 attachment_id' );
		$attachment_id = (int) $result['attachment_id'];

		$row = $this->get_attachment_row( $attachment_id );
		$this->assertIsArray( $row, '附件紀錄應已寫入 pc_assignment_attachments' );
		$this->assertSame( $this->alice_id, (int) $row['user_id'], 'user_id 不符' );
		$this->assertSame( '作業截圖.png', $row['original_name'], 'original_name 不符' );
		$this->assertSame( 'image/png', $row['mime_type'], 'mime_type 不符' );
		$this->assertSame( 20480, (int) $row['file_size'], 'file_size 不符' );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（狀態）- 建立附件紀錄並把檔案以隨機檔名存入受保護目錄
	 * Example: 新建作業附件的實體檔案應存放於受保護目錄且檔名不等於原始檔名
	 * 檔名應為 64 位 hex 字串、無副檔名（DEC-09）；目錄需有 .htaccess 與 index.php（Nginx 無效但仍是最後一道防線，R-04）
	 */
	public function test_實體檔案存放於受保護目錄且檔名為隨機64位hex(): void {
		$storage_dir = $this->use_temp_storage_dir();
		$tmp_path    = $this->make_tmp_source_file();

		$result        = $this->call_store( $this->alice_id, $tmp_path, '作業截圖.png', 'image/png', 20480 );
		$attachment_id = (int) $result['attachment_id'];

		$row = $this->get_attachment_row( $attachment_id );
		$this->assertIsArray( $row, '附件紀錄應已寫入' );

		$stored_path = (string) $row['stored_path'];
		$this->assertNotSame( '', $stored_path, 'stored_path 不應為空' );

		// stored_path 可能是絕對路徑，也可能是相對於受保護目錄的路徑（erm.dbml Note）；兩者皆解析為受保護目錄內的絕對路徑
		$absolute_path = ( '/' === ( $stored_path[0] ?? '' ) || 1 === \preg_match( '#^[A-Za-z]:[\\\\/]#', $stored_path ) )
			? $stored_path
			: \rtrim( $storage_dir, '/\\' ) . '/' . \ltrim( $stored_path, '/\\' );

		$this->assertFileExists( $absolute_path, "實體檔案應存在：{$absolute_path}" );

		$filename = \pathinfo( $absolute_path, PATHINFO_BASENAME );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $filename, "檔名應為 64 位小寫 hex 字串，實際為：{$filename}" );
		$this->assertNotSame( '作業截圖.png', $filename, '檔名不應等於原始檔名' );
		$this->assertStringNotContainsString( '.', $filename, '檔名不應帶副檔名' );

		$this->assertFileExists( \rtrim( $storage_dir, '/\\' ) . '/.htaccess', '受保護目錄應有 .htaccess' );
		$this->assertFileExists( \rtrim( $storage_dir, '/\\' ) . '/index.php', '受保護目錄應有 index.php' );
	}

	// ========== 後置（回應）- 回傳附件 id、檔名、MIME 類型與大小，不回傳檔案 URL ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（回應）- 回傳附件 id、檔名、MIME 類型與大小，不回傳檔案 URL
	 * Example: 上傳成功後回應不含 url
	 * DEC-09：API 永不回傳 stored_path 或 URL
	 */
	public function test_上傳成功後回應不含url欄位(): void {
		$tmp_path = $this->make_tmp_source_file();

		$result = $this->call_store( $this->alice_id, $tmp_path, '作業截圖.png', 'image/png', 20480 );

		$this->assertSame( '作業截圖.png', $result['file_name'] ?? null, 'file_name 不符' );
		$this->assertSame( 'image/png', $result['mime_type'] ?? null, 'mime_type 不符' );
		$this->assertSame( 20480, $result['file_size'] ?? null, 'file_size 不符' );
		$this->assertArrayNotHasKey( 'url', $result, '回應不應包含 url 欄位（DEC-09）' );
		$this->assertArrayNotHasKey( 'stored_path', $result, '回應不應包含 stored_path 欄位（DEC-09）' );
	}

	// ========== DEC-17：API 層的 PHP 上傳錯誤碼轉換函式（單獨測） ==========

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_php_ini_upload_errors
	 *
	 * DEC-17：PHP upload_max_filesize / post_max_size 擋下檔案時
	 * （UPLOAD_ERR_INI_SIZE / UPLOAD_ERR_FORM_SIZE）應對應「檔案過大（上限 50MB）」，
	 * 不是「請選擇要上傳的檔案」。此轉換函式獨立於 $_FILES / is_uploaded_file()，可直接呼叫測試。
	 *
	 * 介面契約（test-creator 決定，計劃未指定確切名稱）：
	 * J7\PowerCourse\Resources\Assignment\Core\MyApi::translate_upload_error( int $php_upload_error ): string
	 *
	 * @param int $php_upload_error PHP 上傳錯誤碼常數
	 */
	public function test_PHP上傳錯誤碼轉換為檔案過大訊息( int $php_upload_error ): void {
		if ( ! \class_exists( self::MY_API_CLASS ) ) {
			$this->fail( self::MY_API_CLASS . ' 尚未實作' );
		}
		if ( ! \method_exists( self::MY_API_CLASS, 'translate_upload_error' ) ) {
			$this->fail( self::MY_API_CLASS . '::translate_upload_error() 尚未實作' );
		}

		/** @var string $message */
		$message = \call_user_func( [ self::MY_API_CLASS, 'translate_upload_error' ], $php_upload_error );

		$this->assertSame( '檔案過大（上限 50MB）', $message );
	}

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function provide_php_ini_upload_errors(): array {
		return [
			'UPLOAD_ERR_INI_SIZE'  => [ \UPLOAD_ERR_INI_SIZE ],
			'UPLOAD_ERR_FORM_SIZE' => [ \UPLOAD_ERR_FORM_SIZE ],
		];
	}
}
