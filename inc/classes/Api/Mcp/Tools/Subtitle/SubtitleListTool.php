<?php
/**
 * MCP Subtitle List Tool
 *
 * 列出指定 post（章節 / 課程影片）某個 video slot 的字幕軌道。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Subtitle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleListTool
 *
 * 對應 MCP ability：power-course/subtitle_list
 */
final class SubtitleListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'subtitle_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'List subtitles', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'List all subtitle tracks of a post (chapter or course video) for the given video slot.', 'power-course' );
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
					'description' => \__( 'The post ID (chapter post or product/course) that owns the subtitles.', 'power-course' ),
				],
				'video_slot' => [
					'type'        => 'string',
					'default'     => 'chapter_video',
					'description' => \__( 'Video slot name. One of: chapter_video, feature_video, trial_video, trial_video_0 ~ trial_video_5.', 'power-course' ),
				],
			],
			'required'   => [ 'post_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'post_id'    => [
					'type'        => 'integer',
					'description' => \__( 'The queried post ID.', 'power-course' ),
				],
				'video_slot' => [
					'type'        => 'string',
					'description' => \__( 'The queried video slot.', 'power-course' ),
				],
				'subtitles'  => [
					'type'        => 'array',
					'description' => \__( 'Subtitle tracks. Each item contains srclang, label, url and attachment_id.', 'power-course' ),
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'srclang'       => [
								'type'        => 'string',
								'description' => \__( 'BCP-47 language code, e.g. zh-TW.', 'power-course' ),
							],
							'label'         => [
								'type'        => 'string',
								'description' => \__( 'Human-readable language label.', 'power-course' ),
							],
							'url'           => [
								'type'        => 'string',
								'description' => \__( 'Public URL of the VTT subtitle file.', 'power-course' ),
							],
							'attachment_id' => [
								'type'        => 'integer',
								'description' => \__( 'WordPress attachment ID of the subtitle file.', 'power-course' ),
							],
						],
					],
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
	 * 執行列出字幕
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{post_id: int, video_slot: string, subtitles: array<int, array{srclang: string, label: string, url: string, attachment_id: int}>}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$post_id = (int) ( $args['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'post_id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$video_slot = isset( $args['video_slot'] )
		? \sanitize_key( (string) $args['video_slot'] )
		: 'chapter_video';

		try {
			$service   = new SubtitleService();
			$subtitles = $service->get_subtitles( $post_id, $video_slot );
		} catch ( \RuntimeException $e ) {
			return $this->runtime_exception_to_wp_error( $e );
		}

		return [
			'post_id'    => $post_id,
			'video_slot' => $video_slot,
			'subtitles'  => $subtitles,
		];
	}

	/**
	 * 將 SubtitleService 拋出的 RuntimeException 轉為對應 HTTP 狀態碼的 WP_Error。
	 * 依錯誤訊息前綴判斷（與 SubtitleApi 的處理一致）。
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
}
