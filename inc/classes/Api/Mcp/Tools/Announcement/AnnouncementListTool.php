<?php
/**
 * MCP Announcement List Tool
 *
 * 列出課程公告，支援依課程篩選、狀態篩選與分頁。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Announcement;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Announcement\Service\Query;

/**
 * Class AnnouncementListTool
 *
 * 對應 MCP ability：power-course/announcement_list
 */
final class AnnouncementListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'announcement_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'List announcements', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'List course announcements. Supports filtering by course, by post status, and pagination.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'parent_course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The course ID that owns the announcements. When omitted, announcements from all courses are returned.', 'power-course' ),
				],
				'post_status'      => [
					'type'        => 'string',
					'description' => \__( 'Comma-separated post statuses to include. One or more of: publish, future, draft, pending, private, trash. Defaults to publish,future,draft.', 'power-course' ),
				],
				'posts_per_page'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
					'description' => \__( 'Number of announcements per page.', 'power-course' ),
				],
				'paged'            => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => \__( 'Page number, starting from 1.', 'power-course' ),
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
				'items' => [
					'type'        => 'array',
					'description' => \__( 'The list of announcements.', 'power-course' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'total' => [
					'type'        => 'integer',
					'description' => \__( 'Number of announcements returned in this page.', 'power-course' ),
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
		return 'announcement';
	}

	/**
	 * 執行列出公告
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$query_args = [];

		if ( isset( $args['parent_course_id'] ) ) {
			$query_args['parent_course_id'] = \absint( (int) $args['parent_course_id'] );
		}

		if ( isset( $args['post_status'] ) && is_string( $args['post_status'] ) && '' !== $args['post_status'] ) {
			$query_args['post_status'] = \sanitize_text_field( $args['post_status'] );
		}

		if ( isset( $args['posts_per_page'] ) ) {
			$query_args['posts_per_page'] = min( 100, max( 1, (int) $args['posts_per_page'] ) );
		}

		if ( isset( $args['paged'] ) ) {
			$query_args['paged'] = max( 1, (int) $args['paged'] );
		}

		try {
			$items = Query::list( $query_args );
		} catch ( \Throwable $th ) {
			return new \WP_Error(
				'mcp_announcement_list_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		return [
			'items' => $items,
			'total' => count( $items ),
		];
	}
}
