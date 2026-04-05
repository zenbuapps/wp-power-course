<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources;

/** Class Comment */
final class Comment {
	use \J7\WpUtils\Traits\SingletonTrait;


	/** Constructor */
	public function __construct() {
		\add_action('wp_insert_comment', [ __CLASS__, 'send_comment_notification' ], 10, 2);
	}

	/**
	 * 發送評論通知
	 * 只對  'comment', 'review' 發送
	 *
	 * @param int         $comment_id 評論 ID
	 * @param \WP_Comment $comment 評論資料
	 * @return void
	 */
	public static function send_comment_notification( $comment_id, $comment ): void {
		if (!in_array($comment->comment_type, [ 'comment', 'review' ], true)) {
			return;
		}

		$post = \get_post( (int) $comment->comment_post_ID);
		if (!( $post instanceof \WP_Post )) {
			return;
		}

		$comment_parent_id = (int) $comment->comment_parent; // 被回復的評論 ID

		$emails      = [];
		$emails[]    = \get_option('admin_email');
		$teacher_ids = \get_post_meta($post->ID, 'teacher_ids') ?: [];
		if (is_array($teacher_ids)) {
			foreach ($teacher_ids as $teacher_id) {
				$teacher = \get_user_by('ID', (int) $teacher_id);
				if ($teacher instanceof \WP_User) {
					$emails[] = $teacher->user_email;
				}
			}
		}
		if ($comment_parent_id ) {
			$comment_parent = \get_comment($comment_parent_id);
			if ($comment_parent instanceof \WP_Comment) {
				$emails[] = $comment_parent->comment_author_email;
			}
		}

		$switched_locale = \switch_to_locale( \get_locale() );

		$comment_author_domain = '';
		if ( \WP_Http::is_ip_address( $comment->comment_author_IP ) ) {
			$comment_author_domain = gethostbyaddr( $comment->comment_author_IP );
		}

		/*
		* The blogname option is escaped with esc_html() on the way into the database in sanitize_option().
		* We want to reverse this for the plain text arena of emails.
		*/
		$blogname        = \wp_specialchars_decode( (string) \get_option( 'blogname' ), ENT_QUOTES );
		$comment_content = \wp_specialchars_decode( $comment->comment_content );

		/* translators: %s: Post title. */
		$notify_message = sprintf( \__( 'New comment on your post "%s"' ), $post->post_title ) . "\r\n";
		/* translators: 1: Comment author's name, 2: Comment author's IP address, 3: Comment author's hostname. */
		$notify_message .= sprintf( \__( 'Author: %1$s (IP address: %2$s, %3$s)' ), $comment->comment_author, $comment->comment_author_IP, $comment_author_domain ) . "\r\n";
		/* translators: %s: Comment author email. */
		$notify_message .= sprintf( \__( 'Email: %s' ), $comment->comment_author_email ) . "\r\n";
		/* translators: %s: Trackback/pingback/comment author URL. */
		$notify_message .= sprintf( \__( 'URL: %s' ), $comment->comment_author_url ) . "\r\n";

		/* translators: %s: Comment text. */
		$notify_message .= sprintf( \__( 'Comment: %s' ), "\r\n" . $comment_content ) . "\r\n\r\n";
		$notify_message .= \__( 'You can see all comments on this post here:' ) . "\r\n";
		/* translators: Comment notification email subject. 1: Site title, 2: Post title. */
		$subject = sprintf( \__( '[%1$s] Comment: "%2$s"' ), $blogname, $post->post_title );

		$notify_message .= \get_permalink( (int) $comment->comment_post_ID ) . "\r\n\r\n";

		$wp_email = 'wordpress@' . preg_replace( '#^www\.#', '', (string) \wp_parse_url( \network_home_url(), PHP_URL_HOST ) );

		if ( '' === $comment->comment_author ) {
			$from = "From: \"$blogname\" <$wp_email>";
			if ( '' !== $comment->comment_author_email ) {
				$reply_to = "Reply-To: $comment->comment_author_email";
			}
		} else {
			$from = "From: \"$comment->comment_author\" <$wp_email>";
			if ( '' !== $comment->comment_author_email ) {
				$reply_to = "Reply-To: \"$comment->comment_author_email\" <$comment->comment_author_email>";
			}
		}

		$message_headers = "$from\n"
		. 'Content-Type: text/plain; charset="' . (string) \get_option( 'blog_charset' ) . "\"\n";

		if ( isset( $reply_to ) ) {
			$message_headers .= $reply_to . "\n";
		}

		/**
	 * Filters the comment notification email text.
	 *
	 * @since 1.5.2
	 *
	 * @param string $notify_message The comment notification email text.
	 * @param string $comment_id     Comment ID as a numeric string.
	 */
		/** @var string $notify_message */
		$notify_message = \apply_filters( 'comment_notification_text', $notify_message, $comment->comment_ID );

		/**
		 * Filters the comment notification email subject.
		 *
		 * @since 1.5.2
		 *
		 * @param string $subject    The comment notification email subject.
		 * @param string $comment_id Comment ID as a numeric string.
		 */
		/** @var string $subject */
		$subject = \apply_filters( 'comment_notification_subject', $subject, $comment->comment_ID );

		/**
		 * Filters the comment notification email headers.
		 *
		 * @since 1.5.2
		 *
		 * @param string $message_headers Headers for the comment notification email.
		 * @param string $comment_id      Comment ID as a numeric string.
		 */
		/** @var string $message_headers */
		$message_headers = \apply_filters( 'comment_notification_headers', $message_headers, $comment->comment_ID );

		$emails = array_unique($emails);
		foreach ($emails as $email) {
			\wp_mail( (string) $email, \wp_specialchars_decode( $subject ), $notify_message, $message_headers );
		}

		if ( $switched_locale ) {
			\restore_previous_locale();
		}
	}
}
