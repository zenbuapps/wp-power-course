<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Core;

use J7\PowerCourse\Plugin;

/**
 * 章節/教室模板
 * 1. 版型覆寫
 */
final class Templates {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_filter('single_template', [ $this, 'template_override' ], 9999);
	}

	/**
	 * 覆寫章節頁面
	 * [危險] 如果全域變數汙染，會導致無法預期行為
	 *
	 * @param string $template 原本的模板路徑
	 *
	 * @return string
	 */
	public function template_override( $template ) {

		global $post;
		$post_type = $post?->post_type;
		if ($post_type !== CPT::POST_TYPE) {
			return $template;
		}

		// 檢查主題複寫存不存在，不存在就用預設的
		$chapter_post_type   = CPT::POST_TYPE;
		$dir                 = \get_stylesheet_directory();
		$theme_template_path = \wp_normalize_path("{$dir}/single-{$chapter_post_type}.php");

		if (file_exists($theme_template_path)) {
			return $theme_template_path;
		}

		return \wp_normalize_path(Plugin::$dir . "/inc/templates/single-{$chapter_post_type}.php");
	}
}
