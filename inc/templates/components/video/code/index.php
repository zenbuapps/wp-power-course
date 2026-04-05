<?php
/**
 * Iframe Youtube component
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'class'      => 'rounded-xl',
	'video_info' => [
		'type' => 'youtube',
		'id'   => '',
		'meta' => [],
	],
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

/**
 * @var array{type: string, id: string, meta: ?array<string, mixed>} $video_info
 */
[
	'class'      => $class,
	'video_info'   => $video_info,
] = $args;

[
	'id'   => $video_code,
] = $video_info;



if ( ! $video_code ) {
	Plugin::load_template(
		'video/404',
		[
			'message' => '缺少 video 內容 ，請聯絡老師',
		]
		);
	return;
}


echo do_shortcode( $video_code );
