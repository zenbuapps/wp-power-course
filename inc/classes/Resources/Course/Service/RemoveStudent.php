<?php
/**
 * RemoveStudent Service — 從課程移除學員
 *
 * 抽取自 Api\Course\UserTrait::post_courses_remove_students_callback 的業務邏輯，
 * 封裝成可重用的 Service，供 REST callback 與 MCP tool 共用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class RemoveStudent
 * 從課程移除學員權限（透過觸發 AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION hook）
 */
final class RemoveStudent {

	/**
	 * 從指定課程移除單一學員權限
	 *
	 * 內部委派給 LifeCycle hook，行為與既有 Api 層一致（刪除 avl_course_ids meta、觸發相關清理）。
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   學員 ID
	 * @return array{user_id: int, course_id: int}|\WP_Error 成功時回傳標示，失敗時回傳 WP_Error
	 */
	public static function remove_item( int $course_id, int $user_id ): array|\WP_Error {
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'remove_student_invalid_course_id',
				\__( 'course_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'remove_student_invalid_user_id',
				\__( 'user_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$user = \get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'remove_student_user_not_found',
				\__( '找不到指定的學員', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		try {
			\do_action( LifeCycle::AFTER_REMOVE_STUDENT_FROM_COURSE_ACTION, $user_id, $course_id );
		} catch ( \Throwable $th ) {
			return new \WP_Error(
				'remove_student_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return [
			'user_id'   => $user_id,
			'course_id' => $course_id,
		];
	}
}
