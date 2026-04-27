<?php
/**
 * MCP Tool：course_create — 建立新課程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Course\Service\Crud;

/**
 * Class CourseCreateTool
 * 建立新課程
 */
final class CourseCreateTool extends AbstractTool {

	/**
	 * 取得 tool 名稱
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'course_create';
	}

	/**
	 * 取得 tool 標籤
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( '建立課程', 'power-course' );
	}

	/**
	 * 取得 tool 描述
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'建立一門新課程（底層為 WooCommerce 商品，並設定 _is_course = yes）。可指定名稱、狀態、價格、描述、限制類型等欄位。',
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
				'name'              => [
					'type'        => 'string',
					'minLength'   => 1,
					'description' => __( '課程名稱', 'power-course' ),
				],
				'status'            => [
					'type'        => 'string',
					'enum'        => [ 'publish', 'draft', 'pending', 'private' ],
					'default'     => 'draft',
					'description' => __( '課程狀態', 'power-course' ),
				],
				'regular_price'     => [
					'type'        => 'string',
					'description' => __( '原價（字串，避免浮點精度問題）', 'power-course' ),
				],
				'sale_price'        => [
					'type'        => 'string',
					'description' => __( '促銷價', 'power-course' ),
				],
				'description'       => [
					'type'        => 'string',
					'description' => __( '課程完整描述', 'power-course' ),
				],
				'short_description' => [
					'type'        => 'string',
					'description' => __( '課程簡短描述', 'power-course' ),
				],
				'limit_type'        => [
					'type'        => 'string',
					'enum'        => [ 'unlimited', 'fixed', 'assigned' ],
					'description' => __( '觀看期限類型', 'power-course' ),
				],
				'limit_value'       => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '觀看期限值', 'power-course' ),
				],
				'limit_unit'        => [
					'type'        => 'string',
					'enum'        => [ 'day', 'month', 'year' ],
					'description' => __( '觀看期限單位', 'power-course' ),
				],
			],
			'required'   => [ 'name' ],
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
					'description' => __( '新建立的課程 ID', 'power-course' ),
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
		if ( ! isset( $args['name'] ) || ! is_string( $args['name'] ) || '' === trim( $args['name'] ) ) {
			return new \WP_Error(
				'course_name_required',
				__( 'name 為必填', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$payload = [];
		foreach ( $args as $key => $value ) {
			if ( is_scalar( $value ) || is_array( $value ) ) {
				$payload[ (string) $key ] = $value;
			}
		}

		$result  = Crud::create( $payload );
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
