<?php
/**
 * MCP Tool：course_duplicate — 複製課程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Course\Service\Crud;

/**
 * Class CourseDuplicateTool
 * 複製課程（含章節與銷售方案）
 */
final class CourseDuplicateTool extends AbstractTool {

	/**
	 * 取得 tool 名稱
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'course_duplicate';
	}

	/**
	 * 取得 tool 標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( '複製課程', 'power-course' );
	}

	/**
	 * 取得 tool 描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'複製一門既有課程為新課程（預設為 draft 狀態），同時複製章節、銷售方案關聯。新課程名稱會附加 (Copy) 後綴。',
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
					'description' => __( '要複製的原課程 ID', 'power-course' ),
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
				'id' => [
					'type'        => 'integer',
					'description' => __( '新複製出來的課程 ID', 'power-course' ),
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
		return 'manage_woocommerce';
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
	 * @return array{id: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		if ( ! isset( $args['id'] ) ) {
			return new \WP_Error(
				'course_id_required',
				__( 'id 為必填', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$id      = (int) $args['id'];
		$result  = Crud::duplicate( $id );
		$success = ! \is_wp_error( $result );

		( new ActivityLogger() )->log(
			$this->get_name(),
			get_current_user_id(),
			$args,
			$result,
			$success
		);

		return $result;
	}
}
