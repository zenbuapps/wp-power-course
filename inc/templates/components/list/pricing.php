<?php
/**
 * 帶有價格，用於銷售用的卡片列表
 */

use J7\PowerCourse\Plugin;


$default_args = [
	'products' => [],
	'columns'  => 3,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'products' => $products,
	'columns'  => $columns,
] = $args;

$grid_class = match ( (string) $columns) {
	'1' => 'grid-cols-1',
	'2' => 'grid-cols-2',
	'3' => 'grid-cols-2 lg:grid-cols-3',
	'4' => 'grid-cols-2 lg:grid-cols-4',
	default => 'grid-cols-2 lg:grid-cols-3', // Default to 2 columns base, 3 for large screens
};

echo "<div class='grid gap-x-5 gap-y-14 {$grid_class}'>";

foreach ($products as $product) {
	Plugin::load_template(
	'card/pricing',
	[
		'product' => $product,
	]
	);
}

echo '</div>';
