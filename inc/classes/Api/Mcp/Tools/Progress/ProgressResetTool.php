<?php
/**
 * MCP Progress Reset Tool
 *
 * ⚠️ 高破壞性操作：重置指定學員在指定課程的所有章節進度。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Student\Service\Progress as StudentProgress;

/**
 * Class ProgressResetTool
 *
 * 對應 MCP ability：power-course/progress_reset
 *
 * 權限規則：
 * - capability 強制 = 'edit_users'（不允許自行重置，一律高權限）
 * - 必須提供 confirm = true，否則拒絕執行
 */
final class ProgressResetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'progress_reset';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Reset student progress', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'DANGEROUS: delete all chapter progress records for a student in a specific course. Requires confirm = true and edit_users capability.', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Course ID whose progress should be reset.', 'power-course' ),
				],
				'user_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Target student user ID.', 'power-course' ),
				],
				'confirm'   => [
					'type'        => 'boolean',
					'const'       => true,
					'description' => \__( 'Must be explicitly set to true to execute the reset. Acts as a safety guard for this destructive operation.', 'power-course' ),
				],
			],
			'required'   => [ 'course_id', 'user_id', 'confirm' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success'       => [ 'type' => 'boolean' ],
				'course_id'     => [ 'type' => 'integer' ],
				'user_id'       => [ 'type' => 'integer' ],
				'deleted_rows'  => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'edit_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'progress';
	}

	/**
	 * 執行重置
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{success: bool, course_id: int, user_id: int, deleted_rows: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$current_user_id = \get_current_user_id();
		$logger          = new ActivityLogger();

		// 嚴格 confirm flag 檢查：必須是 bool true（不允許 1、'true'、'1' 等值）
		$confirm = $args['confirm'] ?? null;
		if ( true !== $confirm ) {
			$logger->log(
				$this->get_name(),
				$current_user_id,
				$args,
				'confirm flag missing or not strictly true',
				false
			);

			return new \WP_Error(
				'mcp_confirm_required',
				\__( 'progress_reset is a destructive operation. Set confirm = true explicitly to proceed.', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! isset( $args['course_id'] ) || ! isset( $args['user_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id and user_id are required.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$course_id = (int) $args['course_id'];
		$user_id   = (int) $args['user_id'];

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id and user_id must be positive integers.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! \get_user_by( 'id', $user_id ) ) {
			return new \WP_Error(
				'mcp_user_not_found',
				\__( 'Target student not found.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! \wc_get_product( $course_id ) ) {
			return new \WP_Error(
				'mcp_course_not_found',
				\__( 'Target course not found.', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		try {
			$deleted_rows = StudentProgress::reset( $course_id, $user_id );
		} catch ( \Throwable $th ) {
			$logger->log( $this->get_name(), $current_user_id, $args, $th->getMessage(), false );

			return new \WP_Error(
				'mcp_progress_reset_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'success'      => true,
			'course_id'    => $course_id,
			'user_id'      => $user_id,
			'deleted_rows' => $deleted_rows,
		];

		$logger->log( $this->get_name(), $current_user_id, $args, $result, true );

		return $result;
	}
}
