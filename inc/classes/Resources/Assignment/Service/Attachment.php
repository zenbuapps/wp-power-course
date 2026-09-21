<?php
/**
 * 作業附件 Service（Issue #131）
 *
 * 對應 specs/features/assignment/上傳作業附件.feature、下載作業附件.feature：
 *   - store()：大小 ≤ 50MB → 副檔名白名單（DEC-16）→ 搬進受保護目錄（DEC-09）→ 寫入附件紀錄；
 *     DB 寫入失敗時刪除已搬的檔案（計劃 §7）。不指定作業、不檢查課程觀看權。
 *   - verify_uploaded_content()：真實上傳檔的 finfo 內容交叉比對（DEC-16），由 API 層在 store() 前呼叫
 *   - get_downloadable()：附件紀錄與實體檔案存在 → 管理員：出現在任一繳交；學員：上傳者本人
 *
 * 介面收 (tmp_path, original_name, mime_type, file_size)，不碰 $_FILES / is_uploaded_file()（API 層負責，R-13），
 * 且信任呼叫端傳入的 file_size（PHP 上傳時由 PHP 計算，非瀏覽器提供）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Attachment as AttachmentModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;
use J7\PowerCourse\Resources\Assignment\Utils\Storage;

/**
 * Class Attachment
 */
final class Attachment {

	/** 檔案大小上限（bytes，50MB） */
	public const MAX_FILE_SIZE = 52428800;

	/** 原始檔名長度上限（pc_assignment_attachments.original_name varchar(255)） */
	private const MAX_NAME_LENGTH = 255;

