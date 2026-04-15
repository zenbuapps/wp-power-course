<?php
/**
 * Expandable component
 */

use J7\PowerCourse\Utils\Base;

/**
 * @var array<string, mixed> $args
 */
$args = $args ?? [];

$default_args = [
	'children'   => '',
	'height'     => 160,
	'wrap_class' => 'bg-gradient-to-t from-base-100',
];

$args = wp_parse_args( $args, $default_args );

[
	'children' => $children,
	'height'  => $height,
	'wrap_class' => $wrap_class
] = $args;

$expand_label = esc_html__( 'Expand content', 'power-course' );
printf(
	/*html*/'
	<div data-init-height="%1$s" data-init-bg="%2$s" class="pc-toggle-content text-base-content/75 overflow-hidden relative" style="height:%1$spx;">
			<div class="pc-toggle-content__main">
				%3$s
			</div>
	</div>
	<div class="pc-toggle-content__wrap w-full relative h-10 bottom-10 left-0 cursor-pointer text-sm text-primary flex justify-center items-center font-semibold %2$s">
		<p class="relative top-[3rem]">%4$s</p>
	</div>
	',
	$height,
	$wrap_class,
	$children,
	$expand_label,
);
