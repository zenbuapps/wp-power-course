<?php

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Utils\Duplicate;
use J7\PowerCourse\Resources\Settings\Core\Api as SettingsApi;
/**
 * Option Api
 *
 * @deprecated 使用 Settings Api 取代
 * */
final class Option extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint: string, method: string, permission_callback: ?callable}[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'options',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'options',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'duplicate/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => null,
		],
	];

	/**
	 * 獲取選項
	 *
	 * @deprecated 使用 Settings Api 取代
	 * @param \WP_REST_Request $request REST請求對象。
	 * @return \WP_REST_Response 返回包含選項資料的REST響應對象。
	 */
	public function get_options_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return SettingsApi::instance()->get_settings_callback( $request );
	}

	/**
	 * 更新選項
	 *
	 * @param \WP_REST_Request $request 包含更新選項所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回選項資料，失敗時返回錯誤訊息。
	 */
	public function post_options_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return SettingsApi::instance()->post_settings_callback( $request );
	}


	/**
	 * 複製
	 *
	 * @param \WP_REST_Request $request 包含更新選項所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回選項資料，失敗時返回錯誤訊息。
	 */
	public function post_duplicate_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$id = $request['id'] ?? null;

		if (!$id || !is_numeric( $id ) ) {
			return new \WP_REST_Response(
				[
					'message' => 'id is required',
				],
				400
			);
		}

		$duplicate = new Duplicate();
		$new_id    = $duplicate->process( (int) $id, true, true );

		return new \WP_REST_Response(
			[
				'code'    => 'post_duplicate_success',
				'message' => __( 'Duplicated successfully', 'power-course' ),
				'data'    => $new_id,
			],
			200
			);
	}
}
