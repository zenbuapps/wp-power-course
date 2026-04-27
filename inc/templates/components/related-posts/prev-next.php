<?php
/**
 * 顯示樹狀結構的上一篇、下一篇文章 (不是扁平的上一篇下一篇)
 * 因為課程是商品，章節是文章
 * 不能用 powerhouse 的組件直接用
 */

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Utils\LinearViewing;

global $course;
global $post;

$prev_post_id = ChapterUtils::get_prev_post_id( $post->ID );
/** @var WP_Post|null $prev_post */
$prev_post    = $prev_post_id ? get_post($prev_post_id) : null;
$next_post_id = ChapterUtils::get_next_post_id( $post->ID );
/** @var WP_Post|null $next_post */
$next_post = $next_post_id ? get_post($next_post_id) : null;


echo '<div class="flex gap-x-2 md:gap-x-4">';

if ($prev_post) {
	/* translators: 3: 「上一個」標籤文字 */
	$prev_label = esc_html__( 'Previous', 'power-course' );
	printf(
	/*html*/'
	<a href="%1$s" class="pc-prev-post group w-full rounded-box border border-solid border-base-content/30 p-4 flex items-center gap-x-2 md:gap-x-4 relative" style="text-decoration: none;">
		<svg class="size-4 md:size-6 stroke-base-content group-hover:stroke-primary" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g> <path d="M4 12H20M4 12L8 8M4 12L8 16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> </g></svg>
		<div class="flex-1 pt-6">
			<p class="m-0 text-sm md:text-base text-base-content group-hover:text-primary">%2$s</p>
		</div>
		<p class="m-0 text-xs md:text-sm text-base-content/50 absolute top-4 left-10 md:left-14">%3$s</p>
	</a>
	',
	\esc_url( get_the_permalink($prev_post->ID) ),
	\esc_html( $prev_post->post_title ),
	$prev_label
	);
}

if ($next_post) {
	// 判斷下一章是否鎖定
	$is_next_locked = false;
	$user_id        = get_current_user_id();
	if ( $course && $user_id ) {
		$course_id = $course->get_id();
		if ( LinearViewing::is_enabled( $course_id ) && ! LinearViewing::is_exempt( $user_id ) ) {
			$is_next_locked = LinearViewing::is_chapter_locked( $next_post_id, $course_id, $user_id );
		}
	}

	/* translators: 3: 「下一個」標籤文字 */
	$next_label     = esc_html__( 'Next', 'power-course' );
	$disabled_class = $is_next_locked ? ' pc-btn-disabled pointer-events-none opacity-50' : '';
	$disabled_attr  = $is_next_locked ? ' aria-disabled="true"' : '';
	$href           = $is_next_locked ? 'javascript:void(0)' : \esc_url( get_the_permalink($next_post->ID) );
	$data_locked    = $is_next_locked ? 'true' : 'false';

	printf(
	/*html*/'
	<a href="%1$s" class="pc-next-post group w-full rounded-box border border-solid border-base-content/30 p-4 flex items-center gap-x-2 md:gap-x-4 relative%5$s" data-next-locked="%6$s"%7$s>
		<div class="flex-1 text-right pt-6">
			<p class="m-0 text-sm md:text-base text-base-content group-hover:text-primary">%2$s</p>
		</div>
		<svg class="size-4 md:size-6 stroke-base-content group-hover:stroke-primary" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g> <path d="M4 12H20M20 12L16 8M20 12L16 16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path> </g></svg>
		<p class="m-0 text-xs md:text-sm text-base-content/50 absolute top-4 right-10 md:right-14">%3$s</p>
	</a>
	',
	$href,
	\esc_html( $next_post->post_title ),
	$next_label,
	'', // 保留第 4 個佔位符未使用
	$disabled_class,
	$data_locked,
	$disabled_attr
	);

	// 鎖定時在按鈕下方顯示提示
	if ( $is_next_locked ) {
		printf(
			'<p class="text-xs text-base-content/50 text-center mt-2">%s</p>',
			esc_html__( 'Complete this chapter to unlock the next one', 'power-course' )
		);
	}
}


echo '</div>';
