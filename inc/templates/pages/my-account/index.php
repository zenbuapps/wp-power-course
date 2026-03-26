<?php
/**
 * My Account 我的課程
 */

use J7\PowerCourse\Plugin;


$course_tabs = [
	'all' => [
		'label'   => '所有課程',
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'all' ], false ),
	],
	'ready' => [
		'label'   => '已開課',
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'ready' ], false ),
	],
	'not-ready' => [
		'label'   => '尚未開課',
		'content' => Plugin::load_template( 'course-product/grid', [ 'type' => 'not-ready' ], false ),
	],
];

Plugin::load_template(
	'tabs',
	[
		'course_tabs' => $course_tabs,
	]
);
