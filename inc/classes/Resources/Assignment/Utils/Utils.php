<?php
/**
 * 作業模組共用工具（Issue #131）
 *
 * 目前只有課程觀看權閘門 can_access_course()：學員端所有端點（查詢、繳交）
 * 的第一道 domain 檢查，對齊參考實作 hasActiveAccess（計劃 §6.1）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class Utils
 */
final class Utils {

	/**
	 * 學員對課程是否有有效觀看權
	 *
	 * 三個條件同時成立：
	 *   1. is_avl()：已購買（avl_course_ids）或持有涵蓋此課程的有效權限包（Issue #252 OR 疊加）
	 *   2. ! is_expired()：觀看權未到期
	 *   3. is_course_ready()：課程已開課（course_schedule）
	 *
	 * 注意 is_expired() / is_course_ready() 讀的是 time() 而非 Clock::now()（R-06），
	 * 這是既有 Utils\Course 的行為，本模組不改寫。
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   學員 user ID
	 * @return bool
	 */
	public static function can_access_course( int $course_id, int $user_id ): bool {
		if ( $course_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( ! CourseUtils::is_avl( $course_id, $user_id ) ) {
			return false;
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( CourseUtils::is_expired( $product, $user_id ) ) {
			return false;
		}

		return CourseUtils::is_course_ready( $product );
	}

	/**
	 * 沒有有效觀看權時中斷（403）
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   學員 user ID
	 * @throws AssignmentException 無有效觀看權時（403）。
	 */
	public static function assert_course_access( int $course_id, int $user_id ): void {
		if ( ! self::can_access_course( $course_id, $user_id ) ) {
			throw AssignmentException::access_denied( self::not_enrolled_message() );
		}
	}

	/**
	 * 「尚未報名此課程」訊息（DEC-12）
	 *
	 * 作業不存在或在垃圾桶時也一律回這個訊息，不洩漏作業是否存在。
	 *
	 * @return string 已翻譯的錯誤訊息
	 */
	public static function not_enrolled_message(): string {
		return \__( 'You are not enrolled in this course', 'power-course' );
	}
}
