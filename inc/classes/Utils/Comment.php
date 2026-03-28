<?php
/**
 * Comment
 * TODO 移動到 Resources 底下
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class Comment
 */
abstract class Comment {

	/**
	 * 檢查能不能評價
	 *
	 * @param \WC_Product|\WP_Post $maybe_product 商品
	 * @param string               $comment_type comment, review, etc...
	 * @param string               $operate create，CRUD
	 * @return true|string 能不能 comment 或原因
	 */
	public static function can_comment( $maybe_product, string $comment_type = 'comment', ?string $operate = 'create' ): bool|string {

		if ($maybe_product instanceof \WC_Product) {
			return self::can_comment_product($maybe_product, $comment_type, $operate);
		}

		return true;
	}

	/**
	 * 檢查能不能評價商品
	 *
	 * @param \WC_Product $maybe_product 商品
	 * @param string      $comment_type comment, review, etc...
	 * @param string      $operate create，CRUD
	 * @return true|string 能不能 comment 或原因
	 */
	public static function can_comment_product( \WC_Product $maybe_product, string $comment_type = 'comment', ?string $operate = 'create' ): bool|string {
		if ('create' === $operate && 'review' === $comment_type) {
			$reviews_allowed = $maybe_product->get_reviews_allowed(); // 後台設定，是否允許評價

			if (!$reviews_allowed) {
				return '此課程不開放評價';
			}

			$product_id = $maybe_product->get_id();

			$is_avl = CourseUtils::is_avl( $product_id ); // 判斷用戶是否是學員

			if (!$is_avl) {
				return '您尚未購買此課程，尚無法評價';
			}

			// 檢查用戶是否評論過此商品
			/** @var array<int, \WP_Comment> $has_reviewed */
			$has_reviewed = \get_comments(
				[
					'post_id' => $product_id,
					'user_id' => \get_current_user_id(),
					'type'    => 'review',
				]
			);

			if ($has_reviewed) {
				return '您已評價過此課程，無法再次評價';
			}
		}

		return true;
	}
}
