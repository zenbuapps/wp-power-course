<?php
/**
 * MCP Chapter Toggle Finish Tool
 *
 * 切換章節完成狀態（progress）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Progress as ChapterProgress;

/**
 * Class ChapterToggleFinishTool
 *
 * 對應 MCP ability：power-course/chapter_toggle_finish
 *
 * 權限規則：
 * - 預設 capability = 'read'（AbstractTool 會檢查）
 * - 若 user_id 非當前登入用戶 → 額外強制 edit_users capability
 */
final class ChapterToggleFinishTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_toggle_finish';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '切換章節完成狀態', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '將章節標示為已完成或未完成；可為當前用戶或指定其他用戶（需 edit_users 權限）。', 'power-course' );
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
					'description' => __( '欲切換狀態的章節 ID。', 'power-course' ),
				],
				'user_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '目標用戶 ID；省略時為當前登入者。', 'power-course' ),
				],
				'is_finished' => [
					'type'        => 'boolean',
					'description' => __( '目標狀態；true=已完成，false=未完成。', 'power-course' ),
				],
			],
			'required'   => [ 'chapter_id', 'is_finished' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success'     => [
					'type'        => 'boolean',
					'description' => __( '是否切換成功。', 'power-course' ),
				],
				'chapter_id'  => [
					'type'        => 'integer',
					'description' => __( '章節 ID。', 'power-course' ),
				],
				'user_id'     => [
					'type'        => 'integer',
					'description' => __( '目標用戶 ID。', 'power-course' ),
				],
				'is_finished' => [
					'type'        => 'boolean',
					'description' => __( '切換後的完成狀態。', 'power-course' ),
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
	 * 顯式覆寫為 OP_UPDATE：toggle 會寫入學員進度（pc_avl_chaptermeta），
	 * 不應在唯讀模式下被允許。雖然預設規則對 _toggle_ 也會 fallback 到 OP_UPDATE，
	 * 但為防止未來規則調整誤分類為 read，於此顯式覆寫保險。
	 *
	 * @return string
	 */
	public function get_operation_type(): string {
		return self::OP_UPDATE;
	}

	/**
	 * 執行切換完成狀態
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
				__( 'chapter_id 為必填且必須為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! array_key_exists( 'is_finished', $args ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'is_finished 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}
		$is_finished = (bool) $args['is_finished'];

		$current_user_id = \get_current_user_id();
		$target_user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : $current_user_id;

		if ( $target_user_id <= 0 ) {
			$target_user_id = $current_user_id;
		}

		// 跨用戶操作需額外權限
		if ( $target_user_id !== $current_user_id && ! \current_user_can( 'edit_users' ) ) {
			return new \WP_Error(
				'mcp_permission_denied',
				__( '修改其他用戶的章節進度需要 edit_users 權限。', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		try {
			$success = ChapterProgress::toggle_finish( $chapter_id, $target_user_id, $is_finished );
		} catch ( \RuntimeException $e ) {
			$logger = new ActivityLogger();
			$logger->log( $this->get_name(), $current_user_id, $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_chapter_toggle_finish_failed',
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

		$logger = new ActivityLogger();
		$logger->log( $this->get_name(), $current_user_id, $args, $result, $success );

		return $result;
	}
}
