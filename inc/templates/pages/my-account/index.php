<?php
/**
 * My Account 我的課程
 */

use J7\PowerCourse\Plugin;


$course_tabs = [
	'all' => [
		'label'   => esc_html__( 'All courses', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'all' ], false ),
	],
	'ready' => [
		'label'   => esc_html__( 'Started', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'ready' ], false ),
	],
	'not-ready' => [
		'label'   => esc_html__( 'Not started', 'power-course' ),
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'not-ready' ], false ),
	],
];

Plugin::load_template(
	'tabs',
	[
		'course_tabs' => $course_tabs,
	]
);
