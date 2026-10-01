<?php
/**
 * Assignment 學員端 REST API（Issue #131）
 *
 * 註冊 power-course/my/* 端點（對映 specs/api/api.yml tag Assignments）：
 *   - GET  /my/assignments                     我的作業列表（chapter_id 或 course_id）
 *   - GET  /my/assignments/{id}                我的單一作業（含自己的繳交）
 *   - POST /my/assignments/{id}/submit         繳交作業
 *   - POST /my/assignment-attachments          上傳作業附件（multipart，field 名 file）
 *   - GET  /my/assignment-submissions/{id}/messages  讀取自己繳交的討論串（先標老師訊息已讀，第二波）
 *   - POST /my/assignment-submissions/{id}/messages  在自己繳交的討論串發送訊息（第二波）
 *
 * permission_callback 一律「已登入即可」（未登入 401 rest_not_logged_in，DEC-01）。
 * 本類別負責 $_FILES 解析、PHP 上傳錯誤碼轉換（DEC-17）與 is_uploaded_file()（R-13），
 * 業務邏輯委派給 Service\Attachment / Service\MyQuery / Service\Submission / Service\Thread。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Service\Attachment as AttachmentService;
use J7\PowerCourse\Resources\Assignment\Service\MyQuery;
use J7\PowerCourse\Resources\Assignment\Service\Submission as SubmissionService;
use J7\PowerCourse\Resources\Assignment\Service\Thread;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;

/**
 * Class MyApi
 */
