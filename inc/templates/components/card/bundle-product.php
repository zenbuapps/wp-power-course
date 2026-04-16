<?php
/**
 * Bundle product card
 */

use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Plugin;
use J7\Powerhouse\Domains\Product\Utils\Subscription;

$default_args = [
	'product' => null, // BundleProduct
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

/** @var array{product: \WC_Product} $args */
[
	'product' => $product,
] = $args;

$helper = Helper::instance( $product );
if ( ! $helper?->is_bundle_product ) {
	return;
}


$pbp_product_ids  = $helper?->get_product_ids_with_compat() ?? []; // @phpstan-ignore-line
$pbp_quantities   = $helper->get_product_quantities();

$product_name = $product->get_name();

$purchase_note = \wpautop( $product->get_purchase_note() );

$bundle_type_label = $product->get_meta( 'bundle_type_label' );

$is_on_sale      = $product->is_on_sale();
$date_on_sale_to = $product->get_date_on_sale_to()?->date('Y/m/d H:i');

$image_id  = (int) $product->get_image_id();
$image_url = \wp_get_attachment_image_url($image_id, 'full');

// 確認是否可以購買 以及還有沒有庫存
$in_stock_and_purchasable = $product->is_purchasable() && $product->is_in_stock();
$checkout_url             = \wc_get_checkout_url();
$url                      = \add_query_arg(
	[
		'add-to-cart' => $product->get_id(),
	],
	$checkout_url
);

echo '<div class="w-full bg-base-100 shadow-lg rounded">';

if ($image_url) {
	printf(
	/*html*/'
	<img src="%1$s" alt="%2$s" class="w-full rounded-t" loading="lazy" decoding="async">
	',
	$image_url,
	$product_name
	);
}

echo '<div class="p-6">';

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

foreach ( $pbp_product_ids as $pbp_product_id ) :
	if (!is_numeric($pbp_product_id)) {
		continue;
	}
	$pbp_product = \wc_get_product( $pbp_product_id );
	$pbp_qty     = (int) ( $pbp_quantities[ (string) $pbp_product_id ] ?? 1 );
	echo '<div>';
	Plugin::load_template(
		'course-product/list',
		[
			'product'  => $pbp_product,
			'quantity' => $pbp_qty,
		]
		);
	echo '</div>';
	Plugin::load_template( 'divider' );
endforeach;

Plugin::load_template(
	'customer_amount',
	[
		'product' => $product,
	]
);

Plugin::load_template(
	'stock',
	[
		'product' => $product,
	]
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
'button',
[
	'type'     => 'primary',
	'children' => \esc_html__( 'Enroll now', 'power-course' ),
	'disabled' => ! $in_stock_and_purchasable,
	'class'    => $in_stock_and_purchasable ? 'pc-add-to-cart-link flex-1 text-white px-0' : 'pc-add-to-cart-link flex-1 cursor-not-allowed',
	'href'     => $in_stock_and_purchasable ? $url : '',
],
);

Plugin::load_template(
'button/add-to-cart',
[
	'product'       => $product,
	'children'      => '',
	'type'          => 'primary',
	'outline'       => true,
	'icon'          => 'shopping-bag',
	'shape'         => 'square',
	'wrapper_class' => '[&_a.wc-forward]:tw-hidden',
	'class'         => '',
],
);


echo '</div>';


if ('subscription' === $product->get_type()) {
	$product_meta_data = Subscription::get_meta_data_label( $product );
	echo '<div class="grid grid-cols-2 gap-y-1.5 mt-2">';
	foreach ($product_meta_data as $key => $meta_data) {
		printf(
		/*html*/'<span class="text-gray-500 text-xs %1$s">- %2$s</span>',
		$key % 2 === 0 ? 'text-left' : 'text-right',
		$meta_data
		);
	}
	echo '</div>';
}


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
echo '</div>';
