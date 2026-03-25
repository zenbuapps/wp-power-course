<?php
/**
 * ProductQuery
 */

declare(strict_types=1);

namespace J7\PowerCourse\Admin;

use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class ProductQuery
 */
final class ProductQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'pre_get_posts', [ __CLASS__, 'exclude_course_product' ], 10 );
	}

	/**
	 * Exclude Course Product
	 * [危險] 會影響全站的查詢
	 * 如果是後台，讓管理員知道 課程就是商品
	 * 如果是前台主查詢 (外掛, 短碼不算主查詢)，排除課程商品
	 * 搜尋 - 可以找到課程商品
	 *
	 * @param \WP_Query $query 查詢
	 * @return void
	 */
	public static function exclude_course_product( $query ): void {
		$settings                      = Settings::instance();
		$hide_courses_in_main_query    = $settings->hide_courses_in_main_query;
		$hide_courses_in_search_result = $settings->hide_courses_in_search_result;

		if (!\wc_string_to_bool($hide_courses_in_main_query)) {
			return;
		}

		if ( \is_admin() || !$query->is_main_query() || $query->is_single()) {
			return;
		}

		if (!\wc_string_to_bool($hide_courses_in_search_result)) {
			// 如果是搜尋，也不排除
			if (isset($_GET['s'])) {
				return;
			}
		}

		// 搜尋時候 $post_type 有時是 "" 有時候是 array
		$post_type = $query->get('post_type');

		if (is_array($post_type)) {
			if (!in_array('product', $post_type, true) && !$query->is_search()) {
				return;
			}
		} elseif ('product' !== $post_type && !$query->is_search()) {
			return;
		}

		$meta_query = $query->get('meta_query');

		if (!is_array($meta_query)) {
			$meta_query = [];
		}

		if (!isset($meta_query['relation'])) {
			$meta_query['relation'] = 'AND';
		}

		$meta_query[] = [
			'key'     => '_is_course',
			'value'   => 'no',
			'compare' => '=',
		];

		$query->set('meta_query', $meta_query);
	}
}
