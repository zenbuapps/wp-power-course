<?php
/**
 * MCP Progress Mark Chapter Finished Tool
 *
 * 將指定章節明確標記為完成或未完成（不做 toggle 切換，而是直接設定）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Progress as ChapterProgress;

/**
 * Class ProgressMarkChapterFinishedTool
 *
 * 對應 MCP ability：power-course/progress_mark_chapter_finished
 *
 * 權限規則：
 * - 基礎 capability = 'read'（AbstractTool 檢查）
 * - 若 user_id 非當前登入用戶 → 額外強制 edit_users capability
 */
final class ProgressMarkChapterFinishedTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'progress_mark_chapter_finished';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Mark chapter finished', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Directly mark a chapter as finished or unfinished for a student (explicit set, not toggle).', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'chapter_id'  => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Chapter ID.', 'power-course' ),
				],
				'user_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Target user ID; defaults to the current logged-in user when omitted.', 'power-course' ),
				],
				'is_finished' => [
					'type'        => 'boolean',
					'description' => \__( 'Target state; true = finished, false = unfinished. Defaults to true.', 'power-course' ),
					'default'     => true,
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
				'success'     => [ 'type' => 'boolean' ],
				'chapter_id'  => [ 'type' => 'integer' ],
				'user_id'     => [ 'type' => 'integer' ],
				'is_finished' => [ 'type' => 'boolean' ],
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
		return 'progress';
	}

	/**
	 * 執行標記完成狀態
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{success: bool, chapter_id: int, user_id: int, is_finished: bool}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$chapter_id = (int) ( $args['chapter_id'] ?? 0 );
		if ( $chapter_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'chapter_id is required and must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$is_finished = array_key_exists( 'is_finished', $args ) ? (bool) $args['is_finished'] : true;

		$current_user_id = \get_current_user_id();
		$target_user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : $current_user_id;

		if ( $target_user_id <= 0 ) {
			$target_user_id = $current_user_id;
		}

		// 跨用戶操作需額外權限
		if ( $target_user_id !== $current_user_id && ! \current_user_can( 'edit_users' ) ) {
			return new \WP_Error(
				'mcp_permission_denied',
				\__( 'Modifying other users progress requires edit_users capability.', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		$logger = new ActivityLogger();

		try {
			$success = ChapterProgress::toggle_finish( $chapter_id, $target_user_id, $is_finished );
		} catch ( \RuntimeException $e ) {
			$logger->log( $this->get_name(), $current_user_id, $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_progress_mark_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		$result = [
			'success'     => $success,
			'chapter_id'  => $chapter_id,
			'user_id'     => $target_user_id,
			'is_finished' => $success ? $is_finished : ChapterProgress::is_finished( $chapter_id, $target_user_id ),
		];

		$logger->log( $this->get_name(), $current_user_id, $args, $result, $success );

		return $result;
	}
}
