<?php
/**
 * MyAccount > grid
 * TODO 分頁
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * @var array{'type':string} $args
 */
$default_args = [
	'type' => 'all', // all, ready, not-ready
];

$args = \wp_parse_args( $args, $default_args );

$course_type = $args['type'] ?? 'all';

$current_user_id = \get_current_user_id();
/** @var array<int, \WC_Product> $user_avl_courses */
$user_avl_courses = CourseUtils::get_avl_courses_by_user();

$filtered_courses = array_filter(
	$user_avl_courses,
	function ( $course ) use ( $course_type ) {
		$is_ready = CourseUtils::is_course_ready( $course );

		if ( 'ready' === $course_type && ! $is_ready ) {
			return false;
		}

		if ( 'not-ready' === $course_type && $is_ready ) {
			return false;
		}

		return true;
	}
);

if ( empty( $filtered_courses ) ) {
	Plugin::load_template(
		'alert',
		[
			'type'    => 'info',
			'message' => 'OOPS! 沒有課程。',
		]
	);

	return;
}

echo '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">';
foreach ( $filtered_courses as $course ) {
	Plugin::load_template(
		'card/available',
		[
			'product' => $course,
		]
		);
}
echo '</div>';
