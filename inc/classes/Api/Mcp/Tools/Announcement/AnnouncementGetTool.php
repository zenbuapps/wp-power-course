<?php
/**
 * MCP Announcement Get Tool
 *
 * 取得單一課程公告的詳細資訊。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Announcement;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Announcement\Service\Query;

/**
 * Class AnnouncementGetTool
 *
 * 對應 MCP ability：power-course/announcement_get
 */
final class AnnouncementGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'announcement_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Get announcement', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Get the details of a single course announcement by its ID.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The announcement ID.', 'power-course' ),
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'               => [
					'type'        => 'string',
					'description' => \__( 'The announcement ID.', 'power-course' ),
				],
				'post_title'       => [
					'type'        => 'string',
					'description' => \__( 'The announcement title.', 'power-course' ),
				],
				'post_content'     => [
					'type'        => 'string',
					'description' => \__( 'The announcement content (HTML).', 'power-course' ),
				],
				'post_status'      => [
					'type'        => 'string',
					'description' => \__( 'The post status, e.g. publish, future, draft.', 'power-course' ),
				],
				'post_date'        => [
					'type'        => 'string',
					'description' => \__( 'The publish date (Y-m-d H:i:s, site timezone).', 'power-course' ),
				],
				'parent_course_id' => [
					'type'        => 'integer',
					'description' => \__( 'The course ID this announcement belongs to.', 'power-course' ),
				],
				'visibility'       => [
					'type'        => 'string',
					'description' => \__( 'Visibility: public (everyone) or enrolled (purchasers only).', 'power-course' ),
				],
				'end_at'           => [
					'type'        => [ 'integer', 'string' ],
					'description' => \__( 'Expiration time as a 10-digit Unix timestamp, or an empty string when it never expires.', 'power-course' ),
				],
				'status_label'     => [
					'type'        => 'string',
					'description' => \__( 'Computed status label: active, scheduled, expired or draft.', 'power-course' ),
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
	 * 執行取得單一公告
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$id = \absint( (int) ( $args['id'] ?? 0 ) );
		if ( $id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		try {
			$data = Query::get( $id );
		} catch ( \Throwable $th ) {
			return new \WP_Error(
				'mcp_announcement_get_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		if ( null === $data ) {
			return new \WP_Error(
				'mcp_announcement_not_found',
				\__( 'Announcement does not exist', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		return $data;
	}
}
