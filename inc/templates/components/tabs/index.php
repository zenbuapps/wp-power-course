<?php
/**
 * @var mixed $args
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'course_tabs'        => [],
	'default_active_key' => null,
];

// @phpstan-ignore-next-line
$args = wp_parse_args( $args, $default_args );

$course_tabs        = $args['course_tabs'];
$default_active_key = $args['default_active_key'];


Plugin::load_template('tabs/nav', $args);
Plugin::load_template('tabs/content', $args);
