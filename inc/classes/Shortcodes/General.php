<?php
/**
 * General Shortcodes
 */

declare(strict_types=1);

namespace J7\PowerCourse\Shortcodes;

use J7\PowerCourse\BundleProduct\Helper;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class General
 */
final class General {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * 所有短碼
	 *
	 * @var array<string>
	 */
	public static array $shortcodes = [
		'pc_courses',
		'pc_my_courses',
		'pc_simple_card',
		'pc_bundle_card',
	];

	/** Constructor */
	public function __construct() {
		foreach (self::$shortcodes as $shortcode) {
			// @phpstan-ignore-next-line
			\add_shortcode($shortcode, [ __CLASS__, "{$shortcode}_callback" ]);
		}
	}

	/**
	 * 課程列表短碼 pc_courses callback
	 *
	 * @param array{status:array<string>,paginate:bool,limit:int,page:int,orderby:string,order:string,meta_key:string,meta_value:string,exclude_avl_courses:bool} $params 短碼參數
	 * @return string
	 */
	public static function pc_courses_callback( array $params ): string {

		$default_args = [
			'status'              => [ 'publish' ],
			'visibility'          => 'visible',
			'paginate'            => true,
			'limit'               => 12,
			'page'                => 1,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'meta_key'            => '_is_course',
			'meta_value'          => 'yes',
			'exclude_avl_courses' => false,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$exclude_avl_courses_val = $args['exclude_avl_courses'] ?? false;
		unset($args['exclude_avl_courses']);

		$columns_val = $args['columns'] ?? 3;
		unset($args['columns']);

		$array_keys_to_process = [ 'include', 'exclude', 'tag', 'category' ];
		foreach ($array_keys_to_process as $key) {
			if (isset($args[ $key ])) {
				if (is_string($args[ $key ])) {
					$args[ $key ] = explode(',', str_replace(' ', '', $args[ $key ]));
				}
				if (( $key === 'include' || $key === 'exclude' ) && is_array($args[ $key ])) {
					$args[ $key ] = array_filter(array_map(fn( $v ) => (int) $v, $args[ $key ]));
				}
			}
		}

		$final_excluded_ids = $args['exclude'] ?? [];

		if ( $exclude_avl_courses_val ) {
			$current_user_id     = \get_current_user_id();
			$user_avl_course_ids = CourseUtils::get_avl_courses_by_user( $current_user_id, true );
			if (!empty($user_avl_course_ids)) {
				$final_excluded_ids = array_merge($final_excluded_ids, $user_avl_course_ids);
			}
		}

		if (!empty($final_excluded_ids)) {
			$args['exclude'] = array_unique($final_excluded_ids);
		} else {
			unset($args['exclude']);
		}

		/** @var object{total:int,max_num_pages:int,products:array<int,\WC_Product>} $results */
		$results     = \wc_get_products( $args );
		$total       = $results->total;
		$total_pages = $results->max_num_pages;

		$products = $results->products;

		$html = Plugin::load_template(
			'list/pricing',
			[
				'products' => $products,
				'columns'  => $columns_val,
			],
			false
		);

		return (string) $html;
	}

	/**
	 * 我的課程短碼 pc_my_courses callback
	 *
	 * @param ?array{} $params 短碼參數
	 * @return string
	 */
	public static function pc_my_courses_callback( ?array $params ): string {
		$html = Plugin::load_template(
			'my-account',
			null,
			false
		);

		return (string) $html;
	}

	/**
	 * 簡單課程卡片短碼 pc_simple_card_callback
	 *
	 * @param array{product_id:int|string} $params 短碼參數
	 * @return string
	 */
	public static function pc_simple_card_callback( array $params ): string {
		$default_args = [
			'product_id' => 0,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$product = \wc_get_product( $args['product_id'] );

		if ( ! ( $product instanceof \WC_Product ) ) {
			return '《' . esc_html__( 'Product not found', 'power-course' ) . '》';
		}

		if (in_array($product->get_type(), [ 'simple','subscription' ], true)) {
			return (string) Plugin::safe_get(
			'card/single-product',
			[
				'product' => $product,
			],
				false
				);
		}

		return '《' . esc_html__( 'Product is not a simple product', 'power-course' ) . '》';
	}

	/**
	 * 銷售方案卡片短碼 pc_bundle_card_callback
	 *
	 * @param array{product_id:int|string} $params 短碼參數
	 * @return string
	 */
	public static function pc_bundle_card_callback( array $params ): string {
		$default_args = [
			'product_id' => 0,
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$product = \wc_get_product( $args['product_id'] );

		if ( ! ( $product instanceof \WC_Product ) ) {
			return '《' . esc_html__( 'Product not found', 'power-course' ) . '》';
		}

		$helper = Helper::instance( $product );
		if ( $helper?->is_bundle_product ) {
			return (string) Plugin::safe_get(
				'card/bundle-product',
				[
					'product' => $product,
				],
				false
				);
		}

		return '《' . esc_html__( 'Product is not a bundle', 'power-course' ) . '》';
	}
}
