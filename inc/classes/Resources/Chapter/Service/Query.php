<?php
/**
 * Chapter Service Query
 *
 * 提供章節查詢相關的業務邏輯，供 REST callback 與 MCP tools 共用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Service;

use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Chapter 查詢 Service
 *
 * 職責：封裝章節列表與單筆讀取邏輯
 */
final class Query {

	/**
	 * 列出章節
	 *
	 * 以 get_posts 查詢章節並輸出格式化後的陣列。
	 *
	 * @param array<string, mixed> $args 查詢參數（可包含 post_parent、parent_course_id 等）。
	 *
	 * @return array<int, array<string, mixed>> 格式化後的章節列表
	 */
	public static function list( array $args = [] ): array {
		$default_args = [
			'post_type'      => ChapterCPT::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => 'any',
			'orderby'        => [
				'menu_order' => 'ASC',
				'ID'         => 'ASC',
				'date'       => 'ASC',
			],
		];

		$args = \wp_parse_args( $args, $default_args );

		$chapters = \get_posts( $args );

		/** @var array<int, array<string, mixed>> $formatted */
		$formatted = array_values(
			array_map( [ ChapterUtils::class, 'format_chapter_details' ], $chapters )
		);

		return $formatted;
	}

	/**
	 * 取得單一章節詳細資訊
	 *
	 * @param int $chapter_id 章節 ID
	 *
	 * @return array<string, mixed>|null 找不到時回傳 null
	 */
	public static function get( int $chapter_id ): ?array {
		$post = \get_post( $chapter_id );

		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		if ( $post->post_type !== ChapterCPT::POST_TYPE ) {
			return null;
		}

		/** @var array<string, mixed> $formatted */
		$formatted = ChapterUtils::format_chapter_details( $post );

		return $formatted;
	}
}
