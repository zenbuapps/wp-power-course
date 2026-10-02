<?php
/**
 * Assignment 管理端 REST API（Issue #131）
 *
 * 註冊 power-course/assignments/* 端點（對映 specs/api/api.yml tag Assignments）：
 *   - GET    /assignments                作業列表（chapter_id 或 course_id）
 *   - POST   /assignments                建立作業
 *   - POST   /assignments/sort           排序章節作業
 *   - GET    /assignments/{id}           單一作業
 *   - PUT    /assignments/{id}           更新作業
 *   - DELETE /assignments/{id}           刪除作業（移至垃圾桶）
 *   - GET    /assignment-attachments/{id}/download  下載作業附件（登入即可，callback 內分管理員 / 學員）
 *   - GET    /assignments/{id}/submissions/export    匯出單一作業繳交 CSV
 *   - GET    /assignment-submissions                 繳交列表（{items, total}）
 *   - GET    /assignment-submissions/stats           批改統計
 *   - GET    /assignment-submissions/courses         有繳交紀錄的課程
 *   - GET    /assignment-submissions/student-overview 學員背景資料
 *   - GET    /assignment-submissions/export          匯出批改用（可往返）CSV
 *   - PATCH  /assignment-submissions/{id}/grade      批改繳交
 *   - GET    /assignment-submissions/{id}/messages   讀取討論串（先標學員訊息已讀，第二波）
 *   - POST   /assignment-submissions/{id}/messages   以老師身分發送討論訊息（第二波）
 *   - POST   /assignment-submissions/import          匯入批改結果 CSV（multipart，field 名 file，第二波）
 *
 * 權限沿用 ApiBase 預設（manage_options || manage_woocommerce），下載端點除外。
 * 業務邏輯委派給 Service\Crud / Service\Query；本類別只負責取參數、
 * 把 AssignmentException 轉成 WP_Error（respond()）、輸出 Model::to_array()。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Assignment;
use J7\PowerCourse\Resources\Assignment\Service\Attachment as AttachmentService;
use J7\PowerCourse\Resources\Assignment\Service\Crud;
use J7\PowerCourse\Resources\Assignment\Service\Csv;
use J7\PowerCourse\Resources\Assignment\Service\CsvImport;
use J7\PowerCourse\Resources\Assignment\Service\Grading;
use J7\PowerCourse\Resources\Assignment\Service\Query;
use J7\PowerCourse\Resources\Assignment\Service\Reporting;
use J7\PowerCourse\Resources\Assignment\Service\Thread;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Stream;
use J7\PowerCourse\Resources\Assignment\Utils\UploadedFile;

/**
 * Class Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** 匯入批改結果 CSV 的檔案大小上限（bytes，10MB；剛好 10MB 不算過大） */
	private const IMPORT_MAX_BYTES = 10485760;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint:string,method:string,permission_callback?: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint' => 'assignments',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignments',
			'method'   => 'post',
		],
		[
			'endpoint' => 'assignments/sort',
			'method'   => 'post',
		],
		[
			'endpoint' => 'assignments/(?P<id>\d+)',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignments/(?P<id>\d+)',
			'method'   => 'put',
		],
		[
			'endpoint' => 'assignments/(?P<id>\d+)',
			'method'   => 'delete',
		],
		// 下載作業附件：登入即可，管理員 / 學員的驗權在 callback 內（api.yml）
		[
			'endpoint'            => 'assignment-attachments/(?P<id>\d+)/download',
			'method'              => 'get',
			'permission_callback' => [ MyApi::class, 'check_logged_in_permission' ],
		],
		// P4：單一作業的繳交 CSV 匯出（靜態尾段，與 assignments/(?P<id>\d+) 不衝突）
		[
			'endpoint' => 'assignments/(?P<id>\d+)/submissions/export',
			'method'   => 'get',
		],
		// P4：批改與批改頁報表
		[
			'endpoint' => 'assignment-submissions',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/stats',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/courses',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/student-overview',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/export',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/(?P<id>\d+)/grade',
			'method'   => 'patch',
		],
		// 第二波：作業討論串（不宣告 args，body 驗證在 callback 內、permission 之後，DEC-W2-02）
		[
			'endpoint' => 'assignment-submissions/(?P<id>\d+)/messages',
			'method'   => 'get',
		],
		[
			'endpoint' => 'assignment-submissions/(?P<id>\d+)/messages',
			'method'   => 'post',
		],
		// 第二波：匯入批改結果 CSV（不宣告 args，檔案驗證在 callback 內、permission 之後，DEC-W2-02）
		[
			'endpoint' => 'assignment-submissions/import',
			'method'   => 'post',
		],
	];

	/**
	 * 作業列表（chapter_id 或 course_id 至少一個）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignments_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Assignment::to_list( Query::list( $request->get_query_params() ) )
		);
	}

	/**
	 * 建立作業
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_assignments_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Crud::create( self::get_body_args( $request ) )->to_array()
		);
	}

	/**
	 * 排序章節作業
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_assignments_sort_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Assignment::to_list( Crud::sort( self::get_body_args( $request ) ) )
		);
	}

	/**
	 * 單一作業
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignments_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Query::get( self::get_id( $request ) )->to_array()
		);
	}

	/**
	 * 更新作業（部分更新）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_assignments_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Crud::update( self::get_id( $request ), self::get_body_args( $request ) )->to_array()
		);
	}

	/**
	 * 刪除作業（移至垃圾桶），成功回 204 No Content
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_assignments_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static function () use ( $request ): mixed {
				Crud::trash( self::get_id( $request ) );
				return null;
			},
			204
		);
	}

	/**
	 * 下載作業附件（DEC-08：回傳帶標頭的回應，實際檔案內容由 Stream 登記的輸出器串流）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_attachments_with_id_download_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$is_admin = \current_user_can( 'manage_options' ) || \current_user_can( 'manage_woocommerce' );

		try {
			[ $attachment, $path ] = AttachmentService::get_downloadable( self::get_id( $request ), \get_current_user_id(), $is_admin );
		} catch ( AssignmentException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage(), [ 'status' => $e->get_status() ] );
		}

		return Stream::file( $path, $attachment->mime_type, $attachment->original_name );
	}

	/**
	 * 匯出單一作業的繳交 CSV（DEC-08：回傳帶下載標頭的回應，內容由 Stream 串流輸出）
	 *
	 * 作業不存在或在垃圾桶時一律 404（Query::get()），不洩漏是哪一種。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignments_with_id_submissions_export_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$assignment_id = self::get_id( $request );

		try {
			$assignment = Query::get( $assignment_id );
			$stream     = Csv::write_submission_csv( $assignment_id );
		} catch ( AssignmentException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage(), [ 'status' => $e->get_status() ] );
		}

		return Stream::csv( $stream, Csv::submission_file_name( $assignment->title ) );
	}

	/**
	 * 匯出批改用（可往返）CSV（DEC-08：回傳帶下載標頭的回應，內容由 Stream 串流輸出）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_export_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		try {
			$stream = Csv::write_grading_csv( $request->get_query_params() );
		} catch ( AssignmentException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage(), [ 'status' => $e->get_status() ] );
		}

		return Stream::csv( $stream, Csv::grading_file_name() );
	}

	/**
	 * 作業繳交列表（批改頁主列表，{items, total}）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Reporting::list_submissions( $request->get_query_params() )
		);
	}

	/**
	 * 作業批改統計
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_stats_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Reporting::stats( $request->get_query_params() )
		);
	}

	/**
	 * 有繳交紀錄的課程（批改頁課程 Tab）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_courses_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Reporting::courses_with_submissions()
		);
	}

	/**
	 * 作業學員背景資料（批改頁右欄）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_student_overview_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Reporting::student_overview( $request->get_query_params() )
		);
	}

	/**
	 * 批改作業繳交
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function patch_assignment_submissions_with_id_grade_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Grading::grade(
				self::get_id( $request ),
				self::get_body_args( $request ),
				\get_current_user_id()
			)->to_array()
		);
	}

	/**
	 * 管理員讀取作業討論串（先把學員訊息標已讀再讀取，扁平列表）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_assignment_submissions_with_id_messages_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Thread::list_for_teacher( self::get_id( $request ) )
		);
	}

	/**
	 * 管理員以老師身分在討論串發送訊息（成功一律 200）
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_assignment_submissions_with_id_messages_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		return self::respond(
			static fn(): array => Thread::post_as_teacher(
				self::get_id( $request ),
				\get_current_user_id(),
				self::get_body_args( $request )
			)
		);
	}

	/**
	 * 匯入批改結果 CSV（逐列等同批改作業，批改者為目前管理員）
	 *
	 * 上傳層檢查見 read_import_csv()（DEC-W2-13）；通過後一律 200，
	 * CSV 無法解析 / 空檔 / 只有表頭 / 超量也是 200，摘要的 errors 帶 row 0（由 CsvImport 決定）。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_assignment_submissions_import_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		// 逐列批改並排批改通知，上限 5000 列，不讓預設執行時間中斷到一半（DEC-W2-14）
		if ( \function_exists( 'wc_set_time_limit' ) ) {
			\wc_set_time_limit( 0 );
		}

		return self::respond(
			static fn(): array => CsvImport::import( self::read_import_csv( $request ), \get_current_user_id() )
		);
	}

	/**
	 * 從請求取出匯入用 CSV 的原始內容（field 名 file）
	 *
	 * 檢查順序（DEC-W2-13）：沒有檔案 → PHP 上傳錯誤碼 → 大小上限 10MB → is_uploaded_file()（Utils\UploadedFile）→ 讀檔。
	 * 大小檢查在 is_uploaded_file() 之前；通過大小檢查後仍一律 is_uploaded_file()，不讀任意本機路徑。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return string CSV 原始位元組
	 * @throws AssignmentException 未附檔案 / 檔案過大 / 上傳錯誤（400）；主機上傳設定問題或讀檔失敗（500）。
	 */
	private static function read_import_csv( \WP_REST_Request $request ): string {
		$file = UploadedFile::from_request( $request, self::import_upload_messages(), 'Assignment grading CSV upload failed', self::IMPORT_MAX_BYTES );

		$raw = \file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $raw ) {
			Plugin::logger( 'Assignment grading CSV read failed', 'error' );
			throw AssignmentException::internal( \__( 'Failed to import CSV', 'power-course' ) );
		}

		return $raw;
	}

	/**
	 * 匯入用的使用者可見上傳訊息（上限 10MB，與學員附件上傳的 50MB 分開；交給 Utils\UploadedFile）
	 *
	 * 主機的 upload_max_filesize / MAX_FILE_SIZE 擋下檔案時同樣回「檔案過大（上限 10MB）」，不回「請選擇」。
	 *
	 * @return array{too_large: string, no_file: string, failed: string}
	 */
	private static function import_upload_messages(): array {
		return [
			'too_large' => \__( 'File too large (max 10MB)', 'power-course' ),
			'no_file'   => \__( 'Please select a CSV file to import', 'power-course' ),
			'failed'    => \__( 'Failed to import CSV', 'power-course' ),
		];
	}

	/**
	 * 執行 Service 呼叫並包成 REST 回應；AssignmentException 轉成帶 status 的 WP_Error
	 *
	 * @param callable(): mixed $action 回傳回應資料（Model::to_array() / to_list() 的結果或 null）的 Service 呼叫
	 * @param int               $status 成功時的 HTTP status
	 * @return \WP_REST_Response|\WP_Error
	 */
	private static function respond( callable $action, int $status = 200 ): \WP_REST_Response|\WP_Error {
		try {
			$data = $action();
		} catch ( AssignmentException $e ) {
			return new \WP_Error( $e->get_error_code(), $e->getMessage(), [ 'status' => $e->get_status() ] );
		}

		return new \WP_REST_Response( $data, $status );
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
	 * 不做 sanitize_text_field_deep：instructions 是 HTML，欄位清洗與驗證統一由 Service\Crud 處理。
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
}
