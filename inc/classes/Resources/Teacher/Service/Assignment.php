<?php
/**
 * Teacher Assignment Service — 講師課程指派服務
 *
 * 講師與課程的關聯是以 course 的 post_meta `teacher_ids` 多筆紀錄儲存：
 * 一位講師對應一筆 (post_id, meta_key=teacher_ids, meta_value=user_id)。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Teacher\Service;

/**
 * Class Assignment
 * 將講師指派到課程，或從課程移除講師。
 */
final class Assignment {

	/**
	 * 將講師指派到指定課程
	 *
	 * 行為為 idempotent：若已存在相同的 (course_id, user_id) teacher_ids meta，
	 * 不會重複新增，直接回傳 true。
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   講師 user ID
	 * @return bool|\WP_Error 成功時 true；course 不存在 / user 不存在 / user 非講師 時回傳 WP_Error
	 */
	public static function assign( int $course_id, int $user_id ): bool|\WP_Error {
		$validation = self::validate( $course_id, $user_id );
		if ( \is_wp_error( $validation ) ) {
			return $validation;
		}

		// 已存在則視為成功（idempotent）
		if ( self::is_assigned( $course_id, $user_id ) ) {
			return true;
		}

		\add_post_meta( $course_id, 'teacher_ids', (string) $user_id, false );
		return true;
	}

	/**
	 * 將講師從指定課程移除
	 *
	 * 行為為 idempotent：若未指派，仍回傳 true。
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   講師 user ID
	 * @return bool|\WP_Error 成功時 true；course 不存在時回傳 WP_Error
	 */
	public static function remove( int $course_id, int $user_id ): bool|\WP_Error {
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'teacher_assignment_invalid_course',
				\__( 'course_id 必須為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'teacher_assignment_invalid_user',
				\__( 'user_id 必須為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'teacher_assignment_course_not_found',
				\__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		// delete_post_meta 指定 meta_value 僅刪除對應那一筆
		\delete_post_meta( $course_id, 'teacher_ids', (string) $user_id );
		// 再清一次以防舊資料以 int 型態儲存
		\delete_post_meta( $course_id, 'teacher_ids', $user_id );

		return true;
	}

	/**
	 * 檢查講師是否已指派到課程
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   講師 user ID
	 * @return bool
	 */
	public static function is_assigned( int $course_id, int $user_id ): bool {
		/** @var array<int|string> $existing */
		$existing = (array) \get_post_meta( $course_id, 'teacher_ids', false );

		foreach ( $existing as $value ) {
			if ( (int) $value === $user_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 驗證 course 與 user 是否合法
	 *
	 * @param int $course_id 課程 ID
	 * @param int $user_id   user ID
	 * @return true|\WP_Error 驗證失敗時回傳 WP_Error
	 */
	private static function validate( int $course_id, int $user_id ): bool|\WP_Error {
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'teacher_assignment_invalid_course',
				\__( 'course_id 必須為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'teacher_assignment_invalid_user',
				\__( 'user_id 必須為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'teacher_assignment_course_not_found',
				\__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$user = \get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'teacher_assignment_user_not_found',
				\__( '找不到指定的使用者', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'yes' !== \get_user_meta( $user_id, 'is_teacher', true ) ) {
			return new \WP_Error(
				'teacher_assignment_not_a_teacher',
				\__( '指定的使用者不是講師，請先將其設為講師再指派課程', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		return true;
	}
}
