<?php
/**
 * Countdown component
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

$regular_price = $product->get_regular_price();
$sale_price    = $product->get_sale_price();
$is_on_sale    = $product->is_on_sale();
$from          = $product->get_date_on_sale_from()?->getTimestamp();
$to            = $product->get_date_on_sale_to()?->getTimestamp();

if ($sale_price && $is_on_sale) {
	$discount = round($sale_price / $regular_price * 100);
	$discount = $discount % 10 === 0 ? $discount/10 : $discount;
} else {
	$discount = 0;
}

echo '<div class="mt-1">';
echo '<div class="flex gap-2 items-center text-sm">';
if ('' !== $sale_price && $regular_price && $is_on_sale) { // 沒有折扣價就不顯示 或 沒有一般價就不顯示
	$discount_text = $sale_price
	? sprintf(
			/* translators: %s: 折扣數字 */
			esc_html__( '%s off', 'power-course' ),
			$discount
		)
	: esc_html__( 'Free', 'power-course' );
	printf(
	/*html*/'
		<span class="px-2 py-1 bg-red-100 text-red-500 text-xs rounded-md font-bold">%1$s</span>
	',
	$discount_text,
	);
}

if ($from <= time() && time() < $to ) { // 折扣進行期間
	printf(
	/*html*/'
		%1$s
		%2$s
	',
	esc_html__( 'Remaining', 'power-course' ),
	Plugin::load_template(
		'countdown',
		[
			'type'      => 'sm',
			'timestamp' => $to,
		],
		false
	),
	);
}


echo '</div>';
echo '</div>';
