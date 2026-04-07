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
				'message' => '獲取短碼成功',
				'data'    => $shortcode_content,
			],
			200
		);
	}
}
