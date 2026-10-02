<?php
/**
 * 從 REST 請求取出單一上傳檔（Issue #131；學員附件上傳與匯入批改結果 CSV 共用）
 *
 * 檢查順序（第一波 DEC-17、第二波 DEC-W2-13）：
 *   1. 沒有 file 欄位：整個 body 超過 post_max_size 時 PHP 會清空 $_FILES → 檔案過大；否則 → 請選擇檔案
 *   2. PHP 上傳錯誤碼：INI_SIZE / FORM_SIZE → 檔案過大；NO_FILE → 請選擇檔案；
 *      NO_TMP_DIR / CANT_WRITE / EXTENSION 是主機設定問題 → 500 並記 log；其餘 → 400 上傳失敗
 *   3. 大小上限（呼叫端有給才檢查）：放在 is_uploaded_file() 之前，PHPUnit 才驗得到超過上限
 *   4. is_uploaded_file()：不是 PHP 真的收到的上傳暫存檔一律視為沒有檔案，不讀任意本機路徑（R-13）
 *
 * 使用者可見訊息由呼叫端提供（上限字樣與用語各自不同），本類別只決定「哪一種情況回哪一句」。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

use J7\PowerCourse\Plugin;

/**
 * Class UploadedFile
 */
final class UploadedFile {

	/** 上傳檔在 multipart 中的欄位名稱 */
	private const FIELD = 'file';

	/** 主機上傳設定問題的 PHP 錯誤碼：暫存目錄不存在 / 無法寫入 / 被擴充中止（回 500 並記 log） */
	private const SERVER_ERRORS = [ \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION ];

	/**
	 * 從請求取出單一上傳檔（field 名 file）並完成上傳層檢查
	 *
	 * @param \WP_REST_Request $request     請求物件
	 * @param array            $messages    使用者可見訊息：too_large 檔案過大 / no_file 請選擇檔案 / failed 其他上傳失敗
	 * @param string           $log_message 主機上傳設定問題（500）時寫入 log 的訊息
	 * @param int|null         $max_bytes   大小上限（bytes，剛好等於上限不算過大）；null 代表這一層不檢查大小
	 * @phpstan-param array{too_large: string, no_file: string, failed: string} $messages
	 * @return array{tmp_name: string, name: string, type: string, size: int} name 已 wp_basename()
	 * @throws AssignmentException 未附檔案 / 檔案過大 / 上傳錯誤（400）；主機上傳設定問題（500）。
	 */
	public static function from_request( \WP_REST_Request $request, array $messages, string $log_message, ?int $max_bytes = null ): array {
		$files = $request->get_file_params();
		$file  = \array_key_exists( self::FIELD, $files ) ? $files[ self::FIELD ] : null;

		if ( ! \is_array( $file ) || ! isset( $file['tmp_name'] ) || ! \is_string( $file['tmp_name'] ) ) {
			throw AssignmentException::invalid_param( self::is_post_max_size_exceeded() ? $messages['too_large'] : $messages['no_file'] );
		}

		self::assert_no_php_error( $file, $messages, $log_message );

		$size = isset( $file['size'] ) && \is_numeric( $file['size'] ) ? (int) $file['size'] : 0;
		if ( null !== $max_bytes && $size > $max_bytes ) {
			throw AssignmentException::invalid_param( $messages['too_large'] );
		}

		if ( '' === $file['tmp_name'] || ! \is_uploaded_file( $file['tmp_name'] ) ) {
			throw AssignmentException::invalid_param( $messages['no_file'] );
		}

		return [
			'tmp_name' => $file['tmp_name'],
			'name'     => isset( $file['name'] ) && \is_string( $file['name'] ) ? \wp_basename( $file['name'] ) : '',
			'type'     => isset( $file['type'] ) && \is_string( $file['type'] ) ? $file['type'] : '',
			'size'     => $size,
		];
	}

	/**
	 * PHP 上傳錯誤碼 → 使用者可見訊息
	 *
	 * 主機的 upload_max_filesize / MAX_FILE_SIZE 擋下檔案時回「檔案過大」，不回「請選擇檔案」
	 * （主機常見上限 2MB–8MB，否則訊息會誤導使用者，DEC-17）。
	 *
	 * @param int   $php_upload_error PHP 上傳錯誤碼（UPLOAD_ERR_*）
	 * @param array $messages         使用者可見訊息，同 from_request()
	 * @phpstan-param array{too_large: string, no_file: string, failed: string} $messages
	 * @return string
	 */
	public static function translate_error( int $php_upload_error, array $messages ): string {
		return match ( $php_upload_error ) {
			\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $messages['too_large'],
			\UPLOAD_ERR_NO_FILE => $messages['no_file'],
			default => $messages['failed'],
		};
	}

	/**
	 * PHP 回報上傳錯誤時丟出對應的例外（沒有 error 欄位視同 NO_FILE）
	 *
	 * @param array<mixed> $file        $_FILES 的單一檔案項目
	 * @param array        $messages    使用者可見訊息，同 from_request()
	 * @param string       $log_message 主機上傳設定問題（500）時寫入 log 的訊息
	 * @phpstan-param array{too_large: string, no_file: string, failed: string} $messages
	 * @return void
	 * @throws AssignmentException 上傳錯誤（400）；主機上傳設定問題（500）。
	 */
	private static function assert_no_php_error( array $file, array $messages, string $log_message ): void {
		$error = isset( $file['error'] ) && \is_numeric( $file['error'] ) ? (int) $file['error'] : \UPLOAD_ERR_NO_FILE;
		if ( \UPLOAD_ERR_OK === $error ) {
			return;
		}

		$message = self::translate_error( $error, $messages );

		if ( \in_array( $error, self::SERVER_ERRORS, true ) ) {
			Plugin::logger( $log_message, 'error', [ 'php_upload_error' => $error ] );
			throw AssignmentException::internal( $message );
		}

		throw AssignmentException::invalid_param( $message );
	}

	/**
	 * 請求 body 是否超過 post_max_size（此時 PHP 會清空 $_POST / $_FILES）
	 *
	 * @return bool
	 */
	private static function is_post_max_size_exceeded(): bool {
		$raw_length     = $_SERVER['CONTENT_LENGTH'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$content_length = \is_numeric( $raw_length ) ? (int) $raw_length : 0;
		$post_max_size  = \wp_convert_hr_to_bytes( (string) \ini_get( 'post_max_size' ) );

		return $post_max_size > 0 && $content_length > $post_max_size;
	}
}
