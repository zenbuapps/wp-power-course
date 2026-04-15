<?php
/**
 * Course CRUD Service — 課程寫入服務
 *
 * 封裝課程的 create / update / delete / duplicate 業務邏輯，
 * 供 REST callback 與 MCP tool 共用。為避免大規模重構，
 * 透過組裝 WP_REST_Request 呼叫既有 Api\Course 的 public callback，
 * 確保既有行為與新呼叫路徑一致。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Api\Course as CourseApi;
use J7\PowerCourse\Utils\Duplicate as DuplicateUtil;

/**
 * Class Crud
 * 課程寫入（create / update / delete / duplicate）服務
 */
final class Crud {

	/**
	 * 建立新課程
	 *
	 * @param array<string, mixed> $payload 課程欄位資料（含 name / regular_price / meta_data 等）
	 * @return array{id: int}|\WP_Error 成功時回傳新課程 ID，失敗時回傳 WP_Error
	 */
	public static function create( array $payload ): array|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/courses' );
		$request->set_body_params( $payload );

		$response = CourseApi::instance()->post_courses_callback( $request );
		return self::parse_response( $response, 'id' );
	}

	/**
	 * 更新既有課程
	 *
	 * @param int                  $id      課程 ID
	 * @param array<string, mixed> $payload 要更新的欄位
	 * @return array{id: int}|\WP_Error 成功時回傳課程 ID，失敗時回傳 WP_Error
	 */
	public static function update( int $id, array $payload ): array|\WP_Error {
		if ( $id <= 0 ) {
			return new \WP_Error(
				'course_invalid_id',
				__( 'id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! \wc_get_product( $id ) instanceof \WC_Product ) {
			return new \WP_Error(
				'course_not_found',
				__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$request = new \WP_REST_Request( 'POST', "/power-course/v2/courses/{$id}" );
		$request->set_url_params( [ 'id' => (string) $id ] );
		$request->set_body_params( $payload );

		$response = CourseApi::instance()->post_courses_with_id_callback( $request );
		return self::parse_response( $response, 'id' );
	}

	/**
	 * 刪除課程
	 *
	 * @param int $id 課程 ID
	 * @return array{id: int}|\WP_Error 成功時回傳被刪除課程 ID，失敗時回傳 WP_Error
	 */
	public static function delete( int $id ): array|\WP_Error {
		if ( $id <= 0 ) {
			return new \WP_Error(
				'course_invalid_id',
				__( 'id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'course_not_found',
				__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$result = \wp_delete_post( $id, true );
		if ( ! $result instanceof \WP_Post && false === $result ) {
			return new \WP_Error(
				'course_delete_failed',
				__( '刪除課程失敗', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		return [ 'id' => $id ];
	}

	/**
	 * 複製課程
	 *
	 * 使用 J7\PowerCourse\Utils\Duplicate::process() 複製課程及其章節、銷售方案。
	 *
	 * @param int $id 原課程 ID
	 * @return array{id: int}|\WP_Error 成功時回傳複製後的新課程 ID，失敗時回傳 WP_Error
	 */
	public static function duplicate( int $id ): array|\WP_Error {
		if ( $id <= 0 ) {
			return new \WP_Error(
				'course_invalid_id',
				__( 'id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error(
				'course_not_found',
				__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		try {
			$duplicator = new DuplicateUtil();
			$new_id     = $duplicator->process( $id );
		} catch ( \Throwable $th ) {
			return new \WP_Error(
				'course_duplicate_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		if ( $new_id <= 0 ) {
			return new \WP_Error(
				'course_duplicate_failed',
				__( '複製課程失敗', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		return [ 'id' => $new_id ];
	}

	/**
	 * 解析 REST Response，統一轉為 array{id:int} 或 WP_Error
	 *
	 * @param \WP_REST_Response|\WP_Error $response REST 回應或錯誤
	 * @param string                      $id_key   要從 data 擷取的欄位名稱
	 * @return array{id: int}|\WP_Error
	 */
	private static function parse_response( \WP_REST_Response|\WP_Error $response, string $id_key ): array|\WP_Error {
		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$status = $response->get_status();
		if ( $status >= 400 ) {
			$data    = (array) $response->get_data();
			$message = '';
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			}
			$code = '';
			if ( isset( $data['code'] ) && is_string( $data['code'] ) ) {
				$code = $data['code'];
			}
			return new \WP_Error(
				'' !== $code ? $code : 'course_request_failed',
				'' !== $message ? $message : __( '課程操作失敗', 'power-course' ),
				[ 'status' => $status ]
			);
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['data'] ) && is_array( $data['data'] ) && isset( $data['data'][ $id_key ] ) ) {
			return [ 'id' => (int) $data['data'][ $id_key ] ];
		}

		return [ 'id' => 0 ];
	}
}
