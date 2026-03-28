<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Templates;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class FrontEnd
 * 覆寫 WooCommerce 模板
 */
final class Templates {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter('template_include', [ $this, 'course_product_template' ], 9999);
		\add_action('init', [ $this, 'add_rewrite_rules' ]);
		\add_action( 'admin_bar_menu', [ $this, 'admin_bar_item' ], 210 );
	}

	/**
	 * 覆寫課程商品頁面
	 * [危險] 如果全域變數汙染，會導致無法預期行為
	 *
	 * @param string $template 原本的模板路徑
	 *
	 * @return string
	 */
	public function course_product_template( $template ) {
		global $wp_query;

		if ($wp_query->is_page() || !$wp_query->is_single()) {
			return $template;
		}

		if ('product' !== $wp_query->get('post_type')) {
			return $template;
		}

		$product_id = $wp_query->queried_object_id;

		if (!CourseUtils::is_course_product( (int) $product_id )) {
			return $template;
		}

		$course_product_template = Plugin::$dir . '/inc/templates/course-product-entry.php';
		return $course_product_template;
	}

	/**
	 * Add rewrite rules
	 *
	 * @return void
	 */
	public function add_rewrite_rules(): void {
		// get registered rewrite rules.
		/** @var array<string,string> $rules */
		$rules = \get_option( 'rewrite_rules', [] );

		// @deprecated 為了兼容0.3以前版本的永久連結
		$settings                       = Settings::instance();
		$old_course_permalink_structure = $settings->course_permalink_structure;
		$old_course_regex               = '^' . $old_course_permalink_structure . '/(.+)/?$';
		if ($old_course_permalink_structure) {
			\add_rewrite_rule($old_course_regex, 'index.php?product=$matches[1]', 'top');
		}

		if ( ! isset( $rules[ $old_course_regex ] ) ) {
			\flush_rewrite_rules();
		}
	}

	/**
	 * 在管理員工具列中新增項目
	 *
	 * @param \WP_Admin_Bar $admin_bar 管理員工具列物件
	 *
	 * @return void
	 */
	public function admin_bar_item( \WP_Admin_Bar $admin_bar ): void {

		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		global $post;
		$post_id           = (int) $post?->ID;
		$is_course_product = CourseUtils::is_course_product( $post_id );

		if (!$is_course_product) {
			// 不是課程銷售頁就顯示課程列表
			$admin_bar->add_menu(
				[
					'id'     => Plugin::$kebab,
					'parent' => null,
					'group'  => null,
					'title'  => '課程系統', // you can use img tag with image link. it will show the image icon Instead of the title.
					'href'   => \admin_url('admin.php?page=power-course#/courses'),
					'meta'   => [
						'title' => \__( '課程系統', 'power_course' ), // This title will show on hover
					],
				]
			);
			return;
		}
		// 是課程銷售頁就顯示課程編輯
		$admin_bar->add_menu(
			[
				'id'     => Plugin::$kebab,
				'parent' => null,
				'group'  => null,
				'title'  => '編輯課程', // you can use img tag with image link. it will show the image icon Instead of the title.
				'href'   => \admin_url("admin.php?page=power-course#/courses/edit/{$post_id}"),
				'meta'   => [
					'title' => \__( '編輯課程', 'power_course' ), // This title will show on hover
				],
			]
		);
	}
}
