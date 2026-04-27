<?php
/**
 * MCP Chapter Delete Tool
 *
 * 刪除章節（移至垃圾桶）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;

/**
 * Class ChapterDeleteTool
 *
 * 對應 MCP ability：power-course/chapter_delete
 */
final class ChapterDeleteTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '刪除章節', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '將指定章節移至垃圾桶。', 'power-course' );
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
					'description' => __( '欲刪除的章節 ID。', 'power-course' ),
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
				'success'    => [
					'type'        => 'boolean',
					'description' => __( '是否刪除成功。', 'power-course' ),
				],
				'chapter_id' => [
					'type'        => 'integer',
					'description' => __( '被刪除的章節 ID。', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'edit_posts';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'chapter';
	}

	/**
	 * 執行刪除章節
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{success: bool, chapter_id: int}|\WP_Error
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

		$success = ChapterCrud::delete( $chapter_id );

		$logger = new ActivityLogger();
		$logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'success'    => $success,
				'chapter_id' => $chapter_id,
			],
			$success
		);

		return [
			'success'    => $success,
			'chapter_id' => $chapter_id,
		];
	}
}
