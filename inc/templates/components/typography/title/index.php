<?php
/**
 * Title component
 */

$default_args = [
	'level' => 'h2', // 'h1', 'h2', 'h3', 'h4', 'h5', 'h6
	'value' => '標題',
	'class' => '',
];

/**
 * @var array{level: string, value: string, class: string} $args
 */
// @phpstan-ignore-next-line
$args = wp_parse_args( $args, $default_args );

$html = sprintf(
	'<%1$s class="pc-title %2$s">%3$s</%1$s>',
	$args['level'],
	$args['class'],
	$args['value'],
);

echo $html;
