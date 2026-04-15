<?php
/**
 * MCP Tool：teacher_assign_to_course — 指派講師到課程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Teacher\Service\Assignment;

/**
 * Class TeacherAssignToCourseTool
 * 將指定講師指派到指定課程（teacher_ids post_meta），idempotent
 */
final class TeacherAssignToCourseTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'teacher_assign_to_course';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '指派講師到課程', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__(
			'將指定講師指派到指定課程（於 course 的 `teacher_ids` post_meta 新增一筆 user_id）。已指派時視為成功不重複新增（idempotent）。',
			'power-course'
		);
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
					'description' => \__( '課程 ID', 'power-course' ),
				],
				'user_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '要指派的講師 user ID（該 user 必須已是講師）', 'power-course' ),
				],
			],
			'required'   => [ 'course_id', 'user_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success'   => [
					'type'        => 'boolean',
					'description' => \__( '是否指派成功', 'power-course' ),
				],
				'course_id' => [
					'type'        => 'integer',
					'description' => \__( '課程 ID', 'power-course' ),
				],
				'user_id'   => [
					'type'        => 'integer',
					'description' => \__( '講師 user ID', 'power-course' ),
				],
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
		return 'teacher';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array{success: bool, course_id: int, user_id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$course_id = (int) ( $args['course_id'] ?? 0 );
		$user_id   = (int) ( $args['user_id'] ?? 0 );

		if ( $course_id <= 0 || $user_id <= 0 ) {
			return new \WP_Error(
				'teacher_assign_invalid_input',
				\__( 'course_id 與 user_id 皆為必填且必須為正整數', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$result  = Assignment::assign( $course_id, $user_id );
		$success = ! \is_wp_error( $result );

		$logger = new ActivityLogger();
		$logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$success
				? [
					'success'   => true,
					'course_id' => $course_id,
					'user_id'   => $user_id,
				]
				: $result,
			$success
		);

		if ( ! $success ) {
			return $result;
		}

		return [
			'success'   => true,
			'course_id' => $course_id,
			'user_id'   => $user_id,
		];
	}
}
