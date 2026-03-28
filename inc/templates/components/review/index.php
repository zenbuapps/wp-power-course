<?php
/**
 * Review
 * TODO 前端AJAX換頁
 */

use J7\PowerCourse\Plugin;

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

$product_comments = \get_comments(
	[
		'post_id'      => $product->get_id(),
		'post_type'    => 'product',
		'hierarchical' => 'threaded',
	]
);

// Review 標題
// $count = $product->get_review_count();
// printf(
// /*html*/'<h2 class="text-base font-bold">《%1$s》 共有 %2$s 則評價</h2>',
// esc_html( $product->get_name() ),
// esc_html( $count )
// );

// Review Form

$reviews_allowed = $product->get_reviews_allowed(); // 後台設定，是否允許評價
$has_bought      = wc_customer_bought_product( '', get_current_user_id(), $product->get_id() ); // 用戶是否已購買此課程
// 檢查用戶是否評論過此商品
/** @var array<int|WP_Comment> $has_reviewed_comments */
$has_reviewed_comments = get_comments(
	[
		'post_id' => $product->get_id(),
		'user_id' => get_current_user_id(),
	]
);
$has_reviewed = count( $has_reviewed_comments ) > 0;


if ($reviews_allowed && $has_bought && !$has_reviewed) {
	echo '<div class="bg-gray-100 p-6 mb-2 rounded">';
	include __DIR__ . '/comment_form.php';
	echo '</div>';
}

// Review List
$show_review_list = $product->get_meta( 'show_review_list' ) === 'yes';
if ( $show_review_list && is_array( $product_comments ) && count( $product_comments ) ) {
	foreach ( $product_comments as $product_comment ) {
		Plugin::load_template(
			'review/item',
			[
				'comment' => $product_comment,
			]
			);
	}
}
