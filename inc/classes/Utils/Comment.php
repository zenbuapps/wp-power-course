<?php
/**
 * Comment
 * TODO 移動到 Resources 底下
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

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
				return __( 'This course does not allow reviews', 'power-course' );
			}

			$product_id = $maybe_product->get_id();

			$is_avl = CourseUtils::is_avl( $product_id ); // 判斷用戶是否是學員

			if (!$is_avl) {
				return __( 'You have not purchased this course yet, cannot review', 'power-course' );
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
				return __( 'You have already reviewed this course, cannot review again', 'power-course' );
			}
		}

		return true;
	}

	/**
	 * 將章節 ID 轉譯為所屬課程商品 ID
	 *
	 * 章節（pc_chapter）留言時，前端送來的 comment_post_ID 是章節 ID，
	 * 但留言資料模型掛在課程商品（product）底下，需轉譯（Issue #234）。
	 * 透過 ChapterUtils::get_course_id() 解析，可正確處理頂層與子章節。
	 * 非章節或解析失敗時，原樣回傳輸入值。
	 *
	 * @param int $post_id 來源 post ID（章節或課程商品）
	 * @return int 課程商品 ID；非章節或解析失敗則回傳原值
	 */
	public static function to_course_product_id( int $post_id ): int {
		if ( $post_id <= 0 || 'pc_chapter' !== \get_post_type( $post_id ) ) {
			return $post_id;
		}

		$course_id = ChapterUtils::get_course_id( $post_id );
		return $course_id ? (int) $course_id : $post_id;
	}
}
