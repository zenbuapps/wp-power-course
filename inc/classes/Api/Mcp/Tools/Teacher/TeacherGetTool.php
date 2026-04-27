<?php
/**
 * MCP Tool：teacher_get — 取得講師詳情 + 授課清單
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Teacher;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Teacher\Service\Query;

/**
 * Class TeacherGetTool
 * 依 user_id 取得單一講師詳情，包含授課課程清單
 */
final class TeacherGetTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'teacher_get';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '取得講師詳情', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__(
			'依 user_id 取得講師的完整詳情，包含帳號資訊與授課課程清單。',
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
				'user_id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => \__( '講師的 WP user ID', 'power-course' ),
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
				'id'               => [
					'type'        => 'integer',
					'description' => \__( '講師 user ID', 'power-course' ),
				],
				'display_name'     => [
					'type'        => 'string',
					'description' => \__( '講師顯示名稱', 'power-course' ),
				],
				'user_email'       => [
					'type'        => 'string',
					'description' => \__( '講師 email', 'power-course' ),
				],
				'authored_courses' => [
					'type'        => 'array',
					'description' => \__( '授課的課程清單', 'power-course' ),
					'items'       => [
						'type' => 'object',
					],
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
		return 'teacher';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['user_id'] ) ) {
			return new \WP_Error(
				'teacher_user_id_required',
				\__( 'user_id 為必填', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$user_id = (int) $args['user_id'];
		return Query::get( $user_id );
	}
}
