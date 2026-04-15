<?php
/**
 * 課程還沒開始
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;

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

$message = CourseUtils::get_expired_label($product, get_current_user_id());


echo '<div class="leading-7 text-base-content w-full mx-auto  px-0 md:px-6 text-base font-normal pt-0 lg:pt-[5rem] pb-[10rem]" style="max-width: 1200px;">';
Plugin::load_template(
	'alert',
	[
		'type'    => 'error',
		'message' => $message,
	]
);
Plugin::load_template(
	'course-product/header',
	[
		'show_link' => true,
	]
	);
echo '</div>';
