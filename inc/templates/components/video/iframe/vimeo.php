<?php
/**
 * Iframe Vimeo component
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'class'      => 'rounded-xl',
	'video_info' => [
		'type' => 'vimeo',
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
 * @var array{type: string, id: string, meta: ?array<mixed>} $video_info
 */
[
	'class'      => $class,
	'video_info'   => $video_info,
] = $args;

[
	'id'   => $video_id,
] = $video_info;



if ( ! $video_id ) {
	Plugin::load_template(
		'video/404',
		[
			'message' => '缺少 video_id ，請聯絡老師',
		]
		);
	return;
}

$base_url = "https://player.vimeo.com/video/{$video_id}";

$iframe_url = add_query_arg(
	[
		// 'portrait'   => '0', // 螢幕轉向
		// 'byline'       => '0', //
		// 'title'      => '0', //
		// 'color'    => 'a6a8a8',
		// 'responsive' => 'true',
		// 'controls'   => 'true',
	],
	$base_url
);

echo '<div class="relative [&>*]:absolute [&>*]:top-0 [&>*]:left-0 [&>*]:w-full [&>*]:h-full" style="padding-top:56.25%;">';
printf(
	/*html*/'
	<iframe class="z-20 border-0 %2$s" src="%1$s" loading="lazy" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
	<div class="z-10 animate-pulse aspect-video bg-gray-200 text-gray-400 tracking-widest flex items-center justify-center %2$s">LOADING...</div>
			',
	$iframe_url,
	$class
);
echo '</div>';
