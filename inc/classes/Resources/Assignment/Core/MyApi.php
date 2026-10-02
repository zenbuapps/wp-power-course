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
 * 附件上傳的取檔、PHP 上傳錯誤碼轉換（DEC-17）與 is_uploaded_file()（R-13）委派給 Utils\UploadedFile，
 * 本類別只提供附件上傳專用的訊息；業務邏輯委派給 Service\Attachment / Service\MyQuery / Service\Submission / Service\Thread。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\Assignment\Service\Attachment as AttachmentService;
use J7\PowerCourse\Resources\Assignment\Service\MyQuery;
use J7\PowerCourse\Resources\Assignment\Service\Submission as SubmissionService;
use J7\PowerCourse\Resources\Assignment\Service\Thread;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\UploadedFile;

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
			// 大小上限（50MB）由 Service\Attachment 檢查，這一層不重複檢查
			$file = UploadedFile::from_request( $request, self::upload_messages(), 'Assignment attachment upload failed' );

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
		return UploadedFile::translate_error( $php_upload_error, self::upload_messages() );
	}

	/**
	 * 附件上傳的使用者可見訊息（交給 Utils\UploadedFile）
	 *
	 * @return array{too_large: string, no_file: string, failed: string}
	 */
	private static function upload_messages(): array {
		return [
			'too_large' => \__( 'File too large (max 50MB)', 'power-course' ),
			'no_file'   => \__( 'Please select a file to upload', 'power-course' ),
			'failed'    => \__( 'Failed to upload file', 'power-course' ),
		];
	}
}
