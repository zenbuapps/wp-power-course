<?php
/**
 * MCP Student Remove From Course Tool
 *
 * 將學員從課程移除（撤銷授權）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Course\Service\RemoveStudent;

/**
 * Class StudentRemoveFromCourseTool
 *
 * 對應 MCP ability：power-course/student_remove_from_course
 */
final class StudentRemoveFromCourseTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_remove_from_course';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '從課程移除學員', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '撤銷指定學員對指定課程的權限。', 'power-course' );
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
				'user_id'   => [ 'type' => 'integer' ],
				'course_id' => [ 'type' => 'integer' ],
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
		return 'student';
	}

	/**
	 * 執行移除學員
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{user_id: int, course_id: int}|\WP_Error
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

		$result  = RemoveStudent::remove_item( $course_id, $user_id );
		$success = ! \is_wp_error( $result );

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			$success
		);

		return $result;
	}
}
