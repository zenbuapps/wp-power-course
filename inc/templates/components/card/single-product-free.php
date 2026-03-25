<?php
/**
 * Single Product Card for free product
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

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

// 確認是否可以購買 以及還有沒有庫存
$in_stock_and_purchasable = $product->is_purchasable() && $product->is_in_stock();

$purchase_note = \wpautop( $product->get_purchase_note() );
$checkout_url  = \wc_get_checkout_url();
$url           = \add_query_arg(
			[
				'add-to-cart' => $product->get_id(),
			],
			$checkout_url
		);

printf(
/*html*/'
<div class="w-full bg-base-100 shadow-lg rounded p-6">
	<h6 class="text-base text-base-content font-semibold text-center">免費課程</h6>
	%1$s
	%2$s
	<div class="mt-8 mb-6 text-sm">%3$s</div>
	<div class="flex gap-3">%4$s %5$s</div>
</div>
',
Plugin::load_template( 'divider', null, false ),
Plugin::load_template(
	'countdown/sales',
	[
		'product' => $product,
	],
	false
	),
$purchase_note,
Plugin::load_template(
	'button',
	[
		'type'     => 'primary',
		'children' => '立即購買',
		'disabled' => ! $in_stock_and_purchasable,
		'class'    => $in_stock_and_purchasable ? 'pc-add-to-cart-link flex-1 text-white' : 'pc-add-to-cart-link flex-1',
		'href'     => $in_stock_and_purchasable ? $url : '',
	],
	false
),
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
	false
)
);
