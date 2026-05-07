<?php
/**
 * Announcement Service Query
 *
 * 提供公告查詢相關的業務邏輯，供 REST callback 與前台模板共用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Service;

use J7\PowerCourse\Resources\Announcement\Core\CPT;
use J7\PowerCourse\Resources\Announcement\Utils\Utils;

/**
 * Announcement Query Service
 */
final class Query {

	/**
	 * 後台公告列表
	 *
	 * 支援篩選：
	 * - parent_course_id：必傳，限定查詢的課程
	 * - post_status：CSV 字串或陣列；預設 'publish,future,draft'（不含 trash，但含 draft 讓管理員看得到草稿）
	 * - posts_per_page：預設 20
	 * - paged：預設 1
	 *
	 * @param array<string, mixed> $args 查詢參數
	 * @return array<int, array<string, mixed>> 格式化後的公告列表
	 */
	public static function list( array $args = [] ): array {
		$parent_course_id = isset( $args['parent_course_id'] ) ? (int) $args['parent_course_id'] : 0;

		// post_status 預設為 publish + future + draft（不含 trash；管理員後台需看到草稿）
		$status_arg  = $args['post_status'] ?? 'publish,future,draft';
		$post_status = self::normalize_status_arg( $status_arg );

		$query_args = [
			'post_type'      => CPT::POST_TYPE,
			'posts_per_page' => isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : 20,
			'paged'          => isset( $args['paged'] ) ? (int) $args['paged'] : 1,
			'post_status'    => $post_status,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $parent_course_id > 0 ) {
			$query_args['post_parent'] = $parent_course_id;
		}

		$posts = \get_posts( $query_args );

		return array_values(
			array_map( [ Utils::class, 'format_announcement_details' ], $posts )
		);
	}

	/**
	 * 取得單一公告詳情
	 *
	 * @param int $announcement_id 公告 ID
	 * @return array<string, mixed>|null 找不到時回傳 null
	 */
	public static function get( int $announcement_id ): ?array {
		$post = \get_post( $announcement_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== CPT::POST_TYPE ) {
			return null;
		}
		return Utils::format_announcement_details( $post );
	}

	/**
	 * 前台公開列表
	 *
	 * 過濾條件：
	 * - post_status = publish
	 * - post_date 已到（WP_Query 自動）
	 * - end_at 為空、0 或大於現在時間
	 * - visibility = public，或 visibility = enrolled 且使用者已購此課程
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   使用者 ID（0 視為未登入）
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_public( int $course_id, int $user_id = 0 ): array {
		if ( $course_id <= 0 ) {
			return [];
		}

		$now = (int) \current_time( 'timestamp' );

		$meta_query = [
			'relation' => 'OR',
			[
				'key'     => 'end_at',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => 'end_at',
				'value'   => '',
				'compare' => '=',
			],
			[
				'key'     => 'end_at',
				'value'   => 0,
				'type'    => 'NUMERIC',
				'compare' => '=',
			],
			[
				'key'     => 'end_at',
				'value'   => $now,
				'type'    => 'NUMERIC',
				'compare' => '>',
			],
		];

		$query_args = [
			'post_type'      => CPT::POST_TYPE,
			'posts_per_page' => 50,
			'post_status'    => 'publish',
			'post_parent'    => $course_id,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];

		$posts = \get_posts( $query_args );

		$is_enrolled = Utils::is_enrolled( $course_id, $user_id );

		// 可見性過濾：未購學員只看 public；已購額外看 enrolled
		$filtered = array_filter(
			$posts,
			static function ( \WP_Post $post ) use ( $is_enrolled ): bool {
				$visibility = (string) \get_post_meta( $post->ID, 'visibility', true );
				if ( '' === $visibility ) {
					$visibility = Utils::VISIBILITY_PUBLIC;
				}
				if ( Utils::VISIBILITY_PUBLIC === $visibility ) {
					return true;
				}
				if ( Utils::VISIBILITY_ENROLLED === $visibility && $is_enrolled ) {
					return true;
				}
				return false;
			}
		);

		return array_values(
			array_map( [ Utils::class, 'format_announcement_details' ], array_values( $filtered ) )
		);
	}

	/**
	 * 將外部傳入的 post_status 參數轉成 WP_Query 用的陣列
	 *
	 * @param mixed $status CSV 字串、單一字串或陣列
	 * @return array<string>
	 */
	private static function normalize_status_arg( $status ): array {
		if ( is_array( $status ) ) {
			$values = $status;
		} elseif ( is_string( $status ) && '' !== $status ) {
			$values = explode( ',', $status );
		} else {
			$values = [ 'publish', 'future', 'draft' ];
		}

		$values = array_map( 'trim', $values );
		$values = array_filter(
			$values,
			static fn ( $v ) => in_array( $v, [ 'publish', 'future', 'trash', 'draft', 'pending', 'private', 'any' ], true )
		);

		if ( empty( $values ) ) {
			return [ 'publish', 'future', 'draft' ];
		}
		return array_values( array_unique( $values ) );
	}
}
