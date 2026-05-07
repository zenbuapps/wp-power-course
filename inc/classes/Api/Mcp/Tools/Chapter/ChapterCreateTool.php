<?php
/**
 * MCP Chapter Create Tool
 *
 * 建立新章節。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;

/**
 * Class ChapterCreateTool
 *
 * 對應 MCP ability：power-course/chapter_create
 */
final class ChapterCreateTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_create';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '建立章節', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '在指定課程下建立新的章節（單元）。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_title'       => [
					'type'        => 'string',
					'description' => __( '章節標題。', 'power-course' ),
				],
				'post_content'     => [
					'type'        => 'string',
					'description' => __( '章節內容 HTML。', 'power-course' ),
				],
				'post_parent'      => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '父章節 ID；為 0 表示頂層章節。', 'power-course' ),
				],
				'parent_course_id' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '所屬課程 ID（僅頂層章節有效）。', 'power-course' ),
				],
				'menu_order'       => [
					'type'        => 'integer',
					'description' => __( '排序值，數字越小越前面。', 'power-course' ),
				],
				'meta_input'       => [
					'type'        => 'object',
					'description' => __( '額外的 meta 資料。', 'power-course' ),
				],
			],
			'required'   => [ 'post_title' ],
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
					'description' => __( '新建章節 ID。', 'power-course' ),
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
	 * 執行建立章節
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{chapter_id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$post_title = isset( $args['post_title'] ) ? (string) $args['post_title'] : '';
		if ( '' === $post_title ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'post_title 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$data = [
			'post_title' => \sanitize_text_field( $post_title ),
		];

		if ( isset( $args['post_content'] ) ) {
			$data['post_content'] = \wp_kses_post( (string) $args['post_content'] );
		}
		if ( isset( $args['post_parent'] ) ) {
			$data['post_parent'] = (int) $args['post_parent'];
		}
		if ( isset( $args['menu_order'] ) ) {
			$data['menu_order'] = (int) $args['menu_order'];
		}

		/** @var array<string, mixed> $meta_data */
		$meta_data = isset( $args['meta_input'] ) && is_array( $args['meta_input'] )
		? $args['meta_input']
		: [];

		if ( isset( $args['parent_course_id'] ) ) {
			$meta_data['parent_course_id'] = (int) $args['parent_course_id'];
		}

		try {
			$chapter_id = ChapterCrud::create( $data, $meta_data );
		} catch ( \RuntimeException $e ) {
			$logger = new ActivityLogger();
			$logger->log( $this->get_name(), \get_current_user_id(), $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_chapter_create_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		$logger = new ActivityLogger();
		$logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[ 'chapter_id' => $chapter_id ],
			true
		);

		return [ 'chapter_id' => $chapter_id ];
	}
}
