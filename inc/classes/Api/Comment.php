<?php
/**
 *  Comment API
 */

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Comment\Service\Query as CommentQuery;
use J7\PowerCourse\Resources\Comment\Service\Crud as CommentCrud;
/**
 * Class Api
 */
final class Comment {
	use \J7\WpUtils\Traits\SingletonTrait;
	use \J7\WpUtils\Traits\ApiRegisterTrait;

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_api_products' ] );
	}

	/**
	 * Get APIs
	 *
	 * @return array{endpoint: string, method: string, permission_callback: ?callable}[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected function get_apis(): array {
		return [
			[
				'endpoint'            => 'comments',
				'method'              => 'get',
				'permission_callback' => '__return_true',
			],
			[
				'endpoint'            => 'comments',
				'method'              => 'post',
				'permission_callback' => '__return_true',
			],
			[
				'endpoint'            => 'comments/(?P<id>\d+)/toggle-approved',
				'method'              => 'post',
				'permission_callback' => fn() => \current_user_can( 'manage_woocommerce' ),
			],
			[
				'endpoint'            => 'comments/(?P<id>\d+)',
				'method'              => 'delete',
				'permission_callback' => fn() => \current_user_can( 'manage_woocommerce' ),
			],
		];
	}

	/**
	 * Register products API
	 *
	 * @return void
	 */
	public function register_api_products(): void {
		$this->register_apis(
		$this->get_apis(),
		Plugin::$kebab,
		fn() => \current_user_can( 'manage_woocommerce' ),
		);
	}

	/**
	 * Get comments callback
	 * 通用的留言查詢
	 *
	 * @param \WP_REST_Request $request Request.
	 * $params
	 *  - post_id 哪一篇 POST 的留言
	 *  - meta_value
	 * - count_total 是否要計算總數
	 *
	 * @return \WP_REST_Response
	 */
	public function get_comments_callback( $request ): \WP_REST_Response {

		$params = $request->get_query_params();

		$result = CommentQuery::list(
			$params,
			fn( \WP_Comment $comment, int $depth, array $args ): array => $this->format_comment_details( $comment, $depth, $args )
		);

		$response = new \WP_REST_Response( $result['comments'] );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $result['total'] );
		$response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );
		$response->header( 'X-WP-CurrentPage', (string) $result['current_page'] );
		$response->header( 'X-WP-PageSize', (string) $result['page_size'] );

		return $response;
	}

	/**
	 * 新增留言
	 *
	 * @param \WP_REST_Request $request 包含新增留言所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function post_comments_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_body_params();

		$result = CommentCrud::create( $body_params );

		return new \WP_REST_Response( $result, $result['code'] );
	}

	/**
	 * 新增留言
	 *
	 * @param \WP_REST_Request $request 包含新增留言所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function post_comments_with_id_toggle_approved_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$id = (int) $request['id'];

		$result = CommentCrud::toggle_approved( $id );

		return new \WP_REST_Response( $result, $result['code'] );
	}

	/**
	 * 刪除留言
	 *
	 * @param \WP_REST_Request $request 包含新增留言所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function delete_comments_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$id = (int) $request['id'];

		$comment = \get_comment($id);

		if (!( $comment instanceof \WP_Comment )) {
			return new \WP_REST_Response(
			[
				'code'    => 400,
				'message' => __( 'Comment not found', 'power-course' ),
				'data'    => null,
			],
			400
			);
		}

		$comment_approved = $comment->comment_approved; // '0', '1', 'spam', 'trash'

		if ('trash' === $comment_approved) {
			return new \WP_REST_Response(
				[
					'code'    => 400,
					'message' => __( 'Comment is already in trash', 'power-course' ),
					'data'    => [
						'id' => (string) $id,
					],
				],
				400
				);
		}

		// 取得所有 Children id
		$children_ids = self::get_all_children_ids($id);

		$all_comment_ids = [ $id, ...$children_ids ];

		foreach ($all_comment_ids as $comment_id) {
			$result = \wp_update_comment(
				[
					'comment_ID'       => $comment_id,
					'comment_approved' => 'trash',
				],
				true
			);

			if (\is_wp_error($result)) {
				return new \WP_REST_Response(
					[
						'code'    => 400,
						'message' => $result->get_error_message(),
						'data'    => [
							'id' => (string) $comment_id,
						],
					],
					400
					);
			}
		}

		return new \WP_REST_Response(
			[
				'code'    => 200,
				'message' => __( 'Comment deleted successfully', 'power-course' ),
				'data'    => [
					'ids' => $all_comment_ids,
				],
			],
			200
			);
	}
	/**
	 * Format comment details
	 *
	 * @param \WP_Comment                                                                                    $comment  Comment.
	 * @param int                                                                                            $depth        Depth.
	 * @param array{format?: string, status?: string, hierarchical?: string, orderby?: array<string>|string} $args         Args.
	 *
	 * @return array<string, mixed>
	 */
	public function format_comment_details( \WP_Comment $comment, int $depth = 0, array $args = [] ): array {

		$comment_id        = (int) $comment->comment_ID;
		$user_id           = (int) $comment->user_id;
		$user_avatar_url   = \get_user_meta($user_id, 'user_avatar_url', true);
		$user_avatar_url   = (bool) $user_avatar_url ? $user_avatar_url : \get_avatar_url( $user_id  );
		$user              = [
			'id'         => (string) $user_id,
			'name'       => \get_the_author_meta('display_name', $user_id) ?: __( 'Guest', 'power-course' ),
			'avatar_url' => $user_avatar_url,
			'email'      => $comment->comment_author_email,
		];
		$rating            = \get_comment_meta($comment_id, 'rating', true);
		$comment_approved  = $comment->comment_approved; // '0', '1', 'spam', 'trash'
		$comment_author_IP = $comment->comment_author_IP; //phpcs:ignore
		$comment_content   = \wpautop($comment->comment_content);
		$comment_date      = $comment->comment_date;

		$comment->populated_children(false); // 要關閉這個才會有 children

		$children = $comment->get_children($args);

		$children_array = $children ? array_values(array_map(fn( $child ) => $this->format_comment_details($child, $depth + 1, $args), $children)) : [];

		$base_array = [
			'id'                => (string) $comment_id,
			'depth'             => (int) $depth,
			'user'              => $user,
			'rating'            => (int) $rating,
			'comment_content'   => $comment_content,
			'comment_date'      => $comment_date,
			'comment_approved'  => $comment_approved,
			'comment_author_IP' => $comment_author_IP, //phpcs:ignore
			'can_reply'         => true,
			'can_delete'        => \current_user_can( 'edit_comment', $comment_id ),
			'children'          => $children_array,
		];

		return $base_array;
	}

	/**
	 * Get all children ids (flat)
	 *
	 * @param int $comment_id Comment ID.
	 * @return array<int>
	 */
	public static function get_all_children_ids( int $comment_id ): array {
		$children_ids = [];
		/** @var \WP_Comment[] $children */
		$children = \get_comments(
			[
				'parent'       => $comment_id,
				'hierarchical' => 'threaded',
			]
		);
		foreach ($children as $child) {
			$child_id       = (int) $child->comment_ID;
			$children_ids[] = $child_id;
			$children_ids   = array_merge($children_ids, self::get_all_children_ids($child_id));
		}
		return $children_ids;
	}
}
