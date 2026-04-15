<?php
/**
 * MCP Tool：teacher_remove_from_course — 從課程移除講師指派
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Teacher\Service\Assignment;

/**
 * Class TeacherRemoveFromCourseTool
 * 將講師從指定課程移除（從 teacher_ids post_meta 刪除對應 user_id）
 */
final class TeacherRemoveFromCourseTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'teacher_remove_from_course';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '從課程移除講師', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__(
			'將指定講師從指定課程的 `teacher_ids` post_meta 移除。未指派時仍視為成功（idempotent），不會影響 user 本身的講師身分。',
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
					'description' => \__( '要移除的講師 user ID', 'power-course' ),
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
					'description' => \__( '是否移除成功', 'power-course' ),
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
				'teacher_remove_invalid_input',
				\__( 'course_id 與 user_id 皆為必填且必須為正整數', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$result  = Assignment::remove( $course_id, $user_id );
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
