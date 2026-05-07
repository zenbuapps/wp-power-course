<?php
/**
 * Announcement Utils
 *
 * 提供公告相關的靜態工具方法（驗證、格式化、可見性判斷）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Utils;

use J7\PowerCourse\Resources\Announcement\Core\CPT;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Announcement Utils
 */
abstract class Utils {

	/** Visibility 合法值 */
	public const VISIBILITY_PUBLIC   = 'public';
	public const VISIBILITY_ENROLLED = 'enrolled';

	/** Status label 四態（含 draft：管理員後台用以區分草稿） */
	public const STATUS_LABEL_ACTIVE    = 'active';
	public const STATUS_LABEL_SCHEDULED = 'scheduled';
	public const STATUS_LABEL_EXPIRED   = 'expired';
	public const STATUS_LABEL_DRAFT     = 'draft';

	/**
	 * 將公告 WP_Post 物件格式化為陣列（供 REST 回應與前台模板使用）
	 *
	 * @param \WP_Post $post 公告文章物件
	 * @return array<string, mixed>
	 */
	public static function format_announcement_details( \WP_Post $post ): array {
		$end_at_raw = \get_post_meta( $post->ID, 'end_at', true );
		$end_at     = is_numeric( $end_at_raw ) ? (int) $end_at_raw : 0;
		$visibility = (string) \get_post_meta( $post->ID, 'visibility', true );
		if ( '' === $visibility ) {
			$visibility = self::VISIBILITY_PUBLIC;
		}
		$parent_course_id = (int) \get_post_meta( $post->ID, 'parent_course_id', true );
		if ( 0 === $parent_course_id ) {
			$parent_course_id = (int) $post->post_parent;
		}

		return [
			'id'               => (string) $post->ID,
			'post_title'       => $post->post_title,
			'post_content'     => $post->post_content,
			'post_status'      => $post->post_status,
			'post_date'        => $post->post_date,
			'post_date_gmt'    => $post->post_date_gmt,
			'post_modified'    => $post->post_modified,
			'post_parent'      => (int) $post->post_parent,
			'parent_course_id' => $parent_course_id,
			'end_at'           => $end_at > 0 ? $end_at : '',
			'visibility'       => $visibility,
			'editor'           => (string) \get_post_meta( $post->ID, 'editor', true ),
			'status_label'     => self::compute_status_label( $post, $end_at ),
		];
	}

	/**
	 * 計算公告的狀態標籤（active / scheduled / expired）
	 *
	 * @param \WP_Post $post   公告物件
	 * @param int      $end_at 結束時間（Unix timestamp，0 代表無結束）
	 * @return string
	 */
	public static function compute_status_label( \WP_Post $post, int $end_at ): string {
		// 順序：draft → future → publish/expired → 其他原樣
		if ( 'draft' === $post->post_status ) {
			return self::STATUS_LABEL_DRAFT;
		}

		if ( 'future' === $post->post_status ) {
			return self::STATUS_LABEL_SCHEDULED;
		}

		if ( 'publish' === $post->post_status ) {
			$now = (int) \current_time( 'timestamp' );
			if ( $end_at > 0 && $end_at <= $now ) {
				return self::STATUS_LABEL_EXPIRED;
			}
			return self::STATUS_LABEL_ACTIVE;
		}

		return $post->post_status;
	}

	/**
	 * 判斷公告是否生效中
	 *
	 * 條件：post_status=publish 且（無 end_at 或 end_at > 現在時間）
	 *
	 * @param \WP_Post $post 公告物件
	 * @return bool
	 */
	public static function is_active( \WP_Post $post ): bool {
		if ( 'publish' !== $post->post_status ) {
			return false;
		}
		$end_at_raw = \get_post_meta( $post->ID, 'end_at', true );
		$end_at     = is_numeric( $end_at_raw ) ? (int) $end_at_raw : 0;
		if ( $end_at <= 0 ) {
			return true;
		}
		$now = (int) \current_time( 'timestamp' );
		return $end_at > $now;
	}

	/**
	 * 取得快取 key
	 *
	 * @param int $course_id 課程 ID
	 * @return string
	 */
	public static function get_cache_key( int $course_id ): string {
		return 'pc_announcement_list_' . $course_id;
	}

	/**
	 * 驗證 visibility 值是否合法
	 *
	 * @param mixed $visibility 待驗證值
	 * @return bool
	 */
	public static function is_valid_visibility( $visibility ): bool {
		return in_array( $visibility, [ self::VISIBILITY_PUBLIC, self::VISIBILITY_ENROLLED ], true );
	}

	/**
	 * 驗證 end_at 是否為合法的 10 位 Unix timestamp 字串/整數
	 *
	 * @param mixed $end_at 待驗證值
	 * @return bool
	 */
	public static function is_valid_end_at( $end_at ): bool {
		if ( '' === $end_at || null === $end_at ) {
			return true; // 允許清空
		}
		if ( ! is_numeric( $end_at ) ) {
			return false;
		}
		$value = (int) $end_at;
		// 10 位 Unix timestamp（不含負數，且至少 10^9 ~ 2001 年後）
		return $value > 0 && strlen( (string) $value ) === 10;
	}

	/**
	 * 驗證指定的課程 ID 是否為合法的課程商品（_is_course=yes）
	 *
	 * @param int $course_id 課程 ID
	 * @return bool
	 */
	public static function is_valid_course( int $course_id ): bool {
		if ( $course_id <= 0 ) {
			return false;
		}
		$post = \get_post( $course_id );
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		if ( 'product' !== $post->post_type ) {
			return false;
		}
		return 'yes' === \get_post_meta( $course_id, '_is_course', true );
	}

	/**
	 * 判斷使用者是否為已購學員（針對特定課程）
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   使用者 ID（0 視為未登入）
	 * @return bool
	 */
	public static function is_enrolled( int $course_id, int $user_id ): bool {
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return false;
		}
		return CourseUtils::is_avl( $course_id, $user_id );
	}

	/** 取得 CPT 名稱 */
	public static function get_post_type(): string {
		return CPT::POST_TYPE;
	}
}
