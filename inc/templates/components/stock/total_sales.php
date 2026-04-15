<?php
/**
 * 顯示已售總數
 */

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
	'class'   => 'mt-1',
];

/**
	* @var array{product: \WC_Product, class: string} $args
	* @phpstan-ignore-next-line
	*/
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
	'class'   => $class,
] = $args;

$show_total_sales = \wc_string_to_bool( (string) $product->get_meta('show_total_sales'));

if (!$show_total_sales) {
	return;
}



$total_sales = $product->get_total_sales();

$color_class = 'bg-red-100 text-red-500';


$sold_label = sprintf(
	/* translators: %s: 已售出數量 */
	esc_html__( '%s sold', 'power-course' ),
	esc_html( (string) $total_sales )
);
printf(
	'<div class="%1$s"><span class="px-2 py-1 %2$s text-xs rounded-md font-bold">%3$s</span></div>',
	esc_attr( $class ),
	esc_attr( $color_class ),
	$sold_label
);
