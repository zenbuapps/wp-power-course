<?php
/**
 * Shortcode API
 */

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;

/**
 * Shortcode Api
 */
final class Shortcode extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * Namespace
	 *
	 * @var string
	 */
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
			'endpoint'            => 'shortcode',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'courses-shortcode-page',
			'method'              => 'get',
			'permission_callback' => null,
		],
	];


	/**
	 * 獲取選項
	 *
	 * @param \WP_REST_Request $request REST請求對象。
	 * @return \WP_REST_Response 返回包含選項資料的REST響應對象。
	 */
	public function get_shortcode_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$params    = $request->get_params();
		$shortcode = \sanitize_text_field( (string) ( $params['shortcode'] ?? '' ) );

		$shortcode_content = \do_shortcode( $shortcode, true );

		return new \WP_REST_Response(
			[
				'code'    => 'get_shortcode_success',
				'message' => __( 'Shortcode retrieved successfully', 'power-course' ),
				'data'    => $shortcode_content,
			],
			200
		);
	}

	/**
	 * 課程列表 AJAX 分頁端點
	 *
	 * 沿用 [pc_courses] 短代碼既有查詢參數（category / tag / orderby / exclude_avl_courses 等）
	 * 再加上 page，回傳對應頁的課程卡片 HTML 與分頁資訊。
	 *
	 * 安全性：
	 * - 第一行呼叫 nocache_headers()（Issue #216 規範），避免邊緣快取回傳 stale 資料。
	 * - 僅查 publish + visible 課程（沿用 General::get_courses_page 預設，不接受外部覆寫 status/visibility）。
	 * - 所有輸入經 absint / sanitize_text_field / filter_var 正規化；空字串參數不傳入查詢。
	 *
	 * @param \WP_REST_Request $request REST 請求對象。
	 * @return \WP_REST_Response 含 html / total / total_pages / current_page 的回應。
	 */
	public function get_courses_shortcode_page_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$p = $request->get_params();

		$order = \strtoupper( (string) ( $p['order'] ?? 'DESC' ) );
		$order = \in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

		$params = [
			'page'                => max( 1, \absint( $p['page'] ?? 1 ) ),
			'limit'               => \absint( $p['limit'] ?? 12 ) ?: 12,
			'columns'             => \absint( $p['columns'] ?? 3 ) ?: 3,
			'orderby'             => \sanitize_text_field( (string) ( $p['orderby'] ?? 'date' ) ),
			'order'               => $order,
			'category'            => \sanitize_text_field( (string) ( $p['category'] ?? '' ) ),
			'tag'                 => \sanitize_text_field( (string) ( $p['tag'] ?? '' ) ),
			'include'             => \sanitize_text_field( (string) ( $p['include'] ?? '' ) ),
			'exclude'             => \sanitize_text_field( (string) ( $p['exclude'] ?? '' ) ),
			'exclude_avl_courses' => \filter_var( $p['exclude_avl_courses'] ?? false, FILTER_VALIDATE_BOOLEAN ),
		];

		$page = \J7\PowerCourse\Shortcodes\General::get_courses_page( $params );

		return new \WP_REST_Response(
			[
				'code'    => 'get_courses_shortcode_page_success',
				'message' => __( 'Courses page retrieved successfully', 'power-course' ),
				'data'    => [
					'html'         => $page['html'],
					'total'        => $page['total'],
					'total_pages'  => $page['total_pages'],
					'current_page' => $page['current_page'],
				],
			],
			200
		);
	}
}
