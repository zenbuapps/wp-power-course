<?php
/**
 * MCP Student Add To Course Tool
 *
 * 將學員加入課程（授權）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Course\Service\AddStudent;

/**
 * Class StudentAddToCourseTool
 *
 * 對應 MCP ability：power-course/student_add_to_course
 */
final class StudentAddToCourseTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_add_to_course';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '將學員加入課程', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '手動將指定學員授權至指定課程（對應管理員手動開通），可設定到期日。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'     => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '學員 ID。', 'power-course' ),
				],
				'course_id'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '課程 ID。', 'power-course' ),
				],
				'expire_date' => [
					'type'        => [ 'integer', 'string' ],
					'description' => \__( '到期日：10 位 timestamp；或 0 代表永久；或 "subscription_{id}" 字串。', 'power-course' ),
					'default'     => 0,
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
				'user_id'     => [ 'type' => 'integer' ],
				'course_id'   => [ 'type' => 'integer' ],
				'expire_date' => [ 'type' => [ 'integer', 'string' ] ],
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
	 * 執行加入課程
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{user_id: int, course_id: int, expire_date: int|string}|\WP_Error
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
		if ( $user_id <= 0 || $course_id <= 0 ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id 與 course_id 需為正整數。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! \get_user_by( 'id', $user_id ) ) {
			return new \WP_Error(
				'mcp_user_not_found',
				\__( '找不到指定的學員。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! \wc_get_product( $course_id ) ) {
			return new \WP_Error(
				'mcp_course_not_found',
				\__( '找不到指定的課程。', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		/** @var int|string $expire_date */
		$expire_date = $args['expire_date'] ?? 0;
		if ( \is_string( $expire_date ) ) {
			$expire_date = \sanitize_text_field( $expire_date );
		} else {
			$expire_date = (int) $expire_date;
		}

		try {
			$adder = new AddStudent();
			$adder->add_item( $user_id, $course_id, $expire_date, null );
			$adder->do_action();
		} catch ( \Throwable $th ) {
			( new ActivityLogger() )->log(
				$this->get_name(),
				\get_current_user_id(),
				$args,
				$th->getMessage(),
				false
			);
			return new \WP_Error(
				'mcp_student_add_failed',
				$th->getMessage(),
				[ 'status' => 500 ]
			);
		}

		$result = [
			'user_id'     => $user_id,
			'course_id'   => $course_id,
			'expire_date' => $expire_date,
		];

		( new ActivityLogger() )->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			$result,
			true
		);

		return $result;
	}
}
