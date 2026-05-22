<?php
/**
 * 顯示庫存組件
 */

use J7\Powerhouse\Domains\Woocommerce\Model\Settings as WCSettings;

$default_args = [
	'product'         => $GLOBALS['course'] ?? null,
	'class'           => 'mt-1',
	'show_rest_stock' => null, // 可選的覆寫值：null 時維持原行為（從 $product meta 讀），非 null 時改用此傳入值
];

/**
	* @var array{product: \WC_Product, class: string, show_rest_stock: string|bool|null} $args
	* @phpstan-ignore-next-line
	*/
$args = wp_parse_args( $args, $default_args );

[
	'product'         => $product,
	'class'           => $class,
	'show_rest_stock' => $show_rest_stock_arg,
] = $args;

$managing_stock = $product->managing_stock();

if (!$managing_stock) {
	return;
}


// 「庫存顯示」閘門：未傳入 show_rest_stock 參數時維持原行為（從 $product 自身 meta 讀）；
// 有傳入時改用傳入值（例如 bundle 卡片改讀所屬課程商品的 show_rest_stock）。
$show_rest_stock = ( $show_rest_stock_arg === null )
? wc_string_to_bool( (string) $product->get_meta('show_rest_stock') )
: wc_string_to_bool( (string) $show_rest_stock_arg );

if (!$show_rest_stock) {
	return;
}

$product_low_stock_amount = $product->get_low_stock_amount(); // 個別商品 override 低庫存通知數量

$notify_low_stock_amount = ( $product_low_stock_amount === '' ) ? (int) WCSettings::instance()->notify_low_stock_amount : (int) $product_low_stock_amount; // 檢查 個別商品是否 override 低庫存通知數量，如果沒有則使用設定值

$stock_quantity = $product->get_stock_quantity();

$color_class = 'bg-green-100 text-green-500';
if ($stock_quantity <= $notify_low_stock_amount) {
	$color_class = 'bg-red-100 text-red-500';
}

if ($stock_quantity <= 0) {
	$color_class = 'bg-gray-100 text-gray-500';
}

$stock_label = sprintf(
	/* translators: %s: 庫存數量 */
	esc_html__( '%s left in stock', 'power-course' ),
	esc_html( (string) $stock_quantity )
);
printf(
	'<div class="%1$s"><span class="px-2 py-1 %2$s text-xs rounded-md font-bold">%3$s</span></div>',
	esc_attr( $class ),
	esc_attr( $color_class ),
	$stock_label
);
