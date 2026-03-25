<?php
/**
 * Button component
 * reserve class
 * pc-btn-primary pc-btn-secondary pc-btn-neutral pc-btn-link pc-btn-ghost pc-btn-accent pc-btn-info pc-btn-success pc-btn-warning pc-btn-error
 * pc-btn-outline
 * pc-btn-xs pc-btn-sm pc-btn-lg
 * pc-btn-disabled
 * pc-btn-active
 * pc-btn-glass
 * pc-btn-square pc-btn-circle
 */

use J7\PowerCourse\Plugin;

$default_props = [
	'type'          => '', // primary | secondary | neutral | link | ghost | accent | info | success | warning | error
	'outline'       => false,
	'size'          => '', // xs | sm  | lg
	'children'      => '按鈕',
	'icon'          => '',
	'icon_class'    => ' size-4 fill-current group-hover:fill-white transition-fill duration-300 ease-in-out ',
	'icon_position' => 'start', // start | end
	'disabled'      => false,
	'href'          => '',
	'class'         => '',
	'active'        => false,
	'glass'         => false,
	'attr'          => '',
	'shape'         => '', // square | circle
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
	'disabled' => $disabled,
	'href'     => $href,
	'class'    => $class,
	'active'   => $active,
	'glass'     => $glass,
	'attr'   => $attr,
	'shape'    => $shape,
	'loading'  => $loading,
] = $args;

$href = $href ? esc_url( $href ) : '';

$class_arr = [
	$btn_type,
	$outline ? 'outline' : '',
	$size,
	$disabled ? 'disabled' : '',
	$active ? 'active' : '',
	$glass ? 'glass' : '',
	$shape,
];

$class_arr = array_filter( $class_arr );
$class_arr = array_map( fn( $class_name ) => "pc-btn-$class_name", $class_arr);
$classes   = $class . ' ' . implode( ' ', $class_arr ) . ( $outline ? ' border-solid ' : '' );

$icon_html = '';
if ($icon) {
	$icon_html = $loading ? '<span class="loading loading-spinner text-current group-hover:text-white transition duration-300 ease-in-out"></span>' : Plugin::load_template(
		"icon/{$icon}",
		[
			'class' => $icon_class,
		],
		false
	);
}


$content = sprintf(
/*html*/'%1$s %2$s',
'start' === $icon_position ? $icon_html : $children,
'start' === $icon_position ? $children : $icon_html
);

printf(
	'<%1$s class="group pc-btn %3$s" %4$s>%5$s</%2$s>',
$href ? "a href=\"{$href}\"" : 'button type="button"',
$href ? 'a' : 'button',
$classes,
$attr,
$content
);
