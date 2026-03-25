<?php
/**
 * Classroom > header
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\FrontEnd\MyAccount;
use J7\PowerCourse\Resources\Chapter\Model\Chapter;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
	'chapter' => $GLOBALS['chapter'],
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = \wp_parse_args( $args, $default_args );

[
	'product' => $product,
	'chapter'    => $chapter,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

$product_id         = $product->get_id();
$current_chapter_id = $chapter->ID;

$back_to_my_course_html = sprintf(
	/*html*/'
		<a
			href="%1$s"
			class="hover:opacity-75 transition duration-300 contents lg:tw-hidden"
		>
			<img class="size-6 absolute left-2 top-4" src="%2$s" loading="lazy" decoding="async" />
		</a>
',
	\wc_get_account_endpoint_url( MyAccount::COURSES_ENDPOINT ),
	Plugin::$url . '/inc/assets/images/back.svg',
);

// finish button html
$user_id                    = \get_current_user_id();
$avl_chapter                = new Chapter( (int) $current_chapter_id, (int) $user_id );
$finished_at                = $avl_chapter->finished_at;
$is_this_chapter_finished   = (bool) $finished_at;
$finish_chapter_button_html = '';
$finish_chapter_button_html = sprintf(
		/*html*/'
		<button id="finish-chapter__button" data-course-id="%1$s" data-chapter-id="%2$s" class="pc-btn pc-btn-secondary pc-btn-sm px-0 lg:px-4 w-full lg:w-auto text-xs sm:text-base %3$s">
			<span>%4$s</span>
			<span class="pc-loading pc-loading-spinner w-3 sm:w-4 h-3 sm:h-4 tw-hidden"></span>
		</button>
		',
		$product_id,
		$current_chapter_id,
		$is_this_chapter_finished ? 'pc-btn-outline border-solid' : 'text-white',
		$is_this_chapter_finished ? '標示為未完成' : '標示為已完成'
	);


// next chapter button html
/** @var int[] $chapter_ids */
$chapter_ids = ChapterUtils::get_flatten_post_ids($product_id);
$index       = array_search($current_chapter_id, $chapter_ids, true);
/** @var int|false $index */
$next_chapter_id = $index ? $chapter_ids[ $index + 1 ] ?? false : false;

$next_chapter_button_html = '';
if (count($chapter_ids) > 0) {
	if (false === $next_chapter_id) {
		$next_chapter_button_html = '<button class="pc-btn pc-btn-sm pc-btn-primary px-0 lg:px-4  text-white cursor-not-allowed opacity-70 w-full lg:w-auto text-xs sm:text-base" tabindex="-1" role="button" aria-disabled="true">沒有更多章節</button>';
	} else {
		$next_chapter_button_html = sprintf(
			/*html*/'
		<a href="%1$s" class="pc-btn pc-btn-primary pc-btn-sm px-0 lg:px-4 text-white w-full lg:w-auto text-xs sm:text-base">
					前往下一章節
					<svg class="size-3 sm:size-4" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
						<g id="SVGRepo_bgCarrier" stroke-width="0"></g>
						<g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
						<g id="SVGRepo_iconCarrier">
							<path fill-rule="evenodd" clip-rule="evenodd" d="M5.60439 4.23093C4.94586 3.73136 4 4.20105 4 5.02762V18.9724C4 19.799 4.94586 20.2686 5.60439 19.7691L14.7952 12.7967C15.3227 12.3965 15.3227 11.6035 14.7952 11.2033L5.60439 4.23093ZM2 5.02762C2 2.54789 4.83758 1.13883 6.81316 2.63755L16.004 9.60993C17.5865 10.8104 17.5865 13.1896 16.004 14.3901L6.81316 21.3625C4.83758 22.8612 2 21.4521 2 18.9724V5.02762Z" fill="#ffffff"></path>
							<path d="M20 3C20 2.44772 20.4477 2 21 2C21.5523 2 22 2.44772 22 3V21C22 21.5523 21.5523 22 21 22C20.4477 22 20 21.5523 20 21V3Z" fill="#ffffff"></path>
						</g>
					</svg>
		</a>
',
			\get_permalink($next_chapter_id)
		);
	}
}

// render
printf(
	/*html*/'
<div id="pc-classroom-header" class="w-full bg-base-100 py-4 px-4 lg:px-6 flex flex-col lg:flex-row justify-between lg:items-center top-0 z-30 gap-x-2 sticky">
  <div class="flex flex-1 flex-nowrap gap-4 items-end">
		<h2 id="classroom-chapter_title" class="text-sm lg:text-base text-bold lg:tracking-wide my-0 line-clamp-1 pl-6 lg:pl-0">%1$s</h2>
		%2$s
	</div>
	<div class="tw-fixed bottom-0 lg:bottom-[unset] left-0 lg:left-[unset] lg:relative grid gap-3 sm:gap-4 grid-cols-2 lg:grid-flow-row rtl w-full lg:!w-fit justify-between lg:justify-normal items-center mt-0 p-3 sm:p-4 lg:p-0 bg-base-100 shadow-2xl lg:shadow-none">
		%3$s
		%4$s
	</div>
	%5$s
</div>
',
$chapter->post_title ? $chapter->post_title : $product->get_title(),
	Plugin::load_template(
		'badge',
		[
			'type'     => $is_this_chapter_finished ? 'secondary' : 'accent',
			'children' => $is_this_chapter_finished ? '已完成' : '未完成',
			'size'     => 'sm',
			'class'    => 'text-white text-xs',
			'attr'     => ' id="classroom-chapter_title-badge" ',
		],
		false
		),
		$next_chapter_button_html,
	$finish_chapter_button_html,
	$back_to_my_course_html
);
