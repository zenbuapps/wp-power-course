<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Compatibility;

use J7\PowerCourse\Resources\Chapter\Core\CPT;


/**
 * 修改 Chapter 與 Course 的關聯，將舊版資料結構轉移到新版
 * OLD 舊版: chapter 的 post_parent 是 course_id
 * NEW 新版: chapter 的 post_meta parent_course_id 是 course_id
 *
 * @since v0.8.0
 */
final class Chapter {

	/**
	 * 使用 OLD 舊版取法取得最課程底下，最上層的章節 ids
	 *
	 * @return array<string>
	 */
	public static function get_top_level_chapter_ids(): array {

		global $wpdb;
		$course_ids = $wpdb->get_col(
			\wp_unslash( // phpcs:ignore
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'product' AND pm.meta_key = '_is_course' AND pm.meta_value = 'yes'"
			)
				)
		);

		$course_ids = is_array( $course_ids ) ? $course_ids : [];

		if (!$course_ids) {
			return [];
		}

		$str_course_ids = [];
		foreach ( $course_ids as $cid ) {
			$str_course_ids[] = is_scalar( $cid ) ? (string) $cid : '';
		}

		$chapter_ids = $wpdb->get_col(
			\wp_unslash( // phpcs:ignore
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = '%1\$s' AND post_parent IN (%2\$s)",
				CPT::POST_TYPE,
				"'" . implode( "','", $str_course_ids ) . "'"
			)
			)
		);

		$chapter_ids = is_array( $chapter_ids ) ? $chapter_ids : [];
		/** @var array<string> $chapter_ids */
		return $chapter_ids;
	}

	/**
	 * 遷移 chapter 的 post_parent 到 post_meta parent_course_id
	 *
	 * @throws \Exception 遷移出錯
	 */
	public static function migrate_chapter_to_new_structure(): void {
		try {

			$chapter_ids = self::get_top_level_chapter_ids();

			$success_ids = [];
			foreach ($chapter_ids as $chapter_id) {
				$post_parent = \get_post_field( 'post_parent', (int) $chapter_id );
				if (!$post_parent) {
					continue;
				}

				$result = \wp_update_post(
				[
					'ID'          => (int) $chapter_id,
					'post_parent' => 0,
					'meta_input'  => [
						'parent_course_id' => $post_parent,
					],
				]
					);

				if ($result === 0) {
					throw new \Exception('更新文章失敗 ID: ' . $chapter_id);
				}

				$success_ids[] = $chapter_id;
			}

			\J7\WpUtils\Classes\WC::log($success_ids, 'Chapter::migrate_chapter_to_new_structure 成功轉移');
		} catch (\Exception $e) {
			\J7\WpUtils\Classes\WC::log($e->getMessage(), 'Chapter::migrate_chapter_to_new_structure 出錯了');
		}
	}


	/**
	 * 將 chapter 設定 editor
	 * 將使用 elementor 的 chapter 設定 editor 為 elementor
	 * 將未使用 elementor 的 chapter 設定 editor 為 power-editor
	 */
	public static function set_editor_meta_to_chapter(): void {

		$elementor_chapter_ids = \get_posts(
			[
				'post_type'   => CPT::POST_TYPE,
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [
					'relation'              => 'AND',
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
				'post_type'   => CPT::POST_TYPE,
				'numberposts' => -1,
				'fields'      => 'ids',
				'meta_query'  => [
					'relation'              => 'AND',
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
