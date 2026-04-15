<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\ChapterProgress\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\ChapterProgress\Service\Service as ChapterProgressService;
use J7\Powerhouse\Domains\Post\Utils as PostUtils;

/**
 * 章節續播進度 REST API
 * endpoints:
 *   GET  power-course/v2/chapters/{id}/progress
 *   POST power-course/v2/chapters/{id}/progress
 */
final class Api extends ApiBase {

	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string REST API namespace */
	protected $namespace = 'power-course';

	/**
	 * API 路由定義
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: (callable(): mixed)|null, callback?: (callable(): mixed)|null, schema?: array<string, mixed>|null}>
	 */
	protected $apis = [
		[
			'endpoint'            => 'chapters/(?P<id>\d+)/progress',
			'method'              => 'get',
			'permission_callback' => 'is_user_logged_in',
		],
		[
			'endpoint'            => 'chapters/(?P<id>\d+)/progress',
			'method'              => 'post',
			'permission_callback' => 'is_user_logged_in',
		],
	];

	/**
	 * GET /chapters/{id}/progress
	 * 取得指定章節的播放進度
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_chapters_with_id_progress_callback( $request ): \WP_REST_Response|\WP_Error {
		$chapter_id = (int) $request['id'];
		$user_id    = \get_current_user_id();

		// 驗證章節存在
		$chapter = \get_post( $chapter_id );
		if ( ! $chapter ) {
			return new \WP_Error( 'not_found', 'Chapter not found.', [ 'status' => 404 ] );
		}

		// 取得課程 ID 並驗證授權
		$course_id = (int) PostUtils::get_top_post_id( $chapter_id );
		if ( ! CourseUtils::is_avl( $course_id, $user_id ) ) {
			return new \WP_Error( 'forbidden', 'You do not have access to this course.', [ 'status' => 403 ] );
		}

		$progress = ChapterProgressService::get_progress( $user_id, $chapter_id );

		return new \WP_REST_Response(
			[
				'code' => '200',
				'data' => $progress,
			],
			200
		);
	}

	/**
	 * POST /chapters/{id}/progress
	 * 寫入指定章節的播放進度
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_chapters_with_id_progress_callback( $request ): \WP_REST_Response|\WP_Error {
		$chapter_id = (int) $request['id'];
		$user_id    = \get_current_user_id();

		// 驗證章節存在
		$chapter = \get_post( $chapter_id );
		if ( ! $chapter ) {
			return new \WP_Error( 'not_found', 'Chapter not found.', [ 'status' => 404 ] );
		}

		// 取得 body 參數
		// @phpstan-ignore-next-line
		$body_params = $request->get_body_params() ?? [];
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		// last_position_seconds 必填
		if ( ! isset( $body_params['last_position_seconds'] ) ) {
			return new \WP_Error( 'bad_request', 'Missing required parameter: last_position_seconds.', [ 'status' => 400 ] );
		}

		$raw_seconds = (float) $body_params['last_position_seconds'];

		// 取得課程 ID（前端帶 course_id 僅用於快速 is_avl 檢查；server 端仍會重算）
		$course_id = (int) PostUtils::get_top_post_id( $chapter_id );

		// 驗證課程授權（含到期檢查，is_avl 會檢查 expire_date）
		if ( ! CourseUtils::is_avl( $course_id, $user_id ) ) {
			return new \WP_Error( 'forbidden', 'You do not have access to this course.', [ 'status' => 403 ] );
		}

		try {
			$result = ChapterProgressService::upsert_progress( $user_id, $chapter_id, $raw_seconds );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'invalid_video_type', $e->getMessage(), [ 'status' => 400 ] );
		} catch ( \Throwable $e ) {
			\wc_get_logger()->error(
				sprintf( 'ChapterProgress upsert failed: %s', $e->getMessage() ),
				[ 'source' => 'power-course-chapter-progress' ]
			);
			return new \WP_Error( 'server_error', 'Failed to save progress.', [ 'status' => 500 ] );
		}

		return new \WP_REST_Response(
			[
				'code' => '200',
				'data' => $result,
			],
			200
		);
	}
}