final class MyApi extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint:string,method:string,permission_callback?: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'my/assignments',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		[
			'endpoint'            => 'my/assignments/(?P<id>\d+)',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		[
			'endpoint'            => 'my/assignments/(?P<id>\d+)/submit',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		[
			'endpoint'            => 'my/assignment-attachments',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		// 第二波：作業討論串（不宣告 args，body 驗證在 callback 內、permission 之後，DEC-W2-02）
		[
			'endpoint'            => 'my/assignment-submissions/(?P<id>\d+)/messages',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
		[
			'endpoint'            => 'my/assignment-submissions/(?P<id>\d+)/messages',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_logged_in_permission' ],
		],
	];

	/**
	 * 我的作業列表（chapter_id 或 course_id 至少一個）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_assignments_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => MyQuery::list( $request->get_query_params(), \get_current_user_id() )
		);
	}

	/**
	 * 我的單一作業（含自己的繳交）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_assignments_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => MyQuery::get( self::get_id( $request ), \get_current_user_id() )
		);
	}

	/**
	 * 繳交作業（首次建立或重交覆寫）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_my_assignments_with_id_submit_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => SubmissionService::submit(
				self::get_id( $request ),
				\get_current_user_id(),
				self::get_body_args( $request )
			)
		);
	}

	/**
	 * 學員讀取自己繳交的討論串（先把老師訊息標已讀再讀取，扁平列表）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_my_assignment_submissions_with_id_messages_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Thread::list_for_student( self::get_id( $request ), \get_current_user_id() )
		);
	}

	/**
	 * 學員在自己繳交的討論串發送訊息（成功一律 200）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_my_assignment_submissions_with_id_messages_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Thread::post_as_student(
				self::get_id( $request ),
				\get_current_user_id(),
				self::get_body_args( $request )
			)
		);
	}

	/**
	 * 權限檢查：已登入即可（學員端與下載端點共用）
	 *
	 * @return bool|\WP_Error true 代表有權限；WP_Error 代表拒絕（401）。
	 */
	public static function check_logged_in_permission() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				\__( 'You are not currently logged in.', 'power-course' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * 上傳作業附件（只需登入，不指定作業、不檢查課程觀看權）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_my_assignment_attachments_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		try {
			$file = self::get_uploaded_file( $request );

			AttachmentService::verify_uploaded_content( $file['tmp_name'], $file['name'], $file['type'], $file['size'] );

			$data = AttachmentService::store( \get_current_user_id(), $file['tmp_name'], $file['name'], $file['type'], $file['size'] );
		} catch ( AssignmentException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage(), [ 'status' => $e->get_status() ] );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * 執行 Service 呼叫並包成 REST 回應；AssignmentException 轉成帶 status 的 WP_Error
	 *
	 * @param callable(): mixed $action 回傳回應資料的 Service 呼叫
	 * @param int               $status 成功時的 HTTP status
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function respond( callable $action, int $status = 200 ): \WP_REST_Response|\WP_Error {
		try {
			$data = $action();
		} catch ( AssignmentException $e ) {
			return self::error_response( $e );
		}

		return new \WP_REST_Response( $data, $status );
	}

	/**
	 * AssignmentException → REST 錯誤回應
	 *
	 * 「尚未報名此課程」與「此章節尚未解鎖…」的 error code 是字面字串 '403'；
	 * WP_Error 以 error code 當陣列鍵，純數字字串會被 PHP 轉成 int，
	 * 回應就會變成 403（數字）而不是 '403'（字串）。
	 * 故數字型 code 一律比照既有 Resources\Chapter\Core\Api 的寫法，
	 * 直接回傳 { code, message } 的 WP_REST_Response 保住字串型別。
	 *
	 * @param AssignmentException $e 領域例外
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function error_response( AssignmentException $e ): \WP_REST_Response|\WP_Error {
		$code = $e->get_error_code();

		if ( \is_numeric( $code ) ) {
			return new \WP_REST_Response(
				[
					'code'    => $code,
					'message' => $e->getMessage(),
				],
				$e->get_status()
			);
		}

		return new \WP_Error( $code, $e->getMessage(), [ 'status' => $e->get_status() ] );
	}

	/**
	 * 取得路徑參數 id
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return int
	 */
	private static function get_id( \WP_REST_Request $request ): int {
		$url_params = $request->get_url_params();

		return \absint( $url_params['id'] ?? 0 );
	}

	/**
	 * 取得 request body 參數（JSON 優先，其次 form-encoded）
	 *
	 * 不做 sanitize_text_field_deep：text_body 允許換行與各種符號，
	 * 欄位驗證統一由 Service\Submission 處理。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return array<string, mixed>
	 */
	private static function get_body_args( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();

		/** @var array<string, mixed> $body */
		$body = $json ? $json : $request->get_body_params();

		return $body;
	}

	/**
	 * PHP 上傳錯誤碼 → 使用者可見訊息（DEC-17）
	 *
	 * 當 upload_max_filesize / MAX_FILE_SIZE 擋下檔案時回「檔案過大（上限 50MB）」，
	 * 不回「請選擇要上傳的檔案」（主機常見上限 2MB–8MB，否則訊息會誤導學員）。
	 *
	 * @param int $php_upload_error PHP 上傳錯誤碼（UPLOAD_ERR_*）
	 * @return string 已翻譯的錯誤訊息
	 */
	public static function translate_upload_error( int $php_upload_error ): string {
		return match ( $php_upload_error ) {
			\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => \__( 'File too large (max 50MB)', 'power-course' ),
			\UPLOAD_ERR_NO_FILE => \__( 'Please select a file to upload', 'power-course' ),
			default => \__( 'Failed to upload file', 'power-course' ),
		};
	}

	/**
	 * 從請求取出單一上傳檔（field 名 file），並檢查 PHP 上傳錯誤與 is_uploaded_file()
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return array{tmp_name: string, name: string, type: string, size: int}
	 * @throws AssignmentException 未附檔案 / 上傳錯誤時。
	 */
	private static function get_uploaded_file( \WP_REST_Request $request ): array {
		$files = $request->get_file_params();
		$file  = \array_key_exists( 'file', $files ) ? $files['file'] : null;

		if ( ! \is_array( $file ) || ! isset( $file['tmp_name'] ) || ! \is_string( $file['tmp_name'] ) ) {
			// DEC-17：整個 body 超過 post_max_size 時 PHP 會丟掉 $_FILES，此時回「檔案過大」
			throw AssignmentException::invalid_param(
				self::is_post_max_size_exceeded()
					? self::translate_upload_error( \UPLOAD_ERR_INI_SIZE )
					: self::translate_upload_error( \UPLOAD_ERR_NO_FILE )
			);
		}

		$error = isset( $file['error'] ) && \is_numeric( $file['error'] ) ? (int) $file['error'] : \UPLOAD_ERR_NO_FILE;
		if ( \UPLOAD_ERR_OK !== $error ) {
			$message = self::translate_upload_error( $error );

			// 暫存目錄不存在 / 無法寫入 / 被擴充中止：主機設定問題，回 500 並記 log
			if ( \in_array( $error, [ \UPLOAD_ERR_NO_TMP_DIR, \UPLOAD_ERR_CANT_WRITE, \UPLOAD_ERR_EXTENSION ], true ) ) {
				Plugin::logger( 'Assignment attachment upload failed', 'error', [ 'php_upload_error' => $error ] );
				throw AssignmentException::internal( $message );
			}

			throw AssignmentException::invalid_param( $message );
		}

		if ( '' === $file['tmp_name'] || ! \is_uploaded_file( $file['tmp_name'] ) ) {
			throw AssignmentException::invalid_param( self::translate_upload_error( \UPLOAD_ERR_NO_FILE ) );
		}

		return [
			'tmp_name' => $file['tmp_name'],
			'name'     => isset( $file['name'] ) && \is_string( $file['name'] ) ? \wp_basename( $file['name'] ) : '',
			'type'     => isset( $file['type'] ) && \is_string( $file['type'] ) ? $file['type'] : '',
			'size'     => isset( $file['size'] ) && \is_numeric( $file['size'] ) ? (int) $file['size'] : 0,
		];
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
