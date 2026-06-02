<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Student\Core;

use J7\PowerCourse\Resources\Student\Service\ExportCSV;
use J7\PowerCourse\Resources\Student\Service\ExportAllCSV;
use J7\PowerCourse\Resources\Student\Service\Query;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\Datetime;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\General;
use J7\WpUtils\Classes\ApiBase;
use J7\Powerhouse\Domains\User\Model\User;


/** Class Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string 命名空間 */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected $apis = [
		[
			'endpoint'            => 'students/export/(?P<id>\d+)',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'students/export-all',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'students/export-count',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'students',
			'method'              => 'get',
			'permission_callback' => null,
		],
	];

	/** Constructor */
	public function __construct() {
		parent::__construct();
		ExtendQuery::instance();
		\add_filter('powerhouse/user/get_meta_keys_array', [ $this, 'extend_meta_keys' ], 10, 2);
	}

	/**
	 * 匯出學員名單
	 *
	 * @param \WP_REST_Request $request 包含課程 ID 的 REST 請求對象。
	 * @return \WP_REST_Response
	 */
	public function get_students_export_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$course_id = (int) $request['id'];

		// 解析 search / progress 篩選參數 (與 GET /students 一致的驗證)
		$raw_params = $request->get_query_params();
		$raw_params = is_array( $raw_params ) ? $raw_params : [];

		$progress_error = $this->validate_progress_params( $raw_params );
		if ( null !== $progress_error ) {
			return $progress_error;
		}

		$search            = isset( $raw_params['search'] ) && is_scalar( $raw_params['search'] )
		? \sanitize_text_field( (string) $raw_params['search'] )
		: '';
		$progress_operator = null;
		$progress_value    = null;
		if (
			isset( $raw_params['progress_operator'], $raw_params['progress_value'] )
			&& '' !== $raw_params['progress_operator']
			&& '' !== $raw_params['progress_value']
		) {
			$progress_operator = (string) $raw_params['progress_operator'];
			$progress_value    = (int) $raw_params['progress_value'];
		}

		$export = new ExportCSV( $course_id, $search, $progress_operator, $progress_value );
		$export->export();
		return new \WP_REST_Response(
			[
				'code'    => 'get_students_export_success',
				'message' => __( 'Export successful', 'power-course' ),
				'data'    => null,
			]
			);
	}

	/**
	 * 匯出全域學員名單 CSV
	 *
	 * @param \WP_REST_Request $request 包含篩選參數的 REST 請求對象。
	 * @return \WP_REST_Response
	 */
	public function get_students_export_all_callback( \WP_REST_Request $request ): \WP_REST_Response {
		[
			'search'         => $search,
			'avl_course_ids' => $avl_course_ids,
			'include'        => $include,
		] = $this->extract_export_params( $request );

		try {
			$export = new ExportAllCSV( $search, $avl_course_ids, $include );
			$export->export();

			return new \WP_REST_Response(
				[
					'code'    => 'get_students_export_all_success',
					'message' => __( 'Export successful', 'power-course' ),
					'data'    => null,
				]
			);
		} catch ( \Throwable $th ) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					/* translators: %s: 錯誤訊息 */
					__( 'Failed to export all students CSV, %s', 'power-course' ),
					$th->getMessage()
				),
				'error'
			);

			return new \WP_REST_Response(
				[
					'code'    => 'export_all_error',
					'message' => __( 'Failed to export', 'power-course' ),
					'data'    => null,
				],
				500
			);
		}
	}

	/**
	 * 取得全域學員匯出預估筆數
	 *
	 * @param \WP_REST_Request $request 包含篩選參數的 REST 請求對象。
	 * @return \WP_REST_Response
	 */
	public function get_students_export_count_callback( \WP_REST_Request $request ): \WP_REST_Response {
		[
			'search'         => $search,
			'avl_course_ids' => $avl_course_ids,
			'include'        => $include,
		] = $this->extract_export_params( $request );

		try {
			$count = ExportAllCSV::get_export_count( $search, $avl_course_ids, $include );

			return new \WP_REST_Response( [ 'count' => $count ] );
		} catch ( \Throwable $th ) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					/* translators: %s: 錯誤訊息 */
					__( 'Failed to get export count of all students, %s', 'power-course' ),
					$th->getMessage()
				),
				'error'
			);

			return new \WP_REST_Response(
				[
					'code'    => 'export_count_error',
					'message' => __( 'Failed to get export count', 'power-course' ),
					'data'    => null,
				],
				500
			);
		}
	}

	/**
	 * 從 REST 請求中提取匯出篩選參數
	 *
	 * @param \WP_REST_Request $request REST 請求對象。
	 * @return array{search: string, avl_course_ids: array<string>, include: array<string>}
	 */
	private function extract_export_params( \WP_REST_Request $request ): array {
		$params = $request->get_query_params();
		$params = WP::sanitize_text_field_deep( $params, false );

		/** @var array<string, mixed> $sanitized_params */
		$sanitized_params = is_array( $params ) ? $params : [];

		$avl_course_ids = [];
		if ( isset( $sanitized_params['avl_course_ids'] ) && is_array( $sanitized_params['avl_course_ids'] ) ) {
			foreach ( $sanitized_params['avl_course_ids'] as $course_id_value ) {
				$avl_course_ids[] = is_scalar( $course_id_value ) ? (string) $course_id_value : '';
			}
		}

		$include_ids = [];
		if ( isset( $sanitized_params['include'] ) && is_array( $sanitized_params['include'] ) ) {
			foreach ( $sanitized_params['include'] as $include_value ) {
				$include_ids[] = is_scalar( $include_value ) ? (string) $include_value : '';
			}
		}

		return [
			'search'         => (string) ( $sanitized_params['search'] ?? '' ),
			'avl_course_ids' => $avl_course_ids,
			'include'        => $include_ids,
		];
	}

	/**
	 * 取得學員
	 *
	 * @param \WP_REST_Request $request Request.
	 * $params
	 *  - meta_key avl_course_ids 如果要找用戶可以上的課程
	 *  - meta_value
	 * - count_total 是否要計算總數
	 *
	 * @return \WP_REST_Response
	 */
	public function get_students_callback( $request ): \WP_REST_Response {
		\nocache_headers();

		// 取得原始 query params 用於 progress 驗證（sanitize 前），避免 sanitize_text_field 把 0 轉成 ''
		$raw_params = $request->get_query_params();
		$raw_params = is_array( $raw_params ) ? $raw_params : [];

		// 驗證 progress_operator / progress_value（必須成對 + 白名單 + 範圍）
		$progress_error = $this->validate_progress_params( $raw_params );
		if ( null !== $progress_error ) {
			return $progress_error;
		}

		$params = WP::sanitize_text_field_deep( $raw_params, false );

		/** @var array<string, mixed> $sanitized_params */
		$sanitized_params              = is_array($params) ? $params : [];
		[$meta_keys, $rest_params] = General::destruct($sanitized_params, 'meta_keys');
		/** @var array<string> $meta_keys */
		$meta_keys                 = is_array($meta_keys) ? $meta_keys : [];

		// 將驗證過的 progress 參數從 raw_params 重新覆寫到 rest_params，
		// 避免 sanitize_text_field 把 `<` 等特殊字元改掉
		if ( isset( $rest_params['progress_operator'] ) && isset( $rest_params['progress_value'] ) ) {
			$rest_params['progress_operator'] = (string) $raw_params['progress_operator'];
			$rest_params['progress_value']    = (int) $raw_params['progress_value'];
		}

		try {
			/** @var array<string, mixed> $rest_params */
			$query      = new Query($rest_params);
			$user_ids   = $query->user_ids;
			$pagination = $query->get_pagination();
		} catch ( \Throwable $th ) {
			$message = $th->getMessage();
			$code    = 'students_query_error';
			if ( false !== strpos( $message, 'meta_value' ) ) {
				$code = 'students_course_id_required';
			}
			return new \WP_REST_Response(
				[
					'code'    => $code,
					'message' => $message,
					'data'    => null,
				],
				400
			);
		}

		$formatted_users = [];
		foreach ($user_ids as $user_id) {
			$formatted_user = User::instance( (int) $user_id )->to_array('list', $meta_keys);
			// Issue #233：資料源 Powerhouse to_array('list') 回傳 user_registered 為 UTC 原值，
			// 於此後處理轉成 WP 設定時區（僅 +offset 一次，不 double-shift）。
			// 不碰 user_registered_human（相對時間，TZ offset 對相減無影響）。
			if ( isset( $formatted_user['user_registered'] ) ) {
				$formatted_user['user_registered'] = Datetime::to_site_timezone( (string) $formatted_user['user_registered'] );
			}
			$formatted_users[] = $formatted_user;
		}

		$response = new \WP_REST_Response( $formatted_users );

		$response->header( 'X-WP-Total', (string) $pagination->total );
		$response->header( 'X-WP-TotalPages', (string) $pagination->total_pages );

		return $response;
	}

	/**
	 * 驗證 progress_operator / progress_value 參數
	 *
	 * 規格 (Issue #227)：
	 * - 兩者必須同時提供 → progress_pair_required
	 * - operator 限定白名單 → progress_operator_invalid
	 * - value 限定 0-100 整數 → progress_value_invalid
	 *
	 * @param array<string, mixed> $params 原始 query params。
	 * @return \WP_REST_Response|null 驗證失敗時回 400 response；驗證通過或無 progress 參數時回 null。
	 */
	private function validate_progress_params( array $params ): ?\WP_REST_Response {
		$has_operator = isset( $params['progress_operator'] ) && '' !== $params['progress_operator'];
		$has_value    = isset( $params['progress_value'] ) && '' !== $params['progress_value'];

		// 兩者皆未提供 → 不做 progress 篩選
		if ( ! $has_operator && ! $has_value ) {
			return null;
		}

		// 只給一邊 → 400 progress_pair_required
		if ( $has_operator xor $has_value ) {
			return new \WP_REST_Response(
				[
					'code'    => 'progress_pair_required',
					'message' => __( 'Both progress_operator and progress_value must be provided', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}

		// 白名單檢查
		$operator = (string) $params['progress_operator'];
		if ( ! in_array( $operator, Query::VALID_PROGRESS_OPERATORS, true ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'progress_operator_invalid',
					'message' => __( 'progress_operator must be one of =, !=, <, <=, >, >=', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}

		// 0-100 整數檢查
		$raw_value = $params['progress_value'];
		if ( ! is_numeric( $raw_value ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'progress_value_invalid',
					'message' => __( 'progress_value must be an integer between 0 and 100', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}
		// 比對前後整數轉換結果，確保是整數（拒絕 50.5、abc 等）
		$float_value = (float) $raw_value;
		$int_value   = (int) $raw_value;
		if ( (float) $int_value !== $float_value || $int_value < 0 || $int_value > 100 ) {
			return new \WP_REST_Response(
				[
					'code'    => 'progress_value_invalid',
					'message' => __( 'progress_value must be an integer between 0 and 100', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}

		return null;
	}

	/**
	 * 擴充 meta keys
	 *
	 * @param array<string, mixed> $meta_keys_array Meta keys array.
	 * @param \WP_User             $user          User.
	 * @return array<string, mixed>
	 */
	public function extend_meta_keys( array $meta_keys_array, \WP_User $user ): array {
		// 新增 formatted_name 欄位（Fallback Chain: billing → WP meta → display_name）
		$meta_keys_array['formatted_name'] = \J7\PowerCourse\Utils\User::get_formatted_name( $user->ID );

		if (isset($meta_keys_array['is_teacher'])) {
			$meta_keys_array['is_teacher'] = \wc_string_to_bool( (string) \get_user_meta($user->ID, 'is_teacher', true));
		}

		if (isset($meta_keys_array['avl_courses'])) {
			$avl_course_ids =\get_user_meta($user->ID, 'avl_course_ids');
			$avl_course_ids = \is_array($avl_course_ids) ? $avl_course_ids : [];

			$avl_courses = [];
			foreach ($avl_course_ids as $i => $course_id) {
				$course_id               = (int) $course_id;
				$total_chapters_count    = count(ChapterUtils::get_flatten_post_ids($course_id));
				$finished_chapters_count = count(CourseUtils::get_finished_sub_chapters($course_id, $user->ID, true));

				$avl_courses[ $i ]['id']                      = (string) $course_id;
				$avl_courses[ $i ]['name']                    = \get_the_title($course_id);
				$avl_courses[ $i ]['progress']                = CourseUtils::get_course_progress( $course_id, $user->ID );
				$avl_courses[ $i ]['finished_chapters_count'] = $finished_chapters_count;
				$avl_courses[ $i ]['total_chapters_count']    = $total_chapters_count;
				$avl_courses[ $i ]['expire_date']             = ExpireDate::instance($course_id, $user->ID)->to_array();
			}
			$meta_keys_array['avl_courses'] = $avl_courses;
		}

		return $meta_keys_array;
	}
}
