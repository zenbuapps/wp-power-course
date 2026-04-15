<?php
/**
 *  Comment API
 */

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\PowerCourse\Plugin;
use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Utils\Comment as CommentUtils;
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

		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'number'       => 10,
			'paged'        => 1,
			'post_id'      => 0,
			// 'post_type'    => 'product',
			'type'         => 'review',
			'user_id'      => '',
			'hierarchical' => 'threaded',
			'status'       => 'approve',
		];

		$args = \wp_parse_args(
		$params,
		$default_args,
		);

		if (\current_user_can('manage_woocommerce')) {
			$args['status'] = 'all';
		}

		/**
		 * @var \WP_Comment[] $comments
		 */
		$comments = \get_comments($args);
		$comments = \array_values($comments);

		$formatted_comments = array_map(
			fn( $comment ) => $this->format_comment_details(
			$comment,
			0,
			[
				'status' => $args['status'],
			]
			),
			$comments
			);

		$response = new \WP_REST_Response( $formatted_comments );

		$count_args = array_merge($args, [ 'count' => true ]);
		unset($count_args['paged']);
		/** @var int $total */
		$total       = \get_comments($count_args);
		$total_pages = (int) \floor( $total / (int) $args['number'] ) + 1;
		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );
		$response->header( 'X-WP-CurrentPage', (string) $args['paged'] );
		$response->header( 'X-WP-PageSize', (string) $args['number'] );

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

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		$comment_type = (string) ( $body_params['comment_type'] ?? '' );
		$product_id   = (int) ( $body_params['comment_post_ID'] ?? 0 );
		$product      = \wc_get_product($product_id);
		if (!$product) {
			return new \WP_REST_Response(
			[
				'code'    => 400,
				'message' => __( 'Product not found', 'power-course' ),
				'data'    => null,
			],
			400
			);
		}
		$can_comment  = CommentUtils::can_comment($product, $comment_type);

		if (true !== $can_comment) {
			return new \WP_REST_Response(
			[
				'code'    => 400,
				'message' => $can_comment,
				'data'    => null,
			],
			400
			);
		}

		[
		'data' => $data,
		'meta_data' => $meta_data,
		] = WP::separator( $body_params, 'comment' );

		$data['comment_meta']      = array_merge( (array) ( $data['comment_meta'] ?? [] ), $meta_data);
		$data['comment_author_IP'] = $_SERVER['REMOTE_ADDR']; // phpcs:ignore
		$data['comment_agent']     = $_SERVER['HTTP_USER_AGENT']; // phpcs:ignore

		$user_id = \get_current_user_id();
		if ($user_id) {
			$user = \get_user_by('id', $user_id);
			if ($user) {
				$data['comment_author']       = $user->display_name;
				$data['comment_author_email'] = $user->user_email;
				$data['comment_author_url']   = $user->user_url;
			}
		}
		$data['user_id'] = $user_id;

		/** @var array{comment_agent?: string, comment_approved?: int|string, comment_author?: string, comment_author_email?: string, comment_author_IP?: string, comment_author_url?: string, comment_content?: string, comment_date?: string, comment_date_gmt?: string, comment_karma?: int, comment_meta?: array<string, mixed>, comment_parent?: int, comment_post_ID?: int, comment_type?: string, user_id?: int} $data */
		$comment_id = \wp_insert_comment( $data );

		if (!$comment_id) {
			return new \WP_REST_Response(
			[
				'code'    => 400,
				'message' => __( 'Failed to add review, please try again', 'power-course' ),
				'data'    => null,
			],
			400
			);
		}

		$message = match ($data['comment_type'] ?? '') {
			'comment' => __( 'Comment added successfully', 'power-course' ),
			'review'  => __( 'Review added successfully', 'power-course' ),
			default   => __( 'Comment added successfully', 'power-course' ),
		};

		return new \WP_REST_Response(
			[
				'code'    => 200,
				'message' => $message,
				'data'    => [
					'id' => (string) $comment_id,
				],
			],
			200
			);
	}

	/**
	 * 新增留言
	 *
	 * @param \WP_REST_Request $request 包含新增留言所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function post_comments_with_id_toggle_approved_callback( \WP_REST_Request $request ): \WP_REST_Response {

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

		if (!\is_numeric($comment_approved)) {
			return new \WP_REST_Response(
				[
					'code'    => 400,
					'message' => __( 'Spam comments cannot be moderated', 'power-course' ),
					'data'    => [
						'id' => (string) $id,
					],
				],
				400
				);
		}

		$comment_approved_update_value = match ($comment_approved) {
			'0' => '1',
			'1'  => '0',
			default => '1',
		};

		// 取得所有 Children id
		$children_ids = self::get_all_children_ids($id);

		$all_comment_ids = [ $id, ...$children_ids ];

		$message = '1' === $comment_approved_update_value
			? __( 'Comment shown successfully', 'power-course' )
			: __( 'Comment hidden successfully', 'power-course' );

		foreach ($all_comment_ids as $comment_id) {
			$result = \wp_update_comment(
				[
					'comment_ID'       => $comment_id,
					'comment_approved' => $comment_approved_update_value,
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
				'message' => $message,
				'data'    => [
					'ids' => $all_comment_ids,
				],
			],
			200
			);
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
