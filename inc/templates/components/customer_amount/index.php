<?php
/**
 * 顯示「已有 OO 名學員購買此方案」組件
 */

use J7\PowerCourse\BundleProduct\Helper;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
	* @var array{product: \WC_Product} $args
	* @phpstan-ignore-next-line
	*/
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
] = $args;

$total_sales = $product->get_total_sales();

$helper = Helper::instance( $product );
if ($helper?->is_bundle_product ) {
	$course_product = $helper->get_course_product();
	if (!$course_product) {
		$course_product = $product;
	}
} else {
	$course_product = $product;
}

$show_customer_amount = wc_string_to_bool( (string) $course_product->get_meta('show_customer_amount'));

if (!$total_sales || !$show_customer_amount) {
	return;
}

printf(
/*html*/'
<div class="mt-1 flex">
	<span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs rounded-md font-bold">%1$s</span>
</div>
',
sprintf(
	/* translators: %d: 學員人數 */
	esc_html__( '已有 %d 位學員購買此方案', 'power-course' ),
	$total_sales
)
);
