<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Settings\Core;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/** Settings Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint: string, method: string, permission_callback: ?callable}[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'settings',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'settings',
			'method'              => 'post',
			'permission_callback' => null,
		],
	];

	/**
	 * 獲取選項
	 *
	 * @param \WP_REST_Request $request REST請求對象。
	 * @return \WP_REST_Response 返回包含選項資料的REST響應對象。
	 */
	public function get_settings_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = Settings::instance();
		return new \WP_REST_Response(
			[
				'code'    => 'get_options_success',
				'message' => __( 'Get settings successfully', 'power-course' ),
				'data'    => $settings->to_array(),
			],
			200
		);
	}

	/**
	 * 更新選項
	 *
	 * @param \WP_REST_Request $request 包含更新選項所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回選項資料，失敗時返回錯誤訊息。
	 */
	public function post_settings_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_json_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false, [ 'pc_watermark_text', 'auto_grant_courses' ] );

		$settings = Settings::instance();
		$settings->set_properties( $body_params );
		$settings->save();

		return new \WP_REST_Response(
			[
				'code'    => 'post_user_success',
				'message' => __( 'Update successful', 'power-course' ),
				'data'    => $body_params,
			],
			200
			);
	}
}
