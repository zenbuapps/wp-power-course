<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\Utils\Course as CourseUtils;

/** 自動開通註冊會員的預設課程 */
final class AutoGrant {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'user_register', [ $this, 'grant_courses_on_register' ], 10, 1 );
	}

	/**
	 * 會員註冊後自動開通課程
	 *
	 * @param int $user_id 會員 ID
	 * @return void
	 */
	public function grant_courses_on_register( int $user_id ): void {
		$auto_grant_courses = Settings::instance()->auto_grant_courses;
		if ( !$auto_grant_courses ) {
			return;
		}

		foreach ( $auto_grant_courses as $auto_grant_course ) {
			$course_id = $auto_grant_course['course_id'];
			if ( $course_id <= 0 ) {
				continue;
			}

			if ( !CourseUtils::is_course_product( $course_id ) ) {
				continue;
			}

			$limit_type = $auto_grant_course['limit_type'];
			if ( 'follow_subscription' === $limit_type ) {
				continue;
			}

			$limit_value = $auto_grant_course['limit_value'];
			$limit_unit  = $auto_grant_course['limit_unit'];

			$limit       = new Limit( $limit_type, $limit_value, $limit_unit );
			$expire_date = $limit->calc_expire_date( null );

			\do_action( LifeCycle::ADD_STUDENT_TO_COURSE_ACTION, $user_id, $course_id, $expire_date, null );
		}
	}
}
