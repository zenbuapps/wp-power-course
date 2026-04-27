<?php
/**
 * MCP Progress Get By User Course Tool
 *
 * 取得指定學員在指定課程的完整進度資訊（章節完成狀態）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Progress;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Student\Service\Progress as StudentProgress;

/**
 * Class ProgressGetByUserCourseTool
 *
 * 對應 MCP ability：power-course/progress_get_by_user_course
 *
 * 權限規則：
 * - 基礎 capability = 'read'
 * - 若 user_id 非當前登入用戶 → 額外強制 edit_users capability
 */
final class ProgressGetByUserCourseTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'progress_get_by_user_course';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( 'Get student progress in a course', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( 'Retrieve complete progress (chapter completion status, progress percentage, expiration) for a student in a specific course.', 'power-course' );
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
					'description' => \__( 'Course ID.', 'power-course' ),
				],
				'user_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( 'Student user ID; defaults to the current logged-in user when omitted.', 'power-course' ),
				],
			],
			'required'   => [ 'course_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'              => [ 'type' => 'integer' ],
				'course_id'            => [ 'type' => 'integer' ],
				'progress'             => [ 'type' => 'number' ],
				'total_chapters'       => [ 'type' => 'integer' ],
				'finished_chapters'    => [ 'type' => 'integer' ],
				'finished_chapter_ids' => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
				'expire_date_label'    => [ 'type' => 'string' ],
				'is_expired'           => [ 'type' => 'boolean' ],
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
	 * 執行取得進度
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['course_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id is required.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$course_id = (int) $args['course_id'];
		if ( $course_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'course_id must be a positive integer.', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$current_user_id = \get_current_user_id();
		$target_user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : $current_user_id;

		if ( $target_user_id <= 0 ) {
			$target_user_id = $current_user_id;
		}

		// 跨用戶查詢需額外權限
		if ( $target_user_id !== $current_user_id && ! \current_user_can( 'edit_users' ) ) {
			return new \WP_Error(
				'mcp_permission_denied',
				\__( 'Reading other users progress requires edit_users capability.', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		return StudentProgress::get_progress( $course_id, $target_user_id );
	}
}