	/**
	 * 副檔名 → 標準 MIME 白名單（wp_check_filetype 格式；api.yml / erm.dbml 的 MIME 白名單）
	 * application/x-zip-compressed 是瀏覽器對 .zip 的別名，DB 一律存標準 MIME application/zip
	 */
	public const ALLOWED_MIMES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
		'pdf'          => 'application/pdf',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'          => 'application/vnd.ms-excel',
		'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'ppt'          => 'application/vnd.ms-powerpoint',
		'pptx'         => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		'txt'          => 'text/plain',
		'csv'          => 'text/csv',
		'zip'          => 'application/zip',
	];

	/**
	 * 這些是 finfo 常把二進位檔判成的籠統類型，對 application/* 一律放行（同 wp_check_filetype_and_ext 的處理）
	 */
	private const NONSPECIFIC_REAL_MIMES = [
		'application/octet-stream',
		'application/encrypted',
		'application/CDFV2-encrypted',
		'application/CDFV2',
		'application/x-ole-storage',
		'application/vnd.ms-office',
		'application/zip',
		'application/x-zip-compressed',
	];

	/**
	 * 存入附件：驗證 → 搬進受保護目錄 → 寫入紀錄
	 *
	 * @param int    $user_id       上傳者 user ID
	 * @param string $tmp_path      暫存檔絕對路徑
	 * @param string $original_name 原始檔名
	 * @param string $mime_type     呼叫端提供的 MIME（僅用於錯誤訊息；實際類型依副檔名判定）
	 * @param int    $file_size     檔案大小（bytes）
	 * @return array{attachment_id: int, file_name: string, mime_type: string, file_size: int}
	 * @throws AssignmentException 驗證失敗（400）或儲存失敗（500）時。
	 */
	public static function store( int $user_id, string $tmp_path, string $original_name, string $mime_type, int $file_size ): array {
		$standard_mime = self::validate( $original_name, $mime_type, $file_size );
		$file_name     = self::normalize_name( $original_name );

		try {
			$stored_path = Storage::store_file( $tmp_path );
		} catch ( \RuntimeException $e ) {
			Plugin::logger( 'Assignment attachment storage failed', 'error', [ 'error' => $e->getMessage() ] );
			throw AssignmentException::internal( \__( 'Failed to upload file', 'power-course' ) );
		}

		try {
			$attachment_id = AttachmentRepository::insert(
				$user_id,
				$file_name,
				$stored_path,
				$standard_mime,
				$file_size,
				(string) \wp_date( 'Y-m-d H:i:s', Clock::now() )
			);
		} catch ( \RuntimeException $e ) {
			// 檔案已搬、紀錄沒寫進去 → 刪掉孤兒檔案後回錯（計劃 §7）
			Storage::delete( $stored_path );
			Plugin::logger( 'Assignment attachment insert failed', 'error', [ 'error' => $e->getMessage() ] );
			throw AssignmentException::internal( \__( 'Failed to upload file', 'power-course' ) );
		}

		return ( new AttachmentModel( $attachment_id, $user_id, $file_name, $stored_path, $standard_mime, $file_size, '' ) )->to_meta_array();
	}

	/**
	 * 真實上傳檔的內容交叉比對（DEC-16）：副檔名推得的標準 MIME 必須與 finfo 偵測結果相容
	 *
	 * 只在 API 層處理真實 multipart 上傳時呼叫；store() 本身不做內容偵測。
	 * 主機沒有 fileinfo 擴充或偵測不出類型時放行（以副檔名白名單為準）。
	 *
	 * @param string $tmp_path      暫存檔絕對路徑
	 * @param string $original_name 原始檔名
	 * @param string $mime_type     瀏覽器提供的 MIME（僅用於錯誤訊息）
	 * @param int    $file_size     檔案大小（bytes）
	 * @return void
	 * @throws AssignmentException 驗證失敗（400）時。
	 */
	public static function verify_uploaded_content( string $tmp_path, string $original_name, string $mime_type, int $file_size ): void {
		$standard_mime = self::validate( $original_name, $mime_type, $file_size );

		if ( ! \function_exists( 'finfo_open' ) ) {
			return;
		}

		$finfo = \finfo_open( \FILEINFO_MIME_TYPE );
		if ( false === $finfo ) {
			return;
		}
		$real_mime = \finfo_file( $finfo, $tmp_path );

		if ( ! \is_string( $real_mime ) || '' === $real_mime ) {
			return;
		}

		if ( ! self::is_compatible_real_mime( $standard_mime, $real_mime ) ) {
			throw AssignmentException::invalid_param( self::unsupported_type_message( $original_name, $mime_type ) );
		}
	}

	/**
	 * 取得可下載的附件與實體檔案路徑（驗權）
	 *
	 * 順序（計劃 §6.2）：紀錄存在 → 實體檔案存在 → 角色判定
	 *   - 管理員：附件必須出現在任一繳交，否則 404「找不到對應的作業附件」
	 *   - 學員：必須是上傳者本人，否則 403「無權存取此附件」
	 *
	 * @param int  $attachment_id 附件 ID
	 * @param int  $user_id       目前使用者 ID
	 * @param bool $is_admin      目前使用者是否為管理員（manage_options || manage_woocommerce）
	 * @return array{0: AttachmentModel, 1: string} [附件, 實體檔案絕對路徑]
	 * @throws AssignmentException 不存在（404）或無權存取（403）時。
	 */
	public static function get_downloadable( int $attachment_id, int $user_id, bool $is_admin ): array {
		$attachment = AttachmentRepository::find( $attachment_id );
		$path       = null !== $attachment ? Storage::resolve_path( $attachment->stored_path ) : null;

		if ( null === $attachment || null === $path ) {
			throw AssignmentException::not_found( \__( 'Attachment not found', 'power-course' ) );
		}

		if ( $is_admin ) {
			if ( ! SubmissionRepository::exists_with_attachment( $attachment->id ) ) {
				throw AssignmentException::not_found( \__( 'No matching assignment attachment found', 'power-course' ) );
			}
		} elseif ( $attachment->user_id !== $user_id ) {
			throw AssignmentException::forbidden( \__( 'You do not have permission to access this attachment', 'power-course' ) );
		}

		return [ $attachment, $path ];
	}

	/**
	 * 大小與副檔名白名單驗證（DEC-16：瀏覽器 MIME 不可信，依副檔名判定）
	 *
	 * @param string $original_name 原始檔名
	 * @param string $mime_type     呼叫端提供的 MIME（僅用於錯誤訊息）
	 * @param int    $file_size     檔案大小（bytes）
	 * @return string 副檔名對應的標準 MIME
	 * @throws AssignmentException 超過 50MB 或類型不在白名單時（400）。
	 */
	private static function validate( string $original_name, string $mime_type, int $file_size ): string {
		if ( $file_size > self::MAX_FILE_SIZE ) {
			throw AssignmentException::invalid_param( \__( 'File too large (max 50MB)', 'power-course' ) );
		}

		$filetype = \wp_check_filetype( $original_name, self::ALLOWED_MIMES );
		if ( ! \is_string( $filetype['type'] ) || '' === $filetype['type'] ) {
			throw AssignmentException::invalid_param( self::unsupported_type_message( $original_name, $mime_type ) );
		}

		return $filetype['type'];
	}

	/**
	 * 判斷 finfo 偵測到的真實 MIME 是否與副檔名推得的標準 MIME 相容
	 *
	 * @param string $standard_mime 副檔名推得的標準 MIME
	 * @param string $real_mime     finfo 偵測結果
	 * @return bool
	 */
	private static function is_compatible_real_mime( string $standard_mime, string $real_mime ): bool {
		// finfo 對 Google Docs 產生的 docx / xlsx 可能回傳重複兩次的字串（WP core #57898）
		if ( $real_mime === $standard_mime || \str_contains( $real_mime, $standard_mime ) ) {
			return true;
		}

		// 圖片：只要真實內容是白名單內的圖片即可（截圖工具常把 png 存成 .jpg）
		if ( \str_starts_with( $standard_mime, 'image/' ) ) {
			return \in_array( $real_mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true );
		}

		// 純文字 / CSV：finfo 對文字內容的判定不穩定（text/plain、text/csv、application/csv、text/x-*）
		if ( \in_array( $standard_mime, [ 'text/plain', 'text/csv' ], true ) ) {
			return \str_starts_with( $real_mime, 'text/' ) || 'application/csv' === $real_mime;
		}

		// 文件 / 壓縮檔：finfo 常判成籠統的二進位類型
		return \in_array( $real_mime, self::NONSPECIFIC_REAL_MIMES, true );
	}

	/**
	 * 組出「不支援的檔案類型」錯誤訊息（顯示呼叫端提供的 MIME；未提供時顯示副檔名）
	 *
	 * @param string $original_name 原始檔名
	 * @param string $mime_type     呼叫端提供的 MIME
	 * @return string 已翻譯的錯誤訊息
	 */
	private static function unsupported_type_message( string $original_name, string $mime_type ): string {
		$display = \sanitize_mime_type( $mime_type );
		if ( '' === $display ) {
			$display = (string) \pathinfo( $original_name, \PATHINFO_EXTENSION );
		}

		return \sprintf(
			/* translators: %s: 檔案的 MIME 類型 */
			\__( 'Unsupported file type: %s', 'power-course' ),
			$display
		);
	}

	/**
	 * 正規化原始檔名：去除路徑、無效 UTF-8 與控制字元，超過 255 字時保留副檔名截斷
	 *
	 * @param string $original_name 原始檔名
	 * @return string
	 */
	private static function normalize_name( string $original_name ): string {
		$name = \wp_check_invalid_utf8( \wp_basename( $original_name ), true );
		$name = \trim( (string) \preg_replace( '/[\x00-\x1F\x7F]/u', '', $name ) );

		if ( \mb_strlen( $name ) <= self::MAX_NAME_LENGTH ) {
			return $name;
		}

		$extension = (string) \pathinfo( $name, \PATHINFO_EXTENSION );
		$suffix    = '' !== $extension ? '.' . $extension : '';

		return \mb_substr( $name, 0, self::MAX_NAME_LENGTH - \mb_strlen( $suffix ) ) . $suffix;
	}
}
