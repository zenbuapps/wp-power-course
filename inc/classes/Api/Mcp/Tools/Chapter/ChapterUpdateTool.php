<?php
/**
 * MCP Chapter Update Tool
 *
 * 更新章節資料。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;

/**
 * Class ChapterUpdateTool
 *
 * 對應 MCP ability：power-course/chapter_update
 */
final class ChapterUpdateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_update';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '更新章節', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '更新指定章節的標題、內容或其他欄位。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'chapter_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '欲更新的章節 ID。', 'power-course' ),
				],
				'post_title'   => [
					'type'        => 'string',
					'description' => __( '新的章節標題。', 'power-course' ),
				],
				'post_content' => [
					'type'        => 'string',
					'description' => __( '新的章節內容。', 'power-course' ),
				],
				'menu_order'   => [
					'type'        => 'integer',
					'description' => __( '排序值。', 'power-course' ),
				],
				'meta_input'   => [
					'type'        => 'object',
					'description' => __( '欲更新的 meta 資料。', 'power-course' ),
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
				'chapter_id' => [
					'type'        => 'integer',
					'description' => __( '被更新的章節 ID。', 'power-course' ),
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
	 * 執行更新章節
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{chapter_id: int}|\WP_Error
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

		$data = [];
		if ( isset( $args['post_title'] ) ) {
			$data['post_title'] = \sanitize_text_field( (string) $args['post_title'] );
		}
		if ( isset( $args['post_content'] ) ) {
			$data['post_content'] = \wp_kses_post( (string) $args['post_content'] );
		}
		if ( isset( $args['menu_order'] ) ) {
			$data['menu_order'] = (int) $args['menu_order'];
		}

		/** @var array<string, mixed> $meta_data */
		$meta_data = isset( $args['meta_input'] ) && is_array( $args['meta_input'] )
		? $args['meta_input']
		: [];

		try {
			$updated_id = ChapterCrud::update( $chapter_id, $data, $meta_data );
		} catch ( \RuntimeException $e ) {
			$logger = new ActivityLogger();
			$logger->log( $this->get_name(), \get_current_user_id(), $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_chapter_update_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		$logger = new ActivityLogger();
		$logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[ 'chapter_id' => $updated_id ],
			true
		);

		return [ 'chapter_id' => $updated_id ];
	}
}
