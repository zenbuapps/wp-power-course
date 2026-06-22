<?php
/**
 * MCP Subtitle Delete Tool
 *
 * 刪除指定 post 某個 video slot 下、指定語言的字幕。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Subtitle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleDeleteTool
 *
 * 對應 MCP ability：power-course/subtitle_delete
 */
final class SubtitleDeleteTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'subtitle_delete';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Delete subtitle', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Delete the subtitle of a specific language for a post (chapter or course video) and video slot. The associated media file is also removed.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'    => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'The post ID (chapter post or product/course) that owns the subtitle.', 'power-course' ),
				],
				'srclang'    => [
					'type'        => 'string',
					'description' => \__( 'BCP-47 language code of the subtitle to delete, e.g. zh-TW.', 'power-course' ),
				],
				'video_slot' => [
					'type'        => 'string',
					'default'     => 'chapter_video',
					'description' => \__( 'Video slot name. One of: chapter_video, feature_video, trial_video, trial_video_0 ~ trial_video_5.', 'power-course' ),
				],
			],
			'required'   => [ 'post_id', 'srclang' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'deleted'    => [
					'type'        => 'boolean',
					'description' => \__( 'Whether the subtitle was deleted successfully.', 'power-course' ),
				],
				'post_id'    => [
					'type'        => 'integer',
					'description' => \__( 'The post ID.', 'power-course' ),
				],
				'srclang'    => [
					'type'        => 'string',
					'description' => \__( 'The deleted subtitle language code.', 'power-course' ),
				],
				'video_slot' => [
					'type'        => 'string',
					'description' => \__( 'The video slot.', 'power-course' ),
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
		return 'subtitle';
	}

	/**
	 * 執行刪除字幕
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{deleted: bool, post_id: int, srclang: string, video_slot: string}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$srclang = isset( $args['srclang'] ) ? \sanitize_text_field( (string) $args['srclang'] ) : '';

		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'post_id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( '' === $srclang ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'srclang is a required field.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$video_slot = isset( $args['video_slot'] )
		? \sanitize_key( (string) $args['video_slot'] )
		: 'chapter_video';

		$service = new SubtitleService();

		// 驗證 post 與 video slot 搭配是否合法。
		try {
			$service->validate_post_and_slot( $post_id, $video_slot );
		} catch ( \RuntimeException $e ) {
			return $this->runtime_exception_to_wp_error( $e );
		}

		// 執行刪除（找不到該語言字幕會拋出 subtitle_not_found）。
		try {
			$deleted = $service->delete_subtitle( $post_id, $srclang, $video_slot );
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);

			return $this->throwable_to_wp_error( $th );
		}

		$result = [
			'deleted'    => $deleted,
			'post_id'    => $post_id,
			'srclang'    => $srclang,
			'video_slot' => $video_slot,
		];

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			true
		);

		return $result;
	}

	/**
	 * 將 validate_post_and_slot 拋出的 RuntimeException 轉為對應 HTTP 狀態碼的 WP_Error。
	 *
	 * @param \RuntimeException $e 例外物件。
	 * @return \WP_Error
	 */
	private function runtime_exception_to_wp_error( \RuntimeException $e ): \WP_Error {
		$message = $e->getMessage();

		if ( \str_contains( $message, 'invalid_video_slot' ) ) {
			return new \WP_Error( 'mcp_invalid_video_slot', $message, [ 'status' => 400 ] );
		}

		if ( \str_contains( $message, 'post_not_found' ) ) {
			return new \WP_Error( 'mcp_post_not_found', $message, [ 'status' => 404 ] );
		}

		return new \WP_Error( 'mcp_subtitle_error', $message, [ 'status' => 500 ] );
	}

	/**
	 * 將 delete_subtitle 拋出的例外轉為 WP_Error。
	 * 找不到字幕（subtitle_not_found）以 404 回傳，其餘以 500 回傳。
	 *
	 * @param \Throwable $th 例外物件。
	 * @return \WP_Error
	 */
	private function throwable_to_wp_error( \Throwable $th ): \WP_Error {
		$message = $th->getMessage();

		if ( \str_contains( $message, 'subtitle_not_found' ) ) {
			return new \WP_Error( 'mcp_subtitle_not_found', $message, [ 'status' => 404 ] );
		}

		if ( \str_contains( $message, 'invalid_video_slot' ) ) {
			return new \WP_Error( 'mcp_invalid_video_slot', $message, [ 'status' => 400 ] );
		}

		if ( \str_contains( $message, 'post_not_found' ) ) {
			return new \WP_Error( 'mcp_post_not_found', $message, [ 'status' => 404 ] );
		}

		return new \WP_Error( 'mcp_subtitle_delete_failed', $message, [ 'status' => 500 ] );
	}
}
