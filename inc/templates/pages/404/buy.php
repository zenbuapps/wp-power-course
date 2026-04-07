<?php
/**
 * 還沒購買課程
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
$course_permalink_structure = CourseUtils::get_course_permalink_structure();

echo '<div class="leading-7 text-base-content w-full mx-auto  px-0 md:px-6 text-base font-normal pt-0 lg:pt-[5rem] pb-[10rem]" style="max-width: 1200px;">';
Plugin::load_template(
	'alert',
	[
		'type'    => 'error',
		'message' => '您還沒購買此課程，無法上課',
		'buttons' => sprintf(
			/*html*/'<a  href="%1$s" target="_blank" class="pc-btn pc-btn-sm pc-btn-primary text-white">%2$s</a>',
				site_url( "{$course_permalink_structure}/{$product->get_slug()}" ),
				'前往購買',
				),
	]
);
Plugin::load_template(
	'course-product/header',
	[
		'show_link' => true,
	]
	);
echo '</div>';
