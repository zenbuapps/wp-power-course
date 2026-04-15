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

		$body_params = $request->get_json_params();

		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		try {
			/** @var array{from_tree: array<int, array<string, mixed>>, to_tree: array<int, array<string, mixed>>} $body_params */
			ChapterCrud::sort( $body_params );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'sort_failed', $e->getMessage(), [ 'status' => 400 ] );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'sort_success',
				'message' => '修改排序成功',
				'data'    => null,
			]
		);
	}

	/**
	 * Patch Chapter callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 * @phpstan-ignore-next-line
	 */
	public function post_chapters_with_id_callback( $request ): \WP_REST_Response|\WP_Error {

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
				'message' => '更新成功',
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
		$id = (int) $request['id'];
		ChapterCrud::delete( $id );

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => '刪除成功',
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
					'message' => '找不到課程',
				],
				400
			);
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

		if ( $target_finished ) {
			$message = $success
				? "單元 {$title} 已標示為完成！"
				: "單元 {$title} 標示為未完成時出錯了！";
		} else {
			$message = $success
				? "單元 {$title} 已標示為未完成！"
				: "單元 {$title} 標示為未完成時出錯了！";
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
				throw new \Exception(__('刪除章節資料失敗', 'power-course') . ' ids:' . implode(', ', $failed_ids));
			}

			return new \WP_REST_Response(
				[
					'code'    => 'delete_success',
					'message' => '刪除成功',
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
