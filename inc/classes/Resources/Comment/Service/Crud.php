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
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class Crud
 * 留言 CRUD 服務
 */
final class Crud {

	/** 允許的留言類型 */
	const ALLOWED_TYPES = [ 'comment', 'review' ];

	/** 訪客未提供名稱時寫入的留言者名稱（固定英文，讓「需有已核准留言」的比對不受站台語系影響） */
	const GUEST_AUTHOR_NAME = 'Guest';

	/**
	 * 建立新留言
	 *
	 * 支援訪客留言，因此安全性不靠登入，而靠以下幾點：
	 * 1. 欄位白名單：只接受下方列出的欄位，`comment_approved`、`comment_date`、`comment_karma`、任意 meta 等一律丟棄
	 * 2. 走 `wp_new_comment()`（而非 `wp_insert_comment()`）：審核狀態交給 `wp_allow_comment()`，
	 *    套用「設定 → 討論」的人工審核 / 黑名單 / 灌水間隔 / 重複留言檢查，並觸發 Akismet 與通知信
	 * 3. Power Course 設定 `comment_moderation = no` 時，只把「待審」改成「核准」，垃圾留言與錯誤照舊
	 *
	 * 接受的欄位：comment_post_ID、comment_content、comment_type（comment|review）、comment_parent、
	 * rating（僅 review，1–5）、訪客另收 comment_author / comment_author_email。
	 *
	 * 支援代其他 user 發言（當指定 $user_id_override 時），呼叫端負責做 capability check（例如要求 moderate_comments）。
	 *
	 * @param array<string, mixed> $body_params      原始輸入參數
	 * @param int|null             $user_id_override 指定作者 ID；null 則採用目前登入者
	 * @return array{code: int, message: string, data: array<string, mixed>|null}
	 */
	public static function create( array $body_params, ?int $user_id_override = null ): array {
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		$comment_type = (string) ( $body_params['comment_type'] ?? 'comment' );
		if ( ! in_array( $comment_type, self::ALLOWED_TYPES, true ) ) {
			return self::error( \__( 'Invalid comment type', 'power-course' ) );
		}

		$post_id = (int) ( $body_params['comment_post_ID'] ?? 0 );

		// 章節（pc_chapter）留言：前端送來的 comment_post_ID 是章節 ID，
		// 但留言掛在所屬課程商品底下，需先轉譯為課程商品 ID 再驗證 / 儲存（Issue #234）。
		$source_chapter_id = 0;
		$course_product_id = CommentUtils::to_course_product_id( $post_id );
		if ( $course_product_id !== $post_id ) {
			$source_chapter_id = $post_id;
			$post_id           = $course_product_id;
		}

		$product = \wc_get_product( $post_id );
		if ( ! $product ) {
			return self::error( '找不到商品' );
		}

		$user_id = null !== $user_id_override ? $user_id_override : \get_current_user_id();

		// 未發佈的商品（草稿、待審、私密…）只有可編輯者或已開通的學員能留言，避免訪客對未公開商品灌留言
		if ( 'publish' !== \get_post_status( $post_id )
			&& ! \current_user_can( 'edit_post', $post_id )
			&& ! CourseUtils::is_avl( $post_id, $user_id ) ) {
			return self::error( '找不到商品' );
		}

		$can_comment = CommentUtils::can_comment( $product, $comment_type );
		if ( true !== $can_comment ) {
			return self::error( (string) $can_comment );
		}

		$comment_content = (string) ( $body_params['comment_content'] ?? '' );
		if ( '' === trim( $comment_content ) ) {
			return self::error( \__( 'Please enter comment content', 'power-course' ) );
		}

		// 回覆：父留言必須屬於同一篇商品，且非管理者只能回覆已公開的留言
		$comment_parent = max( 0, (int) ( $body_params['comment_parent'] ?? 0 ) );
		if ( $comment_parent > 0 ) {
			$parent    = \get_comment( $comment_parent );
			$parent_ok = $parent instanceof \WP_Comment
			&& (int) $parent->comment_post_ID === $post_id
			&& ( '1' === (string) $parent->comment_approved || \current_user_can( 'moderate_comments' ) );
			if ( ! $parent_ok ) {
				return self::error( \__( 'The comment you are replying to does not exist', 'power-course' ) );
			}
		}

		$data = [
			'comment_post_ID'      => $post_id,
			'comment_content'      => $comment_content,
			'comment_type'         => $comment_type,
			'comment_parent'       => $comment_parent,
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '', // phpcs:ignore
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '', // phpcs:ignore
			'comment_author'       => '',
			'comment_author_email' => '',
			'comment_author_url'   => '',
			'user_id'              => 0,
			'comment_meta'         => [],
		];

		$user = $user_id ? \get_user_by( 'id', $user_id ) : false;
		if ( $user ) {
			$data['user_id']              = (int) $user->ID;
			$data['comment_author']       = $user->display_name;
			$data['comment_author_email'] = $user->user_email;
			$data['comment_author_url']   = $user->user_url;
		} else {
			$email = \sanitize_email( (string) ( $body_params['comment_author_email'] ?? '' ) );
			if ( ! \is_email( $email ) ) {
				return self::error( \__( 'Please enter a valid email', 'power-course' ) );
			}
			$author = trim( (string) ( $body_params['comment_author'] ?? '' ) );

			$data['comment_author_email'] = $email;
			$data['comment_author']       = '' !== $author ? mb_substr( $author, 0, 245 ) : self::GUEST_AUTHOR_NAME;
		}

		if ( 'review' === $comment_type ) {
			$rating = (int) ( $body_params['rating'] ?? 0 );
			if ( $rating < 1 || $rating > 5 ) {
				return self::error( \__( 'Rating must be between 1 and 5', 'power-course' ) );
			}
			$data['comment_meta']['rating'] = $rating;
		}

		// 記錄來源章節 ID，供前台 / 分析識別留言所屬章節（Issue #234）
		if ( $source_chapter_id > 0 ) {
			$data['comment_meta']['_pc_chapter_id'] = $source_chapter_id;
		}

		// Power Course 設定關閉審核時，只把「待審（0）」改成「核准（1）」；spam / trash / WP_Error 原樣保留
		$skip_moderation = static fn( $approved ) => ( 0 === $approved || '0' === $approved ) ? 1 : $approved;
		$needs_override  = 'no' === Settings::instance()->comment_moderation;
		if ( $needs_override ) {
			\add_filter( 'pre_comment_approved', $skip_moderation, PHP_INT_MAX );
		}

		// WooCommerce 在 comment_post 直接讀 $_POST['rating'] 寫入星等，不分留言類型；
		// 而 WC 平均星等的 SQL 也不分類型，一般留言夾帶 rating 就能操縱課程評分。
		// 星等已在上方驗證並寫進 comment_meta，所以呼叫期間拿掉這個 hook
		$wc_rating_hook     = [ \WC_Comments::class, 'add_comment_rating' ];
		$wc_rating_priority = \has_action( 'comment_post', $wc_rating_hook );
		if ( false !== $wc_rating_priority ) {
			\remove_action( 'comment_post', $wc_rating_hook, (int) $wc_rating_priority );
		}

		// WooCommerce 的 update_comment_type（preprocess_comment）看到 $_POST['comment_post_ID'] 是商品，
		// 就把預設類型 comment 改成 review，章節留言會全部變成評價。最後執行、改回已驗證過的類型
		$keep_comment_type = static function ( $commentdata ) use ( $comment_type ) {
			if ( is_array( $commentdata ) ) {
				$commentdata['comment_type'] = $comment_type;
			}
			return $commentdata;
		};
		\add_filter( 'preprocess_comment', $keep_comment_type, PHP_INT_MAX );

		try {
			// wp_new_comment 預期 slashed 資料（內部 wp_insert_comment 會 wp_unslash）
			$comment_id = \wp_new_comment( \wp_slash( $data ), true );
		} finally {
			\remove_filter( 'preprocess_comment', $keep_comment_type, PHP_INT_MAX );
			if ( $needs_override ) {
				\remove_filter( 'pre_comment_approved', $skip_moderation, PHP_INT_MAX );
			}
			if ( false !== $wc_rating_priority ) {
				\add_action( 'comment_post', $wc_rating_hook, (int) $wc_rating_priority );
			}
		}

		if ( \is_wp_error( $comment_id ) ) {
			$error_data = $comment_id->get_error_data();
			$status     = 400;
			if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
				$status = (int) $error_data['status'];
			} elseif ( is_int( $error_data ) ) {
				$status = $error_data;
			}
			return self::error( $comment_id->get_error_message(), $status >= 400 ? $status : 400 );
		}

		if ( ! $comment_id ) {
			return self::error( '新增評價失敗，請再嘗試一次' );
		}

		$comment_status = (string) \wp_get_comment_status( (int) $comment_id ); // approved | unapproved | spam | trash

		// 垃圾留言 / 垃圾桶也回「等待審核」，不告訴對方被判為垃圾
		if ( 'approved' !== $comment_status ) {
			return [
				'code'    => 200,
				'message' => \__( 'Your comment is awaiting moderation', 'power-course' ),
				'data'    => [
					'id'     => (string) $comment_id,
					'status' => 'unapproved',
				],
			];
		}

		$label = 'review' === $comment_type ? '評價' : '留言';

		return [
			'code'    => 200,
			'message' => "已{$label}成功",
			'data'    => [
				'id'     => (string) $comment_id,
				'status' => 'approved',
			],
		];
	}

	/**
	 * 組失敗回應
	 *
	 * @param string $message 錯誤訊息
	 * @param int    $code    HTTP 狀態碼
	 * @return array{code: int, message: string, data: null}
	 */
	private static function error( string $message, int $code = 400 ): array {
		return [
			'code'    => $code,
			'message' => $message,
			'data'    => null,
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
