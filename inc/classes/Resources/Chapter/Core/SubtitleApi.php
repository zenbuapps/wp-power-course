<?php
/**
 * 字幕 REST API 端點.
 * 已與章節解耦，支援多種 post type 與 video slot.
 *
 * @package J7\PowerCourse\Resources\Chapter\Core
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Core;

use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * 字幕 REST API.
 * 提供字幕的 CRUD 端點: GET 列表、POST 上傳、DELETE 刪除.
 * 路由格式: posts/{id}/subtitles/{videoSlot}
 */
final class SubtitleApi extends ApiBase {
	use SingletonTrait;

	/**
	 * REST API 命名空間.
	 *
	 * @var string
	 */
	protected $namespace = 'power-course';

	/**
	 * API 路由定義.
	 * 由於 videoSlot 路徑參數不被 ApiBase 自動轉換，使用明確的 callback.
	 *
	 * @var array{endpoint:string,method:string,permission_callback?:callable|null,callback?:callable|null}[]
	 */
	protected $apis = [];

	/**
	 * Constructor.
	 * 手動註冊所有路由（因含非標準路徑參數，ApiBase 的自動命名無法正確處理）.
	 */
	public function __construct() {
		parent::__construct();

		\add_action(
			'rest_api_init',
			function () {
				// GET 取得字幕列表.
				\register_rest_route(
					$this->namespace,
					'posts/(?P<id>\d+)/subtitles/(?P<videoSlot>[a-z_]+)',
					[
						'methods'             => 'GET',
						'callback'            => function ( \WP_REST_Request $request ) {
							return $this->try( [ $this, 'get_subtitles_callback' ], $request );
						},
						'permission_callback' => [ $this, 'permission_callback' ],
					]
				);

				// POST 上傳字幕.
				\register_rest_route(
					$this->namespace,
					'posts/(?P<id>\d+)/subtitles/(?P<videoSlot>[a-z_]+)',
					[
						'methods'             => 'POST',
						'callback'            => function ( \WP_REST_Request $request ) {
							return $this->try( [ $this, 'upload_subtitle_callback' ], $request );
						},
						'permission_callback' => [ $this, 'permission_callback' ],
					]
				);

				// DELETE 刪除字幕（含 srclang 路徑參數）.
				\register_rest_route(
					$this->namespace,
					'posts/(?P<id>\d+)/subtitles/(?P<videoSlot>[a-z_]+)/(?P<srclang>[a-zA-Z-]+)',
					[
						'methods'             => 'DELETE',
						'callback'            => function ( \WP_REST_Request $request ) {
							return $this->try( [ $this, 'delete_subtitle_callback' ], $request );
						},
						'permission_callback' => [ $this, 'permission_callback' ],
					]
				);
			}
		);

		\add_filter( 'upload_mimes', [ $this, 'add_subtitle_mime_types' ] );
	}

	/**
	 * GET 取得字幕列表.
	 *
	 * @param \WP_REST_Request $request REST 請求物件.
	 * @return \WP_REST_Response 字幕列表回應.
	 */
	public function get_subtitles_callback( \WP_REST_Request $request ): \WP_REST_Response {
		// 【Issue #216 Bug #1b】注入 nocache 標頭，防止邊緣快取
		\nocache_headers();

		$post_id    = (int) $request['id'];
		$video_slot = (string) $request['videoSlot'];

		try {
			$service   = new SubtitleService();
			$subtitles = $service->get_subtitles( $post_id, $video_slot );

			return new \WP_REST_Response( $subtitles, 200 );
		} catch ( \RuntimeException $e ) {
			return $this->handle_runtime_exception( $e );
		}
	}

	/**
	 * POST 上傳字幕.
	 *
	 * @param \WP_REST_Request $request REST 請求物件.
	 * @return \WP_REST_Response 上傳結果回應.
	 */
	public function upload_subtitle_callback( \WP_REST_Request $request ): \WP_REST_Response {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$post_id     = (int) $request['id'];
		$video_slot  = (string) $request['videoSlot'];
		$file_params = $request->get_file_params();
		$file        = $file_params['file'] ?? null;
		$srclang     = (string) $request->get_param( 'srclang' );

		if ( ! $file || empty( $file['tmp_name'] ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'missing_file',
					'message' => esc_html__( 'Subtitle file is required', 'power-course' ),
				],
				400
			);
		}

		try {
			$service = new SubtitleService();
			$track   = $service->upload_subtitle(
				$post_id,
				(string) $file['tmp_name'],
				(string) $file['name'],
				$srclang,
				$video_slot
			);

			return new \WP_REST_Response( $track, 201 );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_argument',
					'message' => $e->getMessage(),
				],
				400
			);
		} catch ( \RuntimeException $e ) {
			return $this->handle_runtime_exception( $e );
		}
	}

	/**
	 * DELETE 刪除字幕.
	 *
	 * @param \WP_REST_Request $request REST 請求物件.
	 * @return \WP_REST_Response 刪除結果回應.
	 */
	public function delete_subtitle_callback( \WP_REST_Request $request ): \WP_REST_Response {
		// 【Issue #216 Bug #1b】注入 nocache 標頭
		\nocache_headers();

		$post_id    = (int) $request['id'];
		$video_slot = (string) $request['videoSlot'];
		$srclang    = (string) $request['srclang'];

		try {
			$service = new SubtitleService();
			$service->delete_subtitle( $post_id, $srclang, $video_slot );

			return new \WP_REST_Response(
				[ 'deleted' => true ],
				200
			);
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_argument',
					'message' => $e->getMessage(),
				],
				400
			);
		} catch ( \RuntimeException $e ) {
			return $this->handle_runtime_exception( $e );
		}
	}

	/**
	 * 處理 RuntimeException 並回傳對應的 REST 回應.
	 * 根據錯誤訊息前綴判斷錯誤類型與 HTTP 狀態碼.
	 *
	 * @param \RuntimeException $e 例外物件.
	 * @return \WP_REST_Response REST 回應.
	 */
	private function handle_runtime_exception( \RuntimeException $e ): \WP_REST_Response {
		$message = $e->getMessage();

		// 根據錯誤訊息前綴判斷錯誤碼與狀態碼.
		if ( str_contains( $message, 'invalid_video_slot' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_video_slot',
					'message' => $message,
				],
				400
			);
		}

		if ( str_contains( $message, 'post_not_found' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'post_not_found',
					'message' => $message,
				],
				404
			);
		}

		if ( str_contains( $message, 'subtitle_exists' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'subtitle_exists',
					'message' => $message,
				],
				422
			);
		}

		if ( str_contains( $message, 'subtitle_not_found' ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'not_found',
					'message' => $message,
				],
				404
			);
		}

		return new \WP_REST_Response(
			[
				'code'    => 'upload_error',
				'message' => $message,
			],
			500
		);
	}

	/**
	 * 註冊字幕檔案的 MIME 類型.
	 *
	 * @param array<string, string> $mimes 現有的 MIME 類型列表.
	 * @return array<string, string> 擴充後的 MIME 類型列表.
	 */
	public function add_subtitle_mime_types( array $mimes ): array {
		$mimes['srt'] = 'application/x-subrip';
		$mimes['vtt'] = 'text/vtt';

		return $mimes;
	}
}
