<?php
/**
 * MCP Chapter List Tool
 *
 * 列出章節（可依 course_id 篩選）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Chapter\Service\Query as ChapterQuery;

/**
 * Class ChapterListTool
 *
 * 對應 MCP ability：power-course/chapter_list
 */
final class ChapterListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '列出章節', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '列出章節清單，可依課程 ID 或父章節 ID 進行篩選。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'course_id'      => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '課程 ID，用於列出該課程下的所有頂層章節。', 'power-course' ),
				],
				'post_parent'    => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '父章節 ID，用於列出某章節底下的子單元。', 'power-course' ),
				],
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => -1,
					'maximum'     => 500,
					'default'     => -1,
					'description' => __( '每頁筆數；-1 表示不分頁，最大 500。', 'power-course' ),
				],
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( '頁碼（從 1 開始）。', 'power-course' ),
				],
			],
			'required'   => [],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'chapters' => [
					'type'        => 'array',
					'description' => __( '章節資料陣列。', 'power-course' ),
					'items'       => [ 'type' => 'object' ],
				],
				'total'    => [
					'type'        => 'integer',
					'description' => __( '本次回傳的章節總數。', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'read';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'chapter';
	}

	/**
	 * 執行列出章節
	 *
	 * @param array<string, mixed> $args {
	 *     @type int $course_id      課程 ID（optional）
	 *     @type int $post_parent    父章節 ID（optional）
	 *     @type int $posts_per_page 每頁筆數（optional）
	 *     @type int $paged          頁碼（optional）
	 * }
	 *
	 * @return array{chapters: array<int, array<string, mixed>>, total: int}
	 */
	protected function execute( array $args ): array {
		$query_args = [];

		if ( isset( $args['course_id'] ) ) {
			$course_id = (int) $args['course_id'];
			if ( $course_id > 0 ) {
				// 頂層章節以 parent_course_id meta 關聯到課程
				$query_args['meta_query'] = [
					[
						'key'     => 'parent_course_id',
						'value'   => $course_id,
						'compare' => '=',
					],
				];
				$query_args['post_parent'] = 0;
			}
		}

		if ( isset( $args['post_parent'] ) ) {
			$query_args['post_parent'] = (int) $args['post_parent'];
		}

		if ( isset( $args['posts_per_page'] ) ) {
			$query_args['posts_per_page'] = (int) $args['posts_per_page'];
		}

		if ( isset( $args['paged'] ) ) {
			$query_args['paged'] = max( 1, (int) $args['paged'] );
		}

		$chapters = ChapterQuery::list( $query_args );

		return [
			'chapters' => $chapters,
			'total'    => count( $chapters ),
		];
	}
}
