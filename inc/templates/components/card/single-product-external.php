<?php
/**
 * 外部課程 Sidebar 卡片
 * 取代一般課程的 single-product-sale.php
 * CTA 按鈕直接導向外部連結，不走購物車流程
 */

use J7\PowerCourse\Plugin;

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

if ( ! ( $product instanceof \WC_Product_External ) ) {
	return;
}

$product_url = $product->get_product_url();
$button_text = $product->get_button_text() ?: '前往課程';
$has_url     = ! empty( $product_url );

printf(
/*html*/'
<div class="w-full bg-base-100 shadow-lg rounded p-6">
	<h6 class="text-base text-base-content font-semibold text-center">%1$s</h6>
	%2$s
	<div class="mt-8">%3$s</div>
	<div class="mt-8">
		%4$s
	</div>
</div>
',
	\esc_html( $button_text ),
	Plugin::load_template( 'divider', null, false ),
	Plugin::load_template(
		'price',
		[
			'product' => $product,
		],
		false
	),
	$has_url
		? sprintf(
			/*html*/'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="pc-btn pc-btn-primary text-white w-full text-center block">%2$s</a>',
			\esc_url( $product_url ),
			\esc_html( $button_text )
		)
		: sprintf(
			/*html*/'<button disabled class="pc-btn pc-btn-primary text-white w-full cursor-not-allowed opacity-50">%s</button>',
			\esc_html( $button_text )
		)
);
