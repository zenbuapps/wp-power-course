<?php
/**
 * Button component
 */

use J7\PowerCourse\Plugin;

$default_props = [
	'type'          => '', // primary | secondary | neutral | link | ghost | accent | info | success | warning | error
	'outline'       => false,
	'size'          => '', // xs | sm  | lg
	'children'      => \esc_html__( '加入購物車', 'power-course' ),
	'icon'          => '',
	'icon_position' => 'start', // start | end
	'disabled'      => false,
	'href'          => '#',
	'class'         => '',
	'active'        => false,
	'glass'         => false,
	'attr'          => '',
	'shape'         => '', // square | circle
	'loading'       => false,
	'product'       => null, // 🆕  WC_Product
	'qty'           => 1, // 🆕
	'wrapper_class' => '[&_.added]:tw-hidden', // 🆕
];

/** @var array<string, mixed> $args */
$args = $args ?? [];
$args    = wp_parse_args( $args, $default_props );
$product = $args['product'];
if (!( $product instanceof \WC_Product )) {
	return;
}

$wrapper_class  = $args['wrapper_class'];
$args['href']   = '#';
$args['class'] .= ' product_type_simple add_to_cart_button ajax_add_to_cart cursor-pointer ';
$args['attr']  .= sprintf(
	/* translators: %s: 商品名稱 */
	' data-product_id="%1$s" data-quantity="%2$s" data-product_sku="%3$s" aria-label="%5$s" aria-describedby="" rel="nofollow" ',
	$product->get_id(),
	$args['qty'],
	$product->get_sku(),
	$product->get_name(),
	\esc_attr(
		sprintf(
			/* translators: %s: 商品名稱 */
			\__( '加入購物車：「%s」', 'power-course' ),
			$product->get_name()
		)
	)
);

unset($args['product'], $args['wrapper_class'], $args['qty']);

// 確認是否可以購買 以及還有沒有庫存
$in_stock_and_purchasable = $product->is_purchasable() && $product->is_in_stock();
if ( ! $in_stock_and_purchasable ) {
	$args['disabled'] = true;
	$args['outline']  = false;
	$args['class']   .= ' !pointer-events-none ';
	// text-white 替代為 ''
	$args['class']  = str_replace( 'text-white', '', $args['class'] );
	$wrapper_class .= ' !pointer-events-none ';
}

$button_html = Plugin::load_template(
	'button',
	$args,
	false
);

if (!$in_stock_and_purchasable) {
	echo '<div class="cursor-not-allowed">';
}
printf(
/*html*/'<div class="pc-add-to-cart whitespace-nowrap %1$s">%2$s</div>',
$wrapper_class,
$button_html
);

if (!$in_stock_and_purchasable) {
	echo '</div>';
}
