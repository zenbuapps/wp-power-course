<?php
/**
 * Course Product > Announcement Section
 *
 * 顯示在銷售頁「價格與購買按鈕之後、Tab 導覽列之前」的公告區塊。
 * 使用 DaisyUI alert 風格。
 *
 * Issue #224：當公告內文渲染後超過 N 行（預設 3）時，
 * 卡片內文會被 CSS `-webkit-line-clamp` 折疊並提供「展開全文 / 收合」切換。
 * 行數可透過 apply_filters( 'pc_announcement_collapse_lines', 3 ) 客製。
 */

use J7\PowerCourse\Resources\Announcement\Service\Query as AnnouncementQuery;

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

$course_id = (int) $product->get_id();
$user_id   = (int) \get_current_user_id();

/** @var array<int, array<string, mixed>> $announcements */
$announcements = AnnouncementQuery::list_public($course_id, $user_id);

if (empty($announcements)) {
	return;
}

/**
 * 公告卡片內文折疊行數
 *
 * Issue #224：銷售頁公告卡片內文超過 N 視覺行時折疊。
 * 預設 3 行；非整數 / 負數 / 0 一律以 max(1, (int) $value) 收斂為 ≥ 1 整數，
 * 避免 CSS line-clamp 失效或產生負數高度。
 *
 * @param int $lines 預設 3 行。
 */
$collapse_lines = (int) \apply_filters('pc_announcement_collapse_lines', 3);
$collapse_lines = max(1, $collapse_lines);

echo '<section id="pc-announcement-section" class="mb-8">';
printf(
	'<h3 class="pc-title mb-8 text-xl font-normal text-base-content">%s</h3>',
	esc_html__('Announcements', 'power-course')
);

/*
* 內聯 CSS：折疊樣式
*
* 不依賴 Tailwind safelist（本專案無 tailwind.config.js 自訂 line-clamp utility），
* 也避免新增 entry point 到 Vite。
*
* - `.pc-announcement-content` 預設套 -webkit-line-clamp，瀏覽器原生跨平台支援。
* - aria-expanded="true" 時切換為 display:block，line-clamp 解除，整段顯示。
*/
echo '<style>
.pc-announcement-content {
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: var(--pc-collapse-lines, 3);
	overflow: hidden;
}
.pc-announcement-content[aria-expanded="true"] {
	display: block;
	-webkit-line-clamp: unset;
	overflow: visible;
}
</style>';

echo '<div class="pc-announcement-list flex flex-col gap-2">';
foreach ($announcements as $announcement) {
	$announcement_id   = (int) ( $announcement['id'] ?? 0 );
	$post_title        = (string) ( $announcement['post_title'] ?? '' );
	$post_content      = (string) ( $announcement['post_content'] ?? '' );
	$post_date_display = isset($announcement['post_date'])
	? \wp_date(\get_option('date_format'), strtotime( (string) $announcement['post_date']))
	: '';
	$content_dom_id    = sprintf('pc-announcement-content-%d', $announcement_id);

	printf(
		/* html */
		'
<div role="alert" class="pc-alert" data-announcement-id="%1$d">
	<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-info h-6 w-6 shrink-0 self-start mt-0">
		<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
	</svg>
	<div class="flex flex-col gap-0 w-full">
		<span class="font-semibold text-base">%2$s</span>
		<div
			id="%6$s"
			class="pc-announcement-content text-sm leading-7 relative"
			style="--pc-collapse-lines: %7$d;"
			data-collapse-lines="%7$d"
			aria-expanded="false"
		>%4$s</div>
		<div class="pc-announcement-toggle w-full relative text-sm text-primary flex justify-center items-center font-semibold" hidden data-target="%6$s">
			<button type="button" class="pc-announcement-toggle__btn relative py-1 px-2 cursor-pointer bg-transparent border-0 text-primary" aria-controls="%6$s" aria-expanded="false">%5$s</button>
		</div>
		<time class="text-xs text-base-content/60 whitespace-nowrap self-end mt-0">%3$s</time>
	</div>
</div>',
		(int) $announcement_id,
		esc_html($post_title),
		esc_html($post_date_display),
		\wpautop(wp_kses_post($post_content)),
		esc_html__('Expand content', 'power-course'),
		esc_attr($content_dom_id),
		(int) $collapse_lines
	);
}
echo '</div>';
echo '</section>';
