<?php

/**
 * My Account 裡面上課用的卡片
 * 已登入，有上課進度，可直接上課
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Base;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args($args, $default_args);

[
	'product' => $product,
] = $args;

if (! ( $product instanceof \WC_Product )) {
	return;
}

$product_id  = $product->get_id();
$chapter_ids = ChapterUtils::get_flatten_post_ids($product_id);

$name              = $product->get_name();
$product_image_url = Base::get_image_url_by_product($product, 'full');
/** @var array<int, mixed> $teacher_ids */
$teacher_ids       = \get_post_meta($product_id, 'teacher_ids', false);
$teacher_name_list = '';
foreach ($teacher_ids as $key => $teacher_id) {
	$is_last           = $key === count($teacher_ids) - 1;
	$connect           = $is_last ? '' : ' & ';
	$teacher           = \get_user_by('id', (string) $teacher_id);
	$teacher_name_list .= ( $teacher instanceof \WP_User ) ? \esc_html($teacher->display_name) . $connect : $connect;
}
$teacher_name = count($teacher_ids) > 0
? sprintf(
		/* translators: %s: 講師名稱列表（可多位，中間以 & 連接） */
		\esc_html__('by %s', 'power-course'),
		$teacher_name_list
	)
: '&nbsp;';

$current_user_id = get_current_user_id();

$expire_date_label = CourseUtils::get_expired_label($product, $current_user_id);
$avl_status        = CourseUtils::get_avl_status($product, $current_user_id);

$badge_html = Plugin::load_template(
	'badge',
	[
		'type'     => $avl_status['badge_color'],
		'children' => $avl_status['label'],
		'class'    => 'absolute top-2 right-2 text-white text-xs z-20',
	],
	false
);

// ========== 三態 CTA 邏輯 ==========
// 依據 last_chapter_id + finished_at 決定 CTA 文字：
// 1. 未開始 → 「開始上課」
// 2. 進行中（last_chapter_id 有值且無 finished_at）→ 「繼續觀看 {章節名} MM:SS」
// 3. 已完成（last_chapter_id 有值且有 finished_at）→ 「重看 {章節名} MM:SS」

$progress_info    = CourseUtils::get_course_progress_info($product, $current_user_id);
$last_chapter_id  = $progress_info['last_chapter_id'];
$last_pos_seconds = (int) $progress_info['last_position_seconds'];

// 格式化秒數為 MM:SS.
$cta_minutes = (int) floor($last_pos_seconds / 60);
$cta_secs    = $last_pos_seconds % 60;
$time_str    = sprintf('%02d:%02d', $cta_minutes, $cta_secs);

if (! $last_chapter_id) {
	// 未開始：導向課程第一章節.
	$first_chapter_id = ! empty($chapter_ids) ? (int) $chapter_ids[0] : null;
	$cta_href         = $first_chapter_id
	? (string) \get_permalink($first_chapter_id)
	: (string) CourseUtils::get_classroom_permalink($product_id);
} else {
	// 有最後觀看章節：判斷是否已完成.
	$finished_at   = AVLChapterMeta::get( (int) $last_chapter_id, $current_user_id, 'finished_at', true);
	$chapter_title = \get_the_title( (int) $last_chapter_id);
	$cta_href = (string) \get_permalink( (int) $last_chapter_id);
}

if (! $cta_href) {
	$cta_href = (string) CourseUtils::get_classroom_permalink($product_id);
}

printf(
	/*html*/
	'
<div class="pc-course-card">
	<a href="%1$s">
		<div class="pc-course-card__image-wrap group">
			%2$s
			<img class="pc-course-card__image group-hover:scale-105 duration-500 transition ease-in-out" src="%3$s" alt="%4$s"  loading="lazy" decoding="async">
	  </div>
  </a>
	<a href="%1$s">
		<h3 class="pc-course-card__name">%4$s</h3>
	</a>
	<p class="pc-course-card__teachers">%5$s</p>
	<div>%6$s</div>
	<div class="flex gap-2 items-center">
		<span class="text-gray-400 text-xs text-nowrap">%8$s</span>
		<span class="text-primary text-xs text-nowrap font-bold">%7$s</span>
	</div>
</div>
',
	esc_url($cta_href),
	$badge_html,
	$product_image_url,
	\esc_html($name),
	$teacher_name,
	Plugin::load_template(
		'progress/vertical',
		[
			'product' => $product,
		],
		false
	),
	\esc_html($expire_date_label),
	\esc_html__('Watch period', 'power-course'),
	\esc_url($cta_href)
);
