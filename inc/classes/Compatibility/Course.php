<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Compatibility;

/**
 * 課程相容性
 */
final class Course {
	/**
	 * 將 course 設定 editor
	 * 將使用 elementor 的 course 設定 editor 為 elementor
	 * 將未使用 elementor 的 course 設定 editor 為 power-editor
	 */
	public static function set_editor_meta_to_course(): void {

		$elementor_chapter_ids = \get_posts(
			[
				'post_type'   => 'product',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [
					'relation'              => 'AND',
					'is_course_clause'      => [
						'key'     => '_is_course',
						'value'   => 'yes',
						'compare' => '=',
					],
					'elementor_data_clause' => [
						'key'     => '_elementor_data',
						'compare' => 'EXISTS',
					],
					'editor_clause'         => [
						'key'     => 'editor',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		foreach ($elementor_chapter_ids as $chapter_id) {
			\update_post_meta($chapter_id, 'editor', 'elementor');
		}

		$power_chapter_ids = \get_posts(
			[
				'post_type'   => 'product',
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [
					'relation'              => 'AND',
					'is_course_clause'      => [
						'key'     => '_is_course',
						'value'   => 'yes',
						'compare' => '=',
					],
					'elementor_data_clause' => [
						'key'     => '_elementor_data',
						'compare' => 'NOT EXISTS',
					],
					'editor_clause'         => [
						'key'     => 'editor',
						'compare' => 'NOT EXISTS',
					],
				],
			]
		);

		foreach ($power_chapter_ids as $chapter_id) {
			\update_post_meta($chapter_id, 'editor', 'power-editor');
		}
	}
}
