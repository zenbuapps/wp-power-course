<?php

/**
 * Course Product > Announcement Section
 *
 * 顯示在銷售頁「價格與購買按鈕之後、Tab 導覽列之前」的公告區塊。
 * 使用 DaisyUI alert 風格，所有公告直接展開顯示。
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

echo '<section id="pc-announcement-section" class="mb-8">';
printf(
	'<h3 class="pc-title mb-8 text-xl font-normal text-base-content">%s</h3>',
	esc_html__('Announcements', 'power-course')
);

echo '<div class="pc-announcement-list flex flex-col gap-2">';
foreach ($announcements as $announcement) {
	$announcement_id   = (int) ( $announcement['id'] ?? 0 );
	$post_title        = (string) ( $announcement['post_title'] ?? '' );
	$post_content      = (string) ( $announcement['post_content'] ?? '' );
	$post_date_display = isset($announcement['post_date'])
	? \wp_date(\get_option('date_format'), strtotime( (string) $announcement['post_date']))
	: '';

	printf(
		/* html */
		'
<div role="alert" class="pc-alert" data-announcement-id="%1$d">
	<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-info h-6 w-6 shrink-0 self-start mt-0">
		<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
	</svg>
	<div class="flex flex-col gap-0 w-full">
		<span class="font-semibold text-base">%2$s</span>
		<div class="text-sm leading-7">%4$s</div>
		<time class="text-xs text-base-content/60 whitespace-nowrap self-end mt-0">%3$s</time>
	</div>
</div>',
		(int) $announcement_id,
		esc_html($post_title),
		esc_html($post_date_display),
		\wpautop(wp_kses_post($post_content))
	);
}
echo '</div>';
echo '</section>';
