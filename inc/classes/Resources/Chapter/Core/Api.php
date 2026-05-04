<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Chapter\Core;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\General;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Model\Chapter;
use J7\Powerhouse\Utils\Base as PowerhouseBase;
use J7\PowerCourse\Utils\LinearViewing;
use J7\PowerCourse\Resources\Chapter\Service\Query as ChapterQuery;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;
use J7\PowerCourse\Resources\Chapter\Service\Progress as ChapterProgress;



/** Chapter Api */
final class Api extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/** @var array{endpoint:string,method:string,permission_callback: callable|null }[] APIs */
	protected $apis = [
		[
			'endpoint'            => 'chapters',
			'method'              => 'get',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'chapters',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'chapters',
			'method'              => 'delete',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'chapters/sort',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'chapters/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'toggle-finish-chapters/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => 'is_user_logged_in',
		],
	];

	/**
	 * Get chapters callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function get_chapters_callback( $request ) { // phpcs:ignore
		// 【Issue #216 Bug #1b】注入 nocache 標頭，防止 LiteSpeed/Cloudflare 等邊緣快取
		\nocache_headers();

		$params = $request->get_query_params();

		$params = WP::sanitize_text_field_deep( $params, false );

		/** @var array<string, mixed> $params */
		$chapters = ChapterQuery::list( $params );

		$response = new \WP_REST_Response( $chapters );

		return $response;
	}


	/**
	 * 處理並分離產品資訊
	 *
	 * 根據請求分離產品資訊，並處理描述欄位。
	 *
	 * @param \WP_REST_Request $request 包含產品資訊的請求對象。
	 * @throws \Exception 當找不到商品時拋出異常。.
	 * @return array{data: array<string, mixed>, meta_data: array<string, mixed>} 包含產品對象、資料和元數據的陣列。
	 */
	private function separator( $request ): array {
		$body_params = $request->get_body_params();
		$file_params = $request->get_file_params();

		$body_params = ChapterUtils::converter( $body_params );

		$skip_keys = [
			'chapter_video',
			'post_content',
			'post_name',
		];
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep($body_params, true, $skip_keys);

		// 將 '[]' 轉為 []
		$body_params = General::parse( $body_params );

		$separated_data = WP::separator( $body_params, 'post', $file_params['files'] ?? [] );

		return $separated_data;
	}

	/**
	 * Post Chapter callback
	 * 創建章節
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_chapters_callback( $request ): \WP_REST_Response|\WP_Error {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = $this->separator( $request );

		$qty = (int) ( $meta_data['qty'] ?? 1 );
		unset($meta_data['qty']);

		$success_ids = [];

		for ($i = 0; $i < $qty; $i++) {
			try {
				$post_id       = ChapterCrud::create( $data, $meta_data );
				$success_ids[] = $post_id;
			} catch ( \RuntimeException $e ) {
				// 單筆失敗，保留行為：忽略該筆繼續下一筆
				continue;
			}
		}

		return new \WP_REST_Response(
			$success_ids
		);
	}

	/**
	 * Post Chapter Sort callback
	 * 處理排序
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_chapters_sort_callback( $request ): \WP_REST_Response|\WP_Error {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$body_params = $request->get_json_params();

		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		try {
			/** @var array{from_tree: array<int, array<string, mixed>>, to_tree: array<int, array<string, mixed>>} $body_params */
			ChapterCrud::sort( $body_params );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'sort_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		// 排序成功後，檢查線性觀看並產生警告
		$warning    = null;
		$sort_course_id = self::get_course_id_from_sort_params( $body_params );
		if ( $sort_course_id && LinearViewing::is_enabled( $sort_course_id ) ) {
			$warning = esc_html__(
				'This course has sequential learning enabled. Changing the chapter order will affect students\' chapter unlock status.',
				'power-course'
			);
		}

		return new \WP_REST_Response(
			[
				'code'    => 'sort_success',
				'message' => esc_html__( 'Sort order updated successfully', 'power-course' ),
				'data'    => null,
				'warning' => $warning,
			]
		);
	}

	/**
	 * 從排序 body 參數中提取 course_id
	 *
	 * 從 from_tree 的第一個元素取得 parent_course_id meta。
	 *
	 * @param array<string, mixed> $body_params 排序參數.
	 * @return int|null
	 */
	private static function get_course_id_from_sort_params( array $body_params ): ?int {
		$from_tree = $body_params['from_tree'] ?? [];
		if ( empty( $from_tree ) ) {
			return null;
		}

		$first_node = $from_tree[0];
		$chapter_id = (int) ( $first_node['id'] ?? 0 );
		if ( ! $chapter_id ) {
			return null;
		}

		// 從 depth=0 的章節取得 parent_course_id（即課程 ID）
		$depth = (int) ( $first_node['depth'] ?? 0 );
		if ( 0 === $depth ) {
			// 頂層章節的 parent_id 就是 course_id
			$course_id = (int) ( $first_node['parent_id'] ?? 0 );
			return $course_id ?: null;
		}

		// 非頂層章節，從 meta 取得
		$course_id = ChapterUtils::get_course_id( $chapter_id );
		return $course_id;
	}

	/**
	 * Patch Chapter callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_chapters_with_id_callback( $request ): \WP_REST_Response|\WP_Error {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$id = (int) $request['id'];

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = $this->separator( $request );

		try {
			$updated_id = ChapterCrud::update( $id, $data, $meta_data );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'update_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => esc_html__( 'Updated successfully', 'power-course' ),
				'data'    => [
					'id' => $updated_id,
				],
			]
		);
	}

	/**
	 * Delete Chapter callback
	 * 刪除章節
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_chapters_with_id_callback( $request ): \WP_REST_Response {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$id = (int) $request['id'];
		ChapterCrud::delete( $id );

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => esc_html__( 'Deleted successfully', 'power-course' ),
				'data'    => [
					'id' => $id,
				],
			]
		);
	}

	/**
	 * Patch Chapter callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 *
	 * @phpstan-ignore-next-line
	 */
	public function post_toggle_finish_chapters_with_id_callback( $request ): \WP_REST_Response|\WP_Error {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$chapter_id = (int) $request['id'];
		// @phpstan-ignore-next-line
		$body_params = $request->get_body_params() ?? [];
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		/** @var array<string, mixed> $body_params */
		WP::include_required_params( $body_params, [ 'course_id' ] );

		$course_id = (int) $body_params['course_id'];
		$user_id   = \get_current_user_id();

		$chapter                  = new Chapter( $chapter_id, (int) $user_id );
		$is_this_chapter_finished = (bool) $chapter->finished_at;
		$title                    = \get_the_title( $chapter_id);
		$product                  = \wc_get_product( $course_id );

		if (!$product) {
			return new \WP_REST_Response(
				[
					'code'    => '400',
					'message' => esc_html__( 'Course not found', 'power-course' ),
				],
				400
			);
		}

		// 線性觀看驗證：鎖定的章節不允許標記為完成（取消完成不受限）
		if ( ! $is_this_chapter_finished && LinearViewing::is_enabled( $course_id ) ) {
			if ( ! LinearViewing::is_exempt( $user_id ) && LinearViewing::is_chapter_locked( $chapter_id, $course_id, $user_id ) ) {
				return new \WP_REST_Response(
					[
						'code'    => '403',
						'message' => esc_html__( 'This chapter is not yet unlocked. Please complete the previous chapters first.', 'power-course' ),
					],
					403
				);
			}
		}

		// 目標狀態：目前已完成 → 要改成未完成；目前未完成 → 要改成已完成
		$target_finished = ! $is_this_chapter_finished;

		try {
			$success = ChapterProgress::toggle_finish( $chapter_id, $user_id, $target_finished );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response(
				[
					'code'    => '400',
					'message' => $e->getMessage(),
				],
				400
			);
		}

		$progress = CourseUtils::get_course_progress( $product );

		// 一次性計算線性觀看狀態，避免重複 DB 查詢
		$linear_status = LinearViewing::is_enabled( $course_id )
		? LinearViewing::get_unlock_status( $course_id, $user_id )
		: null;

		// 為當前處於解鎖狀態的章節產生 icon_html，供前端即時替換鎖頭
		$unlocked_chapter_icons = [];
		if ( $linear_status && ! empty( $linear_status['unlocked_ids'] ) ) {
			foreach ( $linear_status['unlocked_ids'] as $uid ) {
				$unlocked_chapter_icons[ (string) $uid ] = ChapterUtils::get_chapter_icon_html( (int) $uid );
			}
		}

		if ( $target_finished ) {
			$message = $success
			? sprintf(
					/* translators: %s: 單元名稱 */
					esc_html__( 'Lesson "%s" marked as finished', 'power-course' ),
					$title
				)
			: sprintf(
					/* translators: %s: 單元名稱 */
					esc_html__( 'Failed to mark lesson "%s" as finished', 'power-course' ),
					$title
				);
		} else {
			$message = $success
			? sprintf(
					/* translators: %s: 單元名稱 */
					esc_html__( 'Lesson "%s" marked as unfinished', 'power-course' ),
					$title
				)
			: sprintf(
					/* translators: %s: 單元名稱 */
					esc_html__( 'Failed to mark lesson "%s" as unfinished', 'power-course' ),
					$title
				);
		}

		return new \WP_REST_Response(
			[
				'code'    => $success ? '200' : '400',
				'message' => $message,
				'data'    => [
					'chapter_id'               => $chapter_id,
					'course_id'                => $course_id,
					'is_this_chapter_finished' => $success ? $target_finished : $is_this_chapter_finished,
					'progress'                 => $progress,
					'icon_html'                => ChapterUtils::get_chapter_icon_html( $chapter_id ),
					'unlocked_chapter_ids'     => $linear_status['unlocked_ids'] ?? null,
					'locked_chapter_ids'       => $linear_status['locked_ids'] ?? null,
					'unlocked_chapter_icons'   => $unlocked_chapter_icons,
				],
			],
			$success ? 200 : 400
		);
	}

	/**
	 * 批次刪除章節資料
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @throws \Exception 當刪除章節資料失敗時拋出異常
	 * @phpstan-ignore-next-line
	 */
	public function delete_chapters_callback( $request ): \WP_REST_Response|\WP_Error {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$body_params = $request->get_json_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		/** @var array<string> $ids */
		$ids = (array) $body_params['ids'];

		try {
			$results        = PowerhouseBase::batch_process(
				$ids,
				static function ( int $id ) {
					// 因為 wp_trash_post 有 hook 會遞規刪除子文章，所以這邊要先檢查狀態
					// 不然刪除已經為 trash 的文章，會 return false，誤報為刪除失敗
					$status = \get_post_status( (int) $id );
					if ( $status === 'trash' ) {
						return true;
					}
					return \wp_trash_post( (int) $id );
				}
			);
			$failed_results = array_filter($results, static fn ( $result ) => !$result);

			if ($failed_results) {
				$failed_result_indexes = array_keys($failed_results);
				$failed_ids            = array_map(static fn ( $index ) => $ids[ $index ], $failed_result_indexes);
				throw new \Exception(__('Failed to delete chapter data', 'power-course') . ' ids:' . implode(', ', $failed_ids));
			}

			return new \WP_REST_Response(
				[
					'code'    => 'delete_success',
					'message' => esc_html__( 'Deleted successfully', 'power-course' ),
					'data'    => $ids,
				]
			);
		} catch (\Exception $e) {
			return new \WP_REST_Response(
				[
					'code'    => 'delete_failed',
					'message' => $e->getMessage(),
					'data'    => $ids,
				],
				400
			);
		}
	}
}
