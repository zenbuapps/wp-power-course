<?php
/**
 * Video 404 component
 */

$default_args = [
	'class'   => 'bg-primary aspect-video w-full text-white flex flex-col items-center justify-center',
	'title'   => '找不到影片',
	'message' => '請聯絡老師',
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
		'class'   => $class,
		'title'   => $video_title,
		'message' => $message,
] = $args;


printf(
		/*html*/'
	<div class="%1$s">
		<p class="font-bold text-4xl mb-2">%2$s</p>
		<p class="text-base">%3$s</p>
	</div>
	',
		$class,
		$video_title,
		$message
	);

return;
