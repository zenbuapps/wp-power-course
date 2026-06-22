<?php
/**
 * MCP Subtitle Upload Tool
 *
 * 透過純文字內容上傳字幕（SRT 或 VTT），適合 AI 生成 / 翻譯字幕後直接上傳。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Subtitle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleUploadTool
 *
 * 對應 MCP ability：power-course/subtitle_upload
 */
final class SubtitleUploadTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'subtitle_upload';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Upload subtitle', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Upload a subtitle by passing the file content as plain text (SRT or VTT). Ideal for uploading AI-generated or AI-translated subtitles without a file. Uploading a language that already exists will fail; delete it first.', 'power-course' );
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
					'description' => \__( 'The post ID (chapter post or product/course) to attach the subtitle to.', 'power-course' ),
				],
				'srclang'    => [
					'type'        => 'string',
					'description' => \__( 'BCP-47 language code. Common values: zh-TW, zh-CN, en, ja, ko. Must be in the supported language list.', 'power-course' ),
				],
				'content'    => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => \__( 'The subtitle file content as plain text (SRT or VTT format).', 'power-course' ),
				],
				'video_slot' => [
					'type'        => 'string',
					'default'     => 'chapter_video',
					'description' => \__( 'Video slot name. One of: chapter_video, feature_video, trial_video, trial_video_0 ~ trial_video_5.', 'power-course' ),
				],
				'format'     => [
					'type'        => 'string',
					'enum'        => [ 'srt', 'vtt' ],
					'description' => \__( 'Subtitle format. If omitted, auto-detected: content starting with WEBVTT is treated as vtt, otherwise srt.', 'power-course' ),
				],
			],
			'required'   => [ 'post_id', 'srclang', 'content' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'srclang'       => [
					'type'        => 'string',
					'description' => \__( 'BCP-47 language code of the uploaded subtitle.', 'power-course' ),
				],
				'label'         => [
					'type'        => 'string',
					'description' => \__( 'Human-readable language label.', 'power-course' ),
				],
				'url'           => [
					'type'        => 'string',
					'description' => \__( 'Public URL of the stored VTT subtitle file.', 'power-course' ),
				],
				'attachment_id' => [
					'type'        => 'integer',
					'description' => \__( 'WordPress attachment ID of the stored subtitle file.', 'power-course' ),
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
	 * 執行上傳字幕
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{srclang: string, label: string, url: string, attachment_id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		// 1. 驗證必填欄位與型別。
		$post_id = (int) ( $args['post_id'] ?? 0 );
		$srclang = isset( $args['srclang'] ) ? \sanitize_text_field( (string) $args['srclang'] ) : '';
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';

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

		if ( '' === $content ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'content is a required field and cannot be empty.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$video_slot = isset( $args['video_slot'] )
		? \sanitize_key( (string) $args['video_slot'] )
		: 'chapter_video';

		$service = new SubtitleService();

		// 2. 驗證語言代碼是否在支援清單內。
		if ( ! $service->validate_srclang( $srclang ) ) {
			$supported = \implode( ', ', \array_keys( SubtitleService::SUPPORTED_LANGUAGES ) );
			return new \WP_Error(
				'mcp_invalid_language',
				\sprintf(
					/* translators: 1: 傳入的語言代碼, 2: 支援的語言代碼清單 */
					\__( 'Unsupported language code "%1$s". Supported codes: %2$s', 'power-course' ),
					$srclang,
					$supported
				),
				[ 'status' => 422 ]
			);
		}

		// 3. 驗證 post 與 video slot 搭配是否合法。
		try {
			$service->validate_post_and_slot( $post_id, $video_slot );
		} catch ( \RuntimeException $e ) {
			return $this->runtime_exception_to_wp_error( $e );
		}

		// 4. 決定副檔名：明確指定優先；否則以 WEBVTT 開頭自動判斷。
		if ( isset( $args['format'] ) && \in_array( $args['format'], SubtitleService::SUPPORTED_EXTENSIONS, true ) ) {
			$ext = (string) $args['format'];
		} else {
			$ext = \str_starts_with( \ltrim( $content ), 'WEBVTT' ) ? 'vtt' : 'srt';
		}

		// 5. 建立溫存檔並寫入字幕內容（供既有 Service 以 file_path 讀取）。
		$file_name = "subtitle-{$srclang}.{$ext}";
		$tmp_path  = \wp_tempnam( $file_name );
		if ( '' === $tmp_path ) {
			return new \WP_Error(
				'mcp_subtitle_tmp_failed',
				\__( 'Failed to create a temporary file for the subtitle.', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		$bytes_written = \file_put_contents( $tmp_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === $bytes_written ) {
			@\unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return new \WP_Error(
				'mcp_subtitle_tmp_failed',
				\__( 'Failed to write the subtitle content to the temporary file.', 'power-course' ),
				[ 'status' => 500 ]
			);
		}

		// 6. 呼叫既有 Service 處理（讀檔 → 轉 VTT → 存 media → 更新 postmeta）。
		try {
			$track = $service->upload_subtitle( $post_id, $tmp_path, $file_name, $srclang, $video_slot );
		} catch ( \Throwable $th ) {
			// 7. 清理溫存檔（失敗路徑）。
			@\unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);

			return $this->throwable_to_wp_error( $th );
		}

		// 7. 清理溫存檔（成功路徑）。
		@\unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		// 8. 記錄成功並回傳字幕軌道。
		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$track,
			true
		);

		return $track;
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
	 * 將 upload_subtitle 拋出的例外轉為 WP_Error。
	 * 重複語言（subtitle_exists）以 422 回傳，其餘以 500 回傳。
	 *
	 * @param \Throwable $th 例外物件。
	 * @return \WP_Error
	 */
	private function throwable_to_wp_error( \Throwable $th ): \WP_Error {
		$message = $th->getMessage();

		if ( \str_contains( $message, 'subtitle_exists' ) ) {
			return new \WP_Error( 'mcp_subtitle_exists', $message, [ 'status' => 422 ] );
		}

		if ( \str_contains( $message, 'invalid_video_slot' ) ) {
			return new \WP_Error( 'mcp_invalid_video_slot', $message, [ 'status' => 400 ] );
		}

		if ( \str_contains( $message, 'post_not_found' ) ) {
			return new \WP_Error( 'mcp_post_not_found', $message, [ 'status' => 404 ] );
		}

		return new \WP_Error( 'mcp_subtitle_upload_failed', $message, [ 'status' => 500 ] );
	}
}
