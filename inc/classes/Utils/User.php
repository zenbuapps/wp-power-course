<?php
/**
 * User
 * TODO 移動到 Resources 底下
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * Class User
 */
abstract class User {

	/**
	 * 取得課程的學生數量
	 *
	 * @param int $course_id 課程ID
	 * @return int
	 * @throws \Exception 課程ID為空時
	 */
	public static function count_student( int $course_id ): int {

		if ( !$course_id ) {
			throw new \Exception('Course ID is required');
		}

		global $wpdb;

		// 查找總數
		$total = $wpdb->get_var(
			$wpdb->prepare(
			'SELECT DISTINCT COUNT(DISTINCT u.ID) FROM %1$s u INNER JOIN %2$s um ON u.ID = um.user_id WHERE um.meta_key = "avl_course_ids" AND um.meta_value = "%3$s"',
			$wpdb->users,
			$wpdb->usermeta,
			(string) $course_id
		)); // phpcs:ignore

		return (int) $total;
	}
}
