<?php
/**
 * Comment CRUD Service
 * 留言寫入服務層 — 提供 create、toggle_approved 邏輯，供 REST Api callback 與 MCP tool 共用
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Comment\Service;

use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Utils\Comment as CommentUtils;
use J7\PowerCourse\Api\Comment as CommentApi;

/**
 * Class Crud
 * 留言 CRUD 服務
 */
final class Crud {

	/**
	 * 建立新留言
	 *
	 * 將原 REST callback 的邏輯抽出，支援代其他 user 發言（當指定 $user_id_override 時）。
	 * 呼叫端負責做 capability check（例如代他人發言時要求 moderate_comments）。
	 *
	 * @param array<string, mixed> $body_params      原始輸入參數（含 comment_post_ID、comment_content、comment_type 等）
	 * @param int|null             $user_id_override 指定作者 ID；null 則採用目前登入者
	 * @return array{code: int, message: string, data: array<string, mixed>|null}
	 */
	public static function create( array $body_params, ?int $user_id_override = null ): array {
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		$comment_type = (string) ( $body_params['comment_type'] ?? '' );
		$product_id   = (int) ( $body_params['comment_post_ID'] ?? 0 );
		$product      = \wc_get_product( $product_id );
		if ( ! $product ) {
			return [
				'code'    => 400,
				'message' => '找不到商品',
				'data'    => null,
			];
		}

		$can_comment = CommentUtils::can_comment( $product, $comment_type );

		if ( true !== $can_comment ) {
			return [
				'code'    => 400,
				'message' => (string) $can_comment,
				'data'    => null,
			];
		}

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = WP::separator( $body_params, 'comment' );

		$data['comment_meta']      = array_merge( (array) ( $data['comment_meta'] ?? [] ), $meta_data );
		$data['comment_author_IP'] = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore
		$data['comment_agent']     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore

		$user_id = null !== $user_id_override ? $user_id_override : \get_current_user_id();
		if ( $user_id ) {
			$user = \get_user_by( 'id', $user_id );
			if ( $user ) {
				$data['comment_author']       = $user->display_name;
				$data['comment_author_email'] = $user->user_email;
				$data['comment_author_url']   = $user->user_url;
			}
		}
		$data['user_id'] = $user_id;

		/** @var array{comment_agent?: string, comment_approved?: int|string, comment_author?: string, comment_author_email?: string, comment_author_IP?: string, comment_author_url?: string, comment_content?: string, comment_date?: string, comment_date_gmt?: string, comment_karma?: int, comment_meta?: array<string, mixed>, comment_parent?: int, comment_post_ID?: int, comment_type?: string, user_id?: int} $data */
		$comment_id = \wp_insert_comment( $data );

		if ( ! $comment_id ) {
			return [
				'code'    => 400,
				'message' => '新增評價失敗，請再嘗試一次',
				'data'    => null,
			];
		}

		$label = match ( $data['comment_type'] ?? '' ) {
			'comment' => '留言',
			'review'  => '評價',
			default   => '留言',
		};

		return [
			'code'    => 200,
			'message' => "已{$label}成功",
			'data'    => [
				'id' => (string) $comment_id,
			],
		];
	}

	/**
	 * 切換留言的審核狀態（approved ↔ unapproved）
	 *
	 * 會連同所有子層留言一併切換，垃圾留言與 trash 留言無法切換。
	 *
	 * @param int $comment_id 留言 ID
	 * @return array{code: int, message: string, data: array<string, mixed>|null}
	 */
	public static function toggle_approved( int $comment_id ): array {
		$comment = \get_comment( $comment_id );

		if ( ! ( $comment instanceof \WP_Comment ) ) {
			return [
				'code'    => 400,
				'message' => '留言不存在',
				'data'    => null,
			];
		}

		$comment_approved = $comment->comment_approved; // '0', '1', 'spam', 'trash'

		if ( ! \is_numeric( $comment_approved ) ) {
			return [
				'code'    => 400,
				'message' => '垃圾留言無法審核',
				'data'    => [
					'id' => (string) $comment_id,
				],
			];
		}

		$comment_approved_update_value = match ( $comment_approved ) {
			'0'     => '1',
			'1'     => '0',
			default => '1',
		};

		// 取得所有 Children id（保持與原 REST callback 一致行為）
		$children_ids = CommentApi::get_all_children_ids( $comment_id );

		$all_comment_ids = [ $comment_id, ...$children_ids ];

		$label = '1' === $comment_approved_update_value ? '顯示' : '隱藏';

		foreach ( $all_comment_ids as $cid ) {
			$result = \wp_update_comment(
				[
					'comment_ID'       => $cid,
					'comment_approved' => $comment_approved_update_value,
				],
				true
			);

			if ( \is_wp_error( $result ) ) {
				return [
					'code'    => 400,
					'message' => $result->get_error_message(),
					'data'    => [
						'id' => (string) $cid,
					],
				];
			}
		}

		return [
			'code'    => 200,
			'message' => "{$label}留言成功",
			'data'    => [
				'ids'      => $all_comment_ids,
				'approved' => $comment_approved_update_value,
			],
		];
	}
}
