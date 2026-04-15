<?php
/**
 * Bundle product card
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'product' => null, // BundleProduct
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
] = $args;

if ( ! ( $product instanceof \WC_Product_Subscription ) ) {
	return;
}

$product_name = $product->get_name();

$purchase_note = \wpautop( $product->get_purchase_note() );

$bundle_type_label = $product->get_meta( 'bundle_type_label' );

$is_on_sale      = $product->is_on_sale();
$date_on_sale_to = $product->get_date_on_sale_to()?->date('Y/m/d H:i');

echo '<div class="w-full bg-base-100 shadow-lg rounded p-6">';
printf(
/*html*/'
  <p class="text-xs text-center mb-1 text-error">%1$s</p>
	<h6 class="text-base text-base-content font-semibold text-center">%2$s</h6>
',
	(string) $bundle_type_label,
	$product_name
);

Plugin::load_template( 'divider' );

printf(
/*html*/'<div class="mb-6 text-sm">%s</div>',
	$purchase_note
);


echo '<div class="flex flex-wrap gap-3 justify-between items-end">';

Plugin::load_template(
	'price',
	[
		'product' => $product,
		'size'    => 'small',
	]
);

Plugin::load_template(
	'button/add-to-cart',
	[
		'product'       => $product,
		'type'          => 'primary',
		'class'         => 'px-6 text-white ',
		'wrapper_class' => '[&_a.wc-forward]:tw-hidden',
	]
);

echo '</div>';

if ($is_on_sale && $date_on_sale_to) {
	printf(
	/*html*/'<p class="text-gray-500 text-xs text-center mt-2 mb-0">%s</p>',
	sprintf(
		/* translators: %s: 優惠結束日期時間 */
		\esc_html__( 'Limited-time offer until %s', 'power-course' ),
		\esc_html( $date_on_sale_to )
	)
	);
}

echo '</div>';
