<?php
/**
 * Announcement 生命週期相關
 *
 * - save_post 時清銷售頁渲染快取
 * - 課程被 trash / 刪除時連動處理該課程下的公告
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Core;

use J7\PowerCourse\Resources\Announcement\Utils\Utils;

/**
 * Class LifeCycle
 */
final class LifeCycle {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		// 公告儲存後清快取
		\add_action( 'save_post_' . CPT::POST_TYPE, [ __CLASS__, 'clear_cache_on_save' ], 10, 1 );

		// 課程被 trash 時，連動 trash 該課程下的公告
		\add_action( 'wp_trash_post', [ __CLASS__, 'trash_announcements_when_course_trashed' ], 20, 1 );

		// 課程被永久刪除時，連動刪除該課程下的公告
		\add_action( 'before_delete_post', [ __CLASS__, 'delete_announcements_when_course_deleted' ], 20, 1 );
	}

	/**
	 * 公告儲存時清除課程銷售頁的渲染快取
	 *
	 * @param int $post_id 公告 ID
	 */
	public static function clear_cache_on_save( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		$course_id = (int) \get_post_meta( (int) $post_id, 'parent_course_id', true );
		if ( $course_id <= 0 ) {
			$post = \get_post( (int) $post_id );
			if ( $post instanceof \WP_Post ) {
				$course_id = (int) $post->post_parent;
			}
		}
		if ( $course_id > 0 ) {
			\delete_transient( Utils::get_cache_key( $course_id ) );
		}
	}

	/**
	 * 課程 trash 時連動 trash 公告
	 *
	 * @param int $post_id 被 trash 的 post ID
	 */
	public static function trash_announcements_when_course_trashed( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! self::is_course( $post_id ) ) {
			return;
		}
		$announcement_ids = self::get_announcement_ids_by_course( $post_id );
		foreach ( $announcement_ids as $aid ) {
			if ( 'trash' !== \get_post_status( $aid ) ) {
				\wp_trash_post( $aid );
			}
		}
	}

	/**
	 * 課程永久刪除時連動刪除公告
	 *
	 * @param int $post_id 被刪除的 post ID
	 */
	public static function delete_announcements_when_course_deleted( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! self::is_course( $post_id ) ) {
			return;
		}
		$announcement_ids = self::get_announcement_ids_by_course( $post_id );
		foreach ( $announcement_ids as $aid ) {
			\wp_delete_post( $aid, true );
		}
	}

	/**
	 * 判斷指定 post_id 是否為課程商品
	 */
	private static function is_course( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( 'product' !== \get_post_type( $post_id ) ) {
			return false;
		}
		return 'yes' === \get_post_meta( $post_id, '_is_course', true );
	}

	/**
	 * 取得指定課程下的所有公告 ID（含 trash / future）
	 *
	 * @param int $course_id 課程 ID
	 * @return array<int>
	 */
	private static function get_announcement_ids_by_course( int $course_id ): array {
		$ids = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => [ 'publish', 'future', 'draft', 'pending', 'trash' ],
				'post_parent'    => $course_id,
				'fields'         => 'ids',
			]
		);
		return array_map( 'intval', $ids );
	}
}
