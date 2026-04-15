<?php
/**
 * My Account 我的課程
 */

use J7\PowerCourse\Plugin;


$course_tabs = [
	'all' => [
		'label'   => esc_html__( '所有課程', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'all' ], false ),
	],
	'ready' => [
		'label'   => esc_html__( '已開課', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'ready' ], false ),
	],
	'not-ready' => [
		'label'   => esc_html__( '尚未開課', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'not-ready' ], false ),
	],
];

Plugin::load_template(
	'tabs',
	[
		'course_tabs' => $course_tabs,
	]
);
