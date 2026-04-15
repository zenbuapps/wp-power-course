<?php
/**
 * Course Tabs component
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Comment as CommentUtils;

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

$description = $product->get_description();
$accordion   = Plugin::load_template(
	'collapse/chapters',
	[
		'product' => $product,
	],
	false
	);
$qa          = Plugin::load_template(
	'collapse/qa',
	[
		'product' => $product,
	],
	false
	);

$review = Plugin::load_template(
		'review',
		[
			'product' => $product,
		],
		false
		);


// 檢查能不能評價商品
$can_comment = CommentUtils::can_comment($product);

ob_start();
\the_content();
$the_content = ob_get_clean();

$course_tabs = [
	'description' => [
		'label'    => esc_html__( 'Description', 'power-course' ),
		/** @deprecated Powerhouse 3.3.0 之後其實是不需要用 class 包裹的，這邊是為了兼容舊版本 */
		'content'  => sprintf(
			/*html*/'<div class="%2$s">%1$s</div>',
			$the_content,
			$product->get_meta('editor') === 'power-editor' ? 'power-editor' : ''
		),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_description_tab' ) ?: 'yes'),
	],
	'chapter' => [
		'label'    => esc_html__( 'Chapters', 'power-course' ),
		'content'  => $accordion,
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_chapter_tab' ) ?: 'yes'),
	],
	'qa' => [
		'label'    => esc_html__( 'Q&A', 'power-course' ),
		'content'  => $qa,
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_qa_tab' ) ?: 'yes'),
	],
	'comment' => [
		'label'    => esc_html__( 'Comments', 'power-course' ),
		'content'  => sprintf(
		/*html*/'<div id="comment-app" data-comment_type="comment" data-post_id="%1$s" data-show_list="%2$s" data-show_form="%3$s" data-user_id="%4$s" data-user_role="%5$s"></div>',
		$product->get_id(),
		'yes', // $product->get_meta( 'show_comment_list' ) === 'yes' ? 'yes' : 'no',
		'yes',
		\get_current_user_id(),
		\current_user_can('manage_woocommerce') ? 'admin' : 'user',
		),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'enable_comment' ) ?: 'yes'),
	],
	'review' => [
		'label'    => esc_html__( 'Reviews', 'power-course' ),
		'content'  => sprintf(
			/*html*/'<div id="review-app" data-comment_type="review" data-post_id="%1$s" data-show_list="%2$s" data-show_form="%3$s" data-user_id="%4$s" data-user_role="%5$s"></div>',
			$product->get_id(),
			$product->get_meta( 'show_review_list' ) === 'yes' ? 'yes' : 'no',
			\is_user_logged_in() ? ( $can_comment === true ? 'yes' : $can_comment ) : esc_attr__( 'You are not logged in', 'power-course' ),
			\get_current_user_id(),
			\current_user_can('manage_woocommerce') ? 'admin' : 'user',
			),
		'disabled' => !\wc_string_to_bool( (string) $product->get_meta( 'show_review_tab' ) ?: 'yes'),
	],
	// 'announcement' => [
	// 'label'   => '公告',
	// 'content' => '🚧 施工中... 🚧',
	// ],
];

// 外部課程：強制隱藏章節與留言 Tab
$is_external = $product instanceof \WC_Product_External;
if ( $is_external ) {
	$course_tabs['chapter']['disabled'] = true;
	$course_tabs['comment']['disabled'] = true;
}

$course_tabs = array_filter($course_tabs, fn( $tab ) => !( $tab['disabled'] ));

if ($course_tabs) {
	echo '<div id="courses-product__tabs-nav" class="z-30 w-full">';
	Plugin::load_template(
	'tabs/nav',
	[
		'course_tabs' => $course_tabs,
	]
	);
	echo '</div>';

	Plugin::load_template(
	'tabs/content',
	[
		'course_tabs' => $course_tabs,
	]
	);
}
