<?php
/**
 * MCP Tool：course_get — 取得單一課程詳情
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Course\Service\Query;

/**
 * Class CourseGetTool
 * 取得單一課程詳情
 */
final class CourseGetTool extends AbstractTool {

	/**
	 * 取得 tool 名稱
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'course_get';
	}

	/**
	 * 取得 tool 標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( '取得課程詳情', 'power-course' );
	}

	/**
	 * 取得 tool 描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'依課程 ID 取得單一課程的完整詳情，包含章節、價格、限制、訂閱設定、銷售方案、老師等欄位。',
			'power-course'
		);
	}

	/**
	 * 取得 input JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '課程（商品）ID', 'power-course' ),
				],
			],
			'required'   => [ 'id' ],
		];
	}

	/**
	 * 取得 output JSON Schema
	 *
	 * @return array{type: string, properties: array<string, mixed>}
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'id'   => [
					'type'        => 'string',
					'description' => __( '課程 ID', 'power-course' ),
				],
				'name' => [
					'type'        => 'string',
					'description' => __( '課程名稱', 'power-course' ),
				],
			],
		];
	}

	/**
	 * 取得執行所需 capability
	 *
	 * @return string
	 */
	public function get_capability(): string {
		return 'read';
	}

	/**
	 * 取得 category
	 *
	 * @return string
	 */
	public function get_category(): string {
		return 'course';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array<string, mixed>|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['id'] ) ) {
			return new \WP_Error(
				'course_id_required',
				__( 'id 為必填', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$id = (int) $args['id'];
		return Query::get( $id );
	}
}
