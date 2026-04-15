<?php
/**
 * MCP Chapter Get Tool
 *
 * 取得單一章節詳細資料。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Chapter\Service\Query as ChapterQuery;

/**
 * Class ChapterGetTool
 *
 * 對應 MCP ability：power-course/chapter_get
 */
final class ChapterGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '取得單一章節', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '依章節 ID 取得該章節的完整詳細資料。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'chapter_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '欲查詢的章節 ID。', 'power-course' ),
				],
			],
			'required'   => [ 'chapter_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'chapter' => [
					'type'        => 'object',
					'description' => __( '章節詳細資料，找不到時為 null。', 'power-course' ),
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
	 * 執行取得單一章節
	 *
	 * @param array<string, mixed> $args {
	 *     @type int $chapter_id 章節 ID
	 * }
	 *
	 * @return array{chapter: array<string, mixed>|null}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );

		if ( $chapter_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'chapter_id 為必填且必須為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$chapter = ChapterQuery::get( $chapter_id );

		if ( null === $chapter ) {
			return new \WP_Error(
				'mcp_chapter_not_found',
				/* translators: %d: chapter id */
				sprintf( __( '找不到章節 ID %d。', 'power-course' ), $chapter_id ),
				[ 'status' => 404 ]
			);
		}

		return [
			'chapter' => $chapter,
		];
	}
}
