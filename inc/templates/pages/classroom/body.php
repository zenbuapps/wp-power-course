<?php
/**
 * Body of the classroom page.
 */

use J7\PowerCourse\Plugin;
use J7\Powerhouse\Plugin as Powerhouse;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
	'chapter' => $GLOBALS['chapter'],
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
	'chapter' => $chapter,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

/** @var \WP_Post $chapter */
$chapter_id = $chapter->ID;

/**
 * @var array{type: string, id: string, meta: ?array} $video_info
 */
// @phpstan-ignore-next-line
$video_info = \get_post_meta( $chapter_id, 'chapter_video', true );


$next_post_id  = ChapterUtils::get_next_post_id( $chapter_id );
$next_post_url = $next_post_id ? ( \get_permalink( $next_post_id ) ?: '' ) : '';

echo '<div id="pc-classroom-body" class="w-full bg-base-100 lg:pl-[25rem]">';

Plugin::load_template(
	'classroom/header',
	[
		'product' => $product,
		'chapter' => $chapter,
	]
	);

echo /*html*/'
<div class="h-10 w-full bg-base-200 flex lg:tw-hidden items-center justify-between px-4 sticky top-[52px] left-0 z-30" style="border-bottom: 1px solid var(--fallback-bc,oklch(var(--bc)/.1))">
	<label for="pc-classroom-drawer" class="flex items-center gap-x-2 text-sm cursor-pointer">
		<svg class="size-4 stroke-base-content/30" viewBox="0 0 48 48" fill="none">
			<path d="M42 9H6" stroke="#78716c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
			<path d="M34 19H6" stroke="#78716c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
			<path d="M42 29H6" stroke="#78716c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
			<path d="M34 39H6" stroke="#78716c" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"></path>
		</svg>
		選單
	</label>
</div>
';

$is_admin_preview = CourseUtils::is_admin_preview($product->get_id());
if ($is_admin_preview) {
	echo /*html*/'<div class="text-center text-sm text-white bg-orange-500 py-1 w-full sticky top-[92px] lg:top-16 z-30">此為管理員預覽模式</div>';
}

printf(
/*html*/'<div class="pc-classroom-body__video z-[15] sticky w-full lg:relative lg:top-[unset] %s">',
$is_admin_preview ? 'top-[120px]' : 'top-[92px]'
);
Plugin::load_template(
	'video',
	[
		'video_info'    => $video_info,
		'class'         => sprintf('rounded-none %s', $next_post_url ? 'has-next-post' : ''),
		'next_post_url' => $next_post_url,
		'chapter_id'    => $chapter_id,
	]
);
echo '</div>';

echo '<div class="bg-base-200 px-4 lg:px-12 py-4">';
Plugin::load_template( 'progress' );
echo '</div>';



echo '<div class="tw-container mx-auto mt-8">';

Powerhouse::load_template('breadcrumb');

$editor = get_post_meta($chapter->ID, 'editor', true) ?: 'power-editor';

/** @deprecated Powerhouse 3.3.0 之後其實是不需要用 class 包裹的，這邊是為了兼容舊版本 */
printf(
/*html*/'<div class="%s">',
$editor === 'power-editor' ? 'power-editor' : ''
);
the_content();
echo '</div>';




// 留言
$enable_comment = \wc_string_to_bool( (string) ( \get_post_meta( $chapter_id, 'enable_comment', true ) ?: 'no' ) );
if ($enable_comment) {
	printf(
			/*html*/'<div id="comment-app" data-comment_type="comment" data-post_id="%1$s" data-show_list="%2$s" data-show_form="%3$s" data-user_id="%4$s" data-user_role="%5$s"></div>',
			$chapter_id,
			'yes', // $product->get_meta( 'show_comment_list' ) === 'yes' ? 'yes' : 'no',
			'yes',
			\get_current_user_id(),
			\current_user_can('manage_woocommerce') ? 'admin' : 'user',
	);
}


echo /*html*/'<div class="pc-divider my-6"></div>';

Powerhouse::load_template('related-posts/children');
Plugin::load_template('related-posts/prev-next');

echo /*html*/'<div class="pc-divider mt-6"></div>';

printf(
/*html*/'<p class="text-sm text-base-content/75">最近修改：%1$s</p>
',
get_the_modified_time('Y-m-d H:i')
);

echo '</div>'; // end container
echo '</div>';

printf(
/*html*/'
<dialog id="finish-chapter__dialog" class="pc-modal">
	<div class="pc-modal-box">
		<h3 id="finish-chapter__dialog__title" class="text-lg font-bold"></h3>
		<p id="finish-chapter__dialog__message" class="py-4"></p>
		<div class="pc-modal-action">
			<form method="dialog">
				<button class="pc-btn pc-btn-sm pc-btn-primary text-white px-4">關閉</button>
			</form>
		</div>
	</div>
	<form method="dialog" class="pc-modal-backdrop">
		<button class="opacity-0">close</button>
	</form>
</dialog>
'
);
