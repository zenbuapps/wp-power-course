<?php
/**
 * MCP Student Get Progress Tool
 *
 * 取得指定學員在指定課程的學習進度。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Student\Service\Progress;

/**
 * Class StudentGetProgressTool
 *
 * 對應 MCP ability：power-course/student_get_progress
 */
final class StudentGetProgressTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_get_progress';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '取得學員課程進度', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '取得指定學員在指定課程的學習進度摘要（完成章節數、進度百分比、到期狀態）。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '學員 ID。', 'power-course' ),
				],
				'course_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '課程 ID。', 'power-course' ),
				],
			],
			'required'   => [ 'user_id', 'course_id' ],
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
		return 'student';
	}

	/**
	 * 執行取得進度
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['user_id'] ) || ! isset( $args['course_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id 與 course_id 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$user_id   = (int) $args['user_id'];
		$course_id = (int) $args['course_id'];

		return Progress::get_progress( $course_id, $user_id );
	}
}
