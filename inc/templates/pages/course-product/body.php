<?php
/**
 * Course Product > body
 */

use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\User as UserUtils;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\Powerhouse\Domains\Product\Utils\CRUD;

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

$product_id          = $product->get_id();
$classroom_permalink = CourseUtils::get_classroom_permalink($product_id);
$is_external         = $product instanceof \WC_Product_External;

echo '<div class="flex-1 px-4 md:px-0">';
echo '<div class="mb-12">';

// 外部課程沒有「已購買」概念，跳過已購提示
$is_avl = ! $is_external && CourseUtils::is_avl( $product->get_id() );
if ( $is_avl ) {
	Plugin::load_template(
		'alert',
	[
		'type'    => 'info',
		'message' => esc_html__( 'You have already purchased this course', 'power-course' ),
		'buttons' => $classroom_permalink ? sprintf(
			/*html*/'<a  href="%1$s" target="_blank" class="pc-btn pc-btn-sm pc-btn-primary text-white">%2$s</a>',
				$classroom_permalink,
				esc_html__( 'Go to classroom', 'power-course' )
				) : '',
		] // phpcs:ignore
	);
}



$course_schedule_in_timestamp = $product->get_meta( 'course_schedule' );
$course_schedule              = $course_schedule_in_timestamp ? \wp_date(
			'Y/m/d H:i',
			(int) $course_schedule_in_timestamp
		) : esc_html__( 'Not set', 'power-course' );
$course_hour                  = (int) $product->get_meta( 'course_hour' );
$course_minute                = (int) $product->get_meta( 'course_minute' );

$count_all_chapters = count( ChapterUtils::get_flatten_post_ids( $product->get_id() ) );


$total_student = ( UserUtils::count_student( $product->get_id() ) ) + ( (int) $product->get_meta( 'extra_student_count' ) );
$limit_labels  = Limit::instance($product)->get_limit_label();

// 外部課程：所有統計項顯示「-」
$items = [
	[
		'icon'     => 'check',
		'label'    => esc_html__( 'All chapters published', 'power-course' ),
		'value'    => '',
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_complete' ) ?: 'no'),
	],
	[
		'icon'     => 'calendar',
		'label'    => esc_html__( 'Course start time', 'power-course' ),
		'value'    => $is_external ? '-' : $course_schedule,
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_schedule' ) ?: 'yes'),
	],
	[
		'icon'     => 'clock',
		'label'    => esc_html__( 'Course duration', 'power-course' ),
		'value'    => $is_external ? '-' : sprintf(
			/* translators: 1: 課程小時數, 2: 課程分鐘數 */
			esc_html__( '%1$s hours %2$s minutes', 'power-course' ),
			$course_hour,
			$course_minute
		),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_time' ) ?: 'yes'),
	],
	[
		'icon'     => 'list',
		'label'    => esc_html__( 'Chapter count', 'power-course' ),
		'value'    => $is_external ? '-' : sprintf(
			/* translators: %s: 章節數量 */
			esc_html__( '%s chapters', 'power-course' ),
			$count_all_chapters
		),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_chapters' ) ?: 'yes'),
	],
	[
		'icon'     => 'eye',
		'label'    => esc_html__( 'Watch time', 'power-course' ),
		'value'    => $is_external ? '-' : "{$limit_labels->type} {$limit_labels->value}",
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_limit' ) ?: 'yes'),
	],
	[
		'icon'     => 'team',
		'label'    => esc_html__( 'Students', 'power-course' ),
		'value'    => $is_external ? '-' : sprintf(
			/* translators: %s: 學員人數 */
			esc_html__( '%s students', 'power-course' ),
			$total_student
		),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_total_student' ) ?: 'yes'),
	],
];

$items = array_filter($items, fn( $item ) => !( $item['disabled'] ));

if ($items) {
	Plugin::load_template(
	'typography/title',
	[
		'value' => esc_html__( 'Course information', 'power-course' ),
		'class' => 'mb-8 text-xl font-normal text-base-content',
	]
	);

	Plugin::load_template(
			'course-product/info',
			$items
		);

}
// echo '<div class="mt-8 flex items-end gap-4">';
// Plugin::load_template(
// 'countdown',
// [
// 'type'       => 'lg',
// 'item_class' => '',
// ]
// );
// echo '<h2>後課程即將停賣</h2>';
// echo '</div>';

echo '</div>';
// Announcements (Issue #6)
Plugin::load_template( 'course-product/announcement', null, true, true );
// Tabs
Plugin::load_template( 'course-product/tabs', null, true, true );
// Footer
Plugin::load_template( 'course-product/footer', null, true, true );
echo '</div>';

// 是否啟用 enable_mobile_fixed_cta
$enable_mobile_fixed_cta = $product->get_meta( 'enable_mobile_fixed_cta' ) === 'yes';

if (!$enable_mobile_fixed_cta) {
	return;
}

// 添加固定在底部的mobile元素
$price_html = CRUD::get_price_html( $product );

if ( $is_external ) {
	// 外部課程：CTA 導向外部連結
	$external_product_url = $product->get_product_url();
	$external_button_text = $product->get_button_text() ?: esc_html__( 'Visit course', 'power-course' );
	$has_external_url     = ! empty( $external_product_url );

	printf(
	/*html*/'
<div class="p-4 md:hidden tw-fixed bottom-0 left-0 right-0 w-full bg-white border-t border-gray-200 z-50">
	<div class="container mx-auto flex items-center justify-between gap-4">
		<div class="pc-price-html">
			%2$s
		</div>
		%3$s
	</div>
</div>
',
		'',
		$price_html,
		$has_external_url
			? sprintf(
				/*html*/'<a href="%1$s" target="_blank" rel="noopener noreferrer" class="flex-1 pc-btn pc-btn-primary text-white cursor-pointer text-center">%2$s</a>',
				\esc_url( $external_product_url ),
				\esc_html( $external_button_text )
			)
			: sprintf(
				/*html*/'<button disabled class="flex-1 pc-btn pc-btn-primary text-white cursor-not-allowed opacity-50">%s</button>',
				\esc_html( $external_button_text )
			)
	);
} else {
	// 站內課程：一般購物流程
	$linked_products = Helper::get_bundle_products( (int) $product->get_id() );
	$variation_count = count($linked_products);

	printf(
	/*html*/'
<div class="p-4 md:hidden tw-fixed bottom-0 left-0 right-0 w-full bg-white border-t border-gray-200 z-50">
	<div class="container mx-auto flex items-center justify-between gap-4">
		<div class="pc-price-html">
			%2$s
		</div>
		<a href="%1$s"
			class="flex-1 pc-btn pc-btn-primary text-white cursor-pointer text-center">
			%3$s
		</a>
	</div>
</div>
',
	$variation_count > 0 ? '#course-pricing' : esc_url(add_query_arg('add-to-cart', $product->get_id(), wc_get_checkout_url())),
	$price_html,
	esc_html__( 'Enroll now', 'power-course' )
	);
}
