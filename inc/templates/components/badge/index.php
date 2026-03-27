<?php
/**
 * Button component
 * reserve class
 * pc-badge-primary pc-badge-secondary pc-badge-neutral pc-badge-link pc-badge-ghost pc-badge-accent pc-badge-info pc-badge-success pc-badge-warning pc-badge-error
 * pc-badge-outline
 * pc-badge-xs pc-badge-sm pc-badge-md pc-badge-lg
 */

use J7\PowerCourse\Plugin;

$default_props = [
	'type'          => 'primary', // primary | secondary | neutral | link | ghost | accent | info | success | warning | error
	'outline'       => false,
	'size'          => 'md', // xs | sm | md | lg
	'children'      => 'badge',
	'icon'          => '',
	'icon_class'    => ' size-4 fill-current group-hover:fill-white transition-fill duration-300 ease-in-out ',
	'icon_position' => 'start', // start | end
	'class'         => '',
	'attr'          => '',
	'loading'       => false,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_props );

[
	'type'     => $btn_type,
	'outline'  => $outline,
	'size'     => $size,
	'children' => $children,
	'icon'     => $icon,
	'icon_class'     => $icon_class,
	'icon_position'     => $icon_position,
	'class'    => $class,
	'attr'   => $attr,
	'loading'  => $loading,
] = $args;


$class_arr = [
	$btn_type,
	$outline ? 'outline' : '',
	$size,
];


$class_arr = array_filter( $class_arr );
$class_arr = array_map( fn( $class_name ) => "pc-badge-$class_name", $class_arr);
$classes   = $class . ' ' . implode( ' ', $class_arr ) . ( $outline ? ' border-solid ' : '' );

$icon_html = $loading ? '<span class="loading loading-spinner text-current group-hover:text-white transition duration-300 ease-in-out "></span>' : Plugin::safe_get(
	"icon/{$icon}",
	[
		'class' => $icon_class,
	],
	false
);

$content = sprintf(
/*html*/'%1$s %2$s',
'start' === $icon_position ? $icon_html : $children,
'start' === $icon_position ? $children : $icon_html
);

printf(
	'<div class="pc-badge whitespace-nowrap %1$s" %2$s>%3$s</div>',
$classes,
$attr,
$content
);
