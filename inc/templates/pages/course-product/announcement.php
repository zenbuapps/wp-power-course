<?php
/**
 * Course Product > Announcement Section
 *
 * 顯示在銷售頁「價格與購買按鈕之後、Tab 導覽列之前」的公告區塊。
 * 使用手風琴排版（純 CSS），最新一則預設展開、其餘折疊。
 */

use J7\PowerCourse\Resources\Announcement\Service\Query as AnnouncementQuery;

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

$course_id = (int) $product->get_id();
$user_id   = (int) \get_current_user_id();

/** @var array<int, array<string, mixed>> $announcements */
$announcements = AnnouncementQuery::list_public( $course_id, $user_id );

if ( empty( $announcements ) ) {
	return;
}

echo '<section id="pc-announcement-section" class="mb-8">';
printf(
	'<h3 class="mb-4 text-xl font-normal text-base-content">%s</h3>',
	esc_html__( 'Announcements', 'power-course' )
);

echo '<div class="pc-announcement-list">';
foreach ( $announcements as $index => $announcement ) {
	$announcement_id   = (int) ( $announcement['id'] ?? 0 );
	$post_title        = (string) ( $announcement['post_title'] ?? '' );
	$post_content      = (string) ( $announcement['post_content'] ?? '' );
	$post_date_display = isset( $announcement['post_date'] )
		? \wp_date( \get_option( 'date_format' ), strtotime( (string) $announcement['post_date'] ) )
		: '';

	// 第一則（最新）預設展開（checked），其餘折疊
	$checked = 0 === $index ? 'checked="checked"' : '';
	$expanded = 0 === $index ? 'true' : 'false';

	printf(
		/* html */ '
<div class="pc-announcement-item pc-collapse pc-collapse-arrow rounded-none mb-1" data-announcement-id="%1$d">
	<input type="checkbox" %2$s aria-expanded="%6$s" />
	<div class="pc-collapse-title text-sm font-semibold bg-base-300 py-3 flex items-center justify-between gap-2">
		<span class="font-semibold">%3$s</span>
		<time class="text-xs text-base-content/60 font-normal">%4$s</time>
	</div>
	<div class="pc-collapse-content bg-base-200 p-0">
		<div class="text-sm border-t-0 border-x-0 border-b border-gray-200 border-solid py-6 flex flex-col px-8 leading-7">
			%5$s
		</div>
	</div>
</div>',
		(int) $announcement_id,
		$checked, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內部產生
		esc_html( $post_title ),
		esc_html( $post_date_display ),
		\wpautop( wp_kses_post( $post_content ) ),
		esc_attr( $expanded )
	);
}
echo '</div>';
echo '</section>';
