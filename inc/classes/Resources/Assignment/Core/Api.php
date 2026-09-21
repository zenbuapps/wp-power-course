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
 *
 * 權限沿用 ApiBase 預設（manage_options || manage_woocommerce），下載端點除外。
 * 業務邏輯委派給 Service\Crud / Service\Query；本類別只負責取參數、
 * 把 AssignmentException 轉成 WP_Error（respond()）、輸出 Model::to_array()。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\Assignment\Model\Assignment;
use J7\PowerCourse\Resources\Assignment\Service\Attachment as AttachmentService;
use J7\PowerCourse\Resources\Assignment\Service\Crud;
use J7\PowerCourse\Resources\Assignment\Service\Query;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Stream;

/**
 * Class Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

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
