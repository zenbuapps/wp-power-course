<?php
/**
 * AccessPass REST API（Issue #252）
 *
 * 註冊 power-course/access-passes/* 端點，提供課程權限包 CRUD、停用、掛載到商品。
 * 對映 specs/api/api.yml 的 /access-passes 段（6 endpoint）。
 *
 * 業務邏輯委派給 Service\Crud / Service\Query；本類別僅負責：
 *   1. 解析 / 清洗 request 參數
 *   2. 將 Service 拋出的 \RuntimeException 轉成對映的 WP_Error（400 / 403 / 404）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Core;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Service\Query;

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
			'endpoint' => 'access-passes',
			'method'   => 'get',
		],
		[
			'endpoint' => 'access-passes',
			'method'   => 'post',
		],
		[
			'endpoint' => 'access-passes',
			'method'   => 'delete',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)',
			'method'   => 'get',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)',
			'method'   => 'put',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/disable',
			'method'   => 'post',
		],
		[
			'endpoint' => 'access-passes/(?P<id>\d+)/attach',
			'method'   => 'post',
		],
	];

	/**
	 * 權限包列表
	 *
	 * 支援 query param：status=active|disabled。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response
	 */
	public function get_access_passes_callback( $request ): \WP_REST_Response { // phpcs:ignore
		\nocache_headers();

		$params = $request->get_query_params();
		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$list = Query::list( $params );
		return new \WP_REST_Response( $list );
	}

	/**
	 * 建立權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$args = $this->get_body_args( $request );

		try {
			$id = Crud::create( $args );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'rest_invalid_param', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'create_success',
				'message' => \__( 'Access pass created', 'power-course' ),
				'data'    => [ 'id' => $id ],
			]
		);
	}

	/**
	 * 批次刪除權限包（需二次確認，真正收回已購用戶觀看權）
	 *
	 * 對映 api.yml /access-passes DELETE（行 6295-6349）契約：
	 *   - req body：{ ids: int[]（minItems 1）, confirm: bool（必須 true）}，兩者皆 required
	 *   - resp 200：{ success: true, deleted_ids: int[], affected_user_count: int }
	 *   - resp 400：ValidationError（ids 空 / confirm 非 true / pass 不存在）
	 *   - resp 403：Precondition（保留給 Service 拋出的停用類前置失敗）
	 *
	 * 逐筆委派 Service\Crud::delete（不碰 avl_course_ids，OR 疊加互不影響），
	 * 累加 affected_user_count、收集 deleted_ids。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_access_passes_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$args = $this->get_body_args( $request );

		// === 前置（參數）：ids 不可為空陣列 ===
		$ids = isset( $args['ids'] ) && \is_array( $args['ids'] ) ? $args['ids'] : [];
		$ids = \array_values(
			\array_filter(
				\array_map( static fn( $id ): int => \absint( (int) $id ), $ids ),
				static fn( int $id ): bool => $id > 0
			)
		);
		if ( empty( $ids ) ) {
			return new \WP_Error( 'rest_invalid_param', \__( 'ids cannot be empty', 'power-course' ), [ 'status' => 400 ] );
		}

		// === 前置（參數）：confirm 必須為 true（二次確認）===
		$confirm = ! empty( $args['confirm'] ) && \in_array( $args['confirm'], [ true, 'true', '1', 1 ], true );
		if ( true !== $confirm ) {
			return new \WP_Error( 'rest_invalid_param', \__( 'confirm must be true', 'power-course' ), [ 'status' => 400 ] );
		}

		$deleted_ids         = [];
		$affected_user_count = 0;

		foreach ( $ids as $id ) {
			try {
				$result               = Crud::delete( $id, true );
				$affected_user_count += (int) $result['affected_user_count'];
				$deleted_ids[]        = $id;
			} catch ( \RuntimeException $e ) {
				return $this->to_wp_error( $e );
			}
		}

		return new \WP_REST_Response(
			[
				'success'             => true,
				'deleted_ids'         => $deleted_ids,
				'affected_user_count' => $affected_user_count,
			]
		);
	}

	/**
	 * 取得單一權限包
	 *
	 * 對映 Edit 頁 useForm(edit) 的 getOne：GET /access-passes/{id}。
	 * 回傳 Model::to_array()（scope / limit / status / term_ids / course_ids），
	 * 供表單回填；找不到（或非 pc_access_pass）時回 404。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_access_passes_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id          = (int) $request['id'];
		$access_pass = AccessPass::instance( $id );

		if ( null === $access_pass ) {
			return new \WP_Error( 'not_found', \__( 'Access pass not found', 'power-course' ), [ 'status' => 404 ] );
		}

		return new \WP_REST_Response( $access_pass->to_array() );
	}

	/**
	 * 更新權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function put_access_passes_with_id_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id   = (int) $request['id'];
		$args = $this->get_body_args( $request );

		try {
			$updated_id = Crud::update( $id, $args );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => \__( 'Access pass updated', 'power-course' ),
				'data'    => [ 'id' => $updated_id ],
			]
		);
	}

	/**
	 * 停用權限包
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_disable_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id = (int) $request['id'];

		try {
			$disabled_id = Crud::disable( $id );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'disable_success',
				'message' => \__( 'Access pass disabled', 'power-course' ),
				'data'    => [ 'id' => $disabled_id ],
			]
		);
	}

	/**
	 * 掛載權限包到商品
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_access_passes_with_id_attach_callback( $request ) { // phpcs:ignore
		\nocache_headers();

		$id         = (int) $request['id'];
		$args       = $this->get_body_args( $request );
		$product_id = isset( $args['product_id'] ) ? \absint( (int) $args['product_id'] ) : 0;

		try {
			Crud::attach_to_product( $id, $product_id );
		} catch ( \RuntimeException $e ) {
			return $this->to_wp_error( $e );
		}

		return new \WP_REST_Response(
			[
				'success'        => true,
				'product_id'     => $product_id,
				'access_pass_id' => $id,
			]
		);
	}

	/**
	 * 將 Service 拋出的 RuntimeException 轉成對映 HTTP 狀態碼的 WP_Error
	 *
	 * 對映規則（對照 api.yml /access-passes 段）：
	 *   - 「不存在」訊息 → 404 not_found
	 *   - 「停用 / 不可掛」訊息 → 403 rest_forbidden（disabled 不可掛新商品）
	 *   - 其餘參數錯誤 → 400 rest_invalid_param
	 *
	 * @param \RuntimeException $e 例外
	 * @return \WP_Error
	 */
	private function to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( false !== \mb_strpos( $message, '不存在' ) ) {
			return new \WP_Error( 'not_found', $message, [ 'status' => 404 ] );
		}
		if ( false !== \mb_strpos( $message, '停用' ) ) {
			return new \WP_Error( 'rest_forbidden', $message, [ 'status' => 403 ] );
		}
		return new \WP_Error( 'rest_invalid_param', $message, [ 'status' => 400 ] );
	}

	/**
	 * 取得並清洗 request body 參數
	 *
	 * 接受 JSON body 或 form-encoded body，自動 sanitize_text_field_deep。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return array<string, mixed>
	 */
	private function get_body_args( $request ): array {
		$body = $request->get_json_params();
		if ( ! \is_array( $body ) || empty( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! \is_array( $body ) ) {
			$body = [];
		}

		/** @var array<string, mixed> $sanitized */
		$sanitized = WP::sanitize_text_field_deep( $body, false );
		return $sanitized;
	}
}
