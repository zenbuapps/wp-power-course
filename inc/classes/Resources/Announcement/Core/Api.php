<?php
/**
 * Announcement REST API
 *
 * 註冊 power-course/announcements/* 端點，提供 CRUD 與公開列表查詢。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Core;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Resources\Announcement\Service\Crud;
use J7\PowerCourse\Resources\Announcement\Service\Query;

/**
 * Class Api
 */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint:string,method:string,permission_callback: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'announcements',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements',
			'method'              => 'delete',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements/public',
			'method'              => 'get',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'announcements/(?P<id>\d+)',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements/(?P<id>\d+)',
			'method'              => 'delete',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'announcements/(?P<id>\d+)/restore',
			'method'              => 'post',
			'permission_callback' => null,
		],
	];

	/**
	 * 後台公告列表
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response
	 */
	public function get_announcements_callback( $request ): \WP_REST_Response { // phpcs:ignore
		$params = $request->get_query_params();
		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$list = Query::list( $params );
		return new \WP_REST_Response( $list );
	}

	/**
	 * 取得單一公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_announcements_with_id_callback( $request ) { // phpcs:ignore
		$id   = (int) $request['id'];
		$data = Query::get( $id );
		if ( null === $data ) {
			return new \WP_Error( 'not_found', __( 'Announcement does not exist', 'power-course' ), [ 'status' => 404 ] );
		}
		return new \WP_REST_Response( $data );
	}

	/**
	 * 前台公開列表
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response
	 */
	public function get_announcements_public_callback( $request ): \WP_REST_Response { // phpcs:ignore
		$params    = $request->get_query_params();
		$course_id = isset( $params['course_id'] ) ? (int) $params['course_id'] : 0;
		$user_id   = (int) \get_current_user_id();

		$list = Query::list_public( $course_id, $user_id );
		return new \WP_REST_Response( $list );
	}

	/**
	 * 建立公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_announcements_callback( $request ) { // phpcs:ignore
		[ $data, $meta ] = $this->extract_data_and_meta( $request );

		try {
			$id = Crud::create( $data, $meta );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'create_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'create_success',
				'message' => __( 'Announcement created', 'power-course' ),
				'data'    => [ 'id' => $id ],
			]
		);
	}

	/**
	 * 更新公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_announcements_with_id_callback( $request ) { // phpcs:ignore
		$id              = (int) $request['id'];
		[ $data, $meta ] = $this->extract_data_and_meta( $request );

		try {
			$updated_id = Crud::update( $id, $data, $meta );
		} catch ( \RuntimeException $e ) {
			$message = $e->getMessage();
			$code    = '公告不存在' === $message ? 'not_found' : 'update_failed';
			$status  = '公告不存在' === $message ? 404 : 400;
			return new \WP_Error( $code, $message, [ 'status' => $status ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => __( 'Announcement updated', 'power-course' ),
				'data'    => [ 'id' => $updated_id ],
			]
		);
	}

	/**
	 * 刪除單一公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_announcements_with_id_callback( $request ) { // phpcs:ignore
		$id    = (int) $request['id'];
		$force = filter_var( $request->get_param( 'force' ), FILTER_VALIDATE_BOOLEAN );

		try {
			$success = Crud::delete( $id, $force );
		} catch ( \RuntimeException $e ) {
			$status = '公告不存在' === $e->getMessage() ? 404 : 400;
			return new \WP_Error( 'delete_failed', $e->getMessage(), [ 'status' => $status ] );
		}

		if ( ! $success ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete announcement', 'power-course' ), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => __( 'Announcement deleted', 'power-course' ),
				'data'    => [ 'id' => $id ],
			]
		);
	}

	/**
	 * 批次刪除公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_announcements_callback( $request ) { // phpcs:ignore
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : [];
		$ids   = isset( $body['ids'] ) ? (array) $body['ids'] : [];
		$force = ! empty( $body['force'] );

		try {
			$result = Crud::delete_many( $ids, $force );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'delete_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => __( 'Announcements deleted', 'power-course' ),
				'data'    => $result,
			]
		);
	}

	/**
	 * 還原已 trash 的公告
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_announcements_with_id_restore_callback( $request ) { // phpcs:ignore
		$id = (int) $request['id'];
		try {
			$success = Crud::restore( $id );
		} catch ( \RuntimeException $e ) {
			$status = '公告不存在' === $e->getMessage() ? 404 : 400;
			return new \WP_Error( 'restore_failed', $e->getMessage(), [ 'status' => $status ] );
		}

		if ( ! $success ) {
			return new \WP_Error( 'restore_failed', __( 'Failed to restore announcement', 'power-course' ), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'restore_success',
				'message' => __( 'Announcement restored', 'power-course' ),
				'data'    => [ 'id' => $id ],
			]
		);
	}

	/**
	 * 將 request 拆分為 data / meta 兩個陣列
	 *
	 * 接受 JSON body 或 form-encoded body，自動 sanitize_text_field_deep（保留 post_content）。
	 *
	 * @param \WP_REST_Request $request 請求物件
	 * @return array{0: array<string, mixed>, 1: array<string, mixed>}
	 */
	private function extract_data_and_meta( $request ): array {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || empty( $body ) ) {
			$body = $request->get_body_params();
		}
		if ( ! is_array( $body ) ) {
			$body = [];
		}

		$skip_keys = [ 'post_content' ];
		$body      = WP::sanitize_text_field_deep( $body, true, $skip_keys );

		$data_keys = [
			'post_title',
			'post_content',
			'post_status',
			'post_date',
			'post_parent',
			'post_author',
			'parent_course_id',
			'visibility',
			'end_at',
		];
		$data = [];
		$meta = [];
		foreach ( $body as $key => $value ) {
			if ( in_array( $key, $data_keys, true ) ) {
				$data[ $key ] = $value;
			} else {
				$meta[ $key ] = $value;
			}
		}
		return [ $data, $meta ];
	}
}
