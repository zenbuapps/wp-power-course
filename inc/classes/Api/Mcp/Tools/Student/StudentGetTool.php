<?php
/**
 * MCP Student Get Tool
 *
 * 取得單一學員詳情（含擁有課程清單）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Student\Service\Query;

/**
 * Class StudentGetTool
 *
 * 對應 MCP ability：power-course/student_get
 */
final class StudentGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '取得學員詳情', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '取得單一學員詳情，包含基本資料與該學員擁有的課程 ID 清單。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '學員 ID。', 'power-course' ),
				],
			],
			'required'   => [ 'user_id' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'user_id'         => [ 'type' => 'integer' ],
				'user_login'      => [ 'type' => 'string' ],
				'user_email'      => [ 'type' => 'string' ],
				'display_name'    => [ 'type' => 'string' ],
				'user_registered' => [ 'type' => 'string' ],
				'first_name'      => [ 'type' => 'string' ],
				'last_name'       => [ 'type' => 'string' ],
				'avl_course_ids'  => [
					'type'  => 'array',
					'items' => [ 'type' => 'integer' ],
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'list_users';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'student';
	}

	/**
	 * 執行取得學員詳情
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['user_id'] ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				\__( 'user_id 為必填欄位。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$user_id = (int) $args['user_id'];

		return Query::get( $user_id );
	}
}
