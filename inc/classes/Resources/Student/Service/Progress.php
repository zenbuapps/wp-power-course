<?php
/**
 * Progress Service — 學員課程進度查詢
 *
 * 封裝「某位學員在某課程的進度資訊」的讀取邏輯，
 * 供 MCP student_get_progress tool 與其他 Service 層使用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Student\Service;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class Progress
 * 提供學員在單一課程的進度摘要
 */
final class Progress {

	/**
	 * 取得指定學員在指定課程的進度資訊
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   學員 ID
	 * @return array{
	 *     user_id: int,
	 *     course_id: int,
	 *     progress: float,
	 *     total_chapters: int,
	 *     finished_chapters: int,
	 *     finished_chapter_ids: array<int>,
	 *     expire_date_label: string,
	 *     is_expired: bool
	 * }|\WP_Error 學員進度摘要，或錯誤
	 */
	public static function get_progress( int $course_id, int $user_id ): array|\WP_Error {
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'progress_invalid_course_id',
				\__( 'course_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'progress_invalid_user_id',
				\__( 'user_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$user = \get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'progress_user_not_found',
				\__( '找不到指定的學員', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'progress_course_not_found',
				\__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$all_chapter_ids      = ChapterUtils::get_flatten_post_ids( $course_id );
		$finished_chapter_ids = CourseUtils::get_finished_sub_chapters( $course_id, $user_id, true );
		$progress             = CourseUtils::get_course_progress( $course_id, $user_id );

		$expire_date = ExpireDate::instance( $course_id, $user_id );

		return [
			'user_id'              => $user_id,
			'course_id'            => $course_id,
			'progress'             => (float) $progress,
			'total_chapters'       => count( $all_chapter_ids ),
			'finished_chapters'    => count( $finished_chapter_ids ),
			'finished_chapter_ids' => array_values( array_map( 'intval', $finished_chapter_ids ) ),
			'expire_date_label'    => (string) $expire_date->expire_date_label,
			'is_expired'           => (bool) $expire_date->is_expired,
		];
	}
}
