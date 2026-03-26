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

echo '<div class="flex-1 px-4 md:px-0">';
echo '<div class="mb-12">';

$is_avl = CourseUtils::is_avl( $product->get_id() );
if ( $is_avl ) {
	Plugin::load_template(
		'alert',
	[
		'type'    => 'info',
		'message' => '您已經購買課程',
		'buttons' => $classroom_permalink ? sprintf(
			/*html*/'<a  href="%1$s" target="_blank" class="pc-btn pc-btn-sm pc-btn-primary text-white">%2$s</a>',
				$classroom_permalink,
				'前往教室',
				) : '',
		] // phpcs:ignore
	);
}



$course_schedule_in_timestamp = $product->get_meta( 'course_schedule' );
$course_schedule              = $course_schedule_in_timestamp ? \wp_date(
			'Y/m/d H:i',
			(int) $course_schedule_in_timestamp
		) : '未設定';
$course_hour                  = (int) $product->get_meta( 'course_hour' );
$course_minute                = (int) $product->get_meta( 'course_minute' );

$count_all_chapters = count( ChapterUtils::get_flatten_post_ids( $product->get_id() ) );


$total_student = ( UserUtils::count_student( $product->get_id() ) ) + ( (int) $product->get_meta( 'extra_student_count' ) );
$limit_labels  = Limit::instance($product)->get_limit_label();

$items = [
	[
		'icon'     => 'calendar',
		'label'    => '開課時間',
		'value'    => $course_schedule,
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_schedule' ) ?: 'yes'),
	],
	[
		'icon'     => 'clock',
		'label'    => '課程時長',
		'value'    => "{$course_hour} 小時 {$course_minute} 分",
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_time' ) ?: 'yes'),
	],
	[
		'icon'     => 'list',
		'label'    => '章節數量',
		'value'    => "{$count_all_chapters} 個",
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_chapters' ) ?: 'yes'),
	],
	[
		'icon'     => 'eye',
		'label'    => '觀看時間',
		'value'    =>"{$limit_labels->type} {$limit_labels->value}",
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_course_limit' ) ?: 'yes'),
	],
	[
		'icon'     => 'team',
		'label'    => '課程學員',
		'value'    => "{$total_student} 人",
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_total_student' ) ?: 'yes'),
	],
];

$items = array_filter($items, fn( $item ) => !( $item['disabled'] ));

if ($items) {
	Plugin::load_template(
	'typography/title',
	[
		'value' => '課程資訊',
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

// 獲取課程方案數量
$linked_products = Helper::get_bundle_products( (int) $product->get_id() );
$variation_count = count($linked_products);

// 添加固定在底部的mobile元素
$price_html = CRUD::get_price_html( $product );
printf(
/*html*/'
<div class="p-4 md:hidden tw-fixed bottom-0 left-0 right-0 w-full bg-white border-t border-gray-200 z-50">
    <div class="container mx-auto flex items-center justify-between gap-4">
        <div class="pc-price-html">
            %2$s
        </div>
        <a href="%1$s"
            class="flex-1 pc-btn pc-btn-primary text-white cursor-pointer text-center">
            立即報名
        </a>
    </div>
</div>
',
$variation_count > 0 ? '#course-pricing' : esc_url(add_query_arg('add-to-cart', $product->get_id(), wc_get_checkout_url())),
$price_html
);
