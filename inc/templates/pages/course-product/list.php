<?php
/**
 * List item for course product
 */

use J7\PowerCourse\Utils\Base;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

$regular_price = (float) $product->get_regular_price();

$regular_price_html = $regular_price ? '<del class="tw-block text-xs text-gray-600">NT$' . $regular_price . '</del>' : '';

$product_name = $product->get_name();

$product_image_url = Base::get_image_url_by_product( $product );

$regular_price_html = \wc_price( $regular_price );

printf(
	'
<div class="grid grid-cols-[1fr_2fr] gap-5">
	<div class="group aspect-video rounded overflow-hidden">
		<img class="w-full h-full object-cover group-hover:scale-105 duration-500 transition ease-in-out" src="%1$s" alt="%2$s" loading="lazy" decoding="async">
	</div>
	<div>
		<h6 class="text-sm font-semibold mb-1">%2$s</h6>
		<del class="tw-block text-xs text-gray-600">%3$s</del>
	</div>
</div>',
	$product_image_url,
	$product_name,
	$regular_price_html
);
