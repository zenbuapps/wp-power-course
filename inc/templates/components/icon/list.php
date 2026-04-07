<?php
/**
 * Icon: list
 */

use J7\PowerCourse\Utils\Base;

$default_args = [
	'type'  => '',
	'class' => 'size-6', // 可以用 tailwind 子選擇器覆寫路徑顏色 跟 透明度
	'color' => Base::PRIMARY_COLOR,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'class' => $class,
	'color' => $color,
] = $args;

printf(
	/*html*/'
	<svg class="%s" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
		<path stroke="%2$s" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8h15M12 16h9M12 24h15"/>
		<path fill="%2$s" d="M6 10a2 2 0 100-4 2 2 0 000 4zM6 18a2 2 0 100-4 2 2 0 000 4zM6 26a2 2 0 100-4 2 2 0 000 4z"/>
	</svg>',
	$class,
	$color
);
