<?php
/**
 * MCP Student Export Count Tool
 *
 * 取得指定課程或全域條件下，預計可匯出的學員 × 課程組合數量。
 * 作為 student_export_csv 的前置檢查（避免一次匯出太多資料）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Student;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Student\Service\ExportAllCSV;

/**
 * Class StudentExportCountTool
 *
 * 對應 MCP ability：power-course/student_export_count
 */
final class StudentExportCountTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'student_export_count';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return \__( '取得匯出學員數', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return \__( '取得符合篩選條件、預計將被匯出的學員 × 課程組合數量（用於前置確認）。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'search'         => [
					'type'        => 'string',
					'description' => \__( '關鍵字搜尋。', 'power-course' ),
				],
				'avl_course_ids' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => \__( '要篩選的課程 ID 陣列；省略則為全部課程。', 'power-course' ),
				],
				'include'        => [
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
					'description' => \__( '指定要包含的用戶 ID 陣列。', 'power-course' ),
				],
			],
			'required'   => [],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'count' => [
					'type'        => 'integer',
					'description' => \__( '預計匯出的筆數。', 'power-course' ),
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
	 * 執行計數
	 *
	 * @param array<string, mixed> $args 輸入參數
	 * @return array{count: int}
	 */
	protected function execute( array $args ): array {
		$search = isset( $args['search'] ) && \is_string( $args['search'] )
		? \sanitize_text_field( $args['search'] )
		: '';

		$avl_course_ids = isset( $args['avl_course_ids'] ) && \is_array( $args['avl_course_ids'] )
		? array_map( 'strval', array_map( 'intval', $args['avl_course_ids'] ) )
		: [];

		$include = isset( $args['include'] ) && \is_array( $args['include'] )
		? array_map( 'strval', array_map( 'intval', $args['include'] ) )
		: [];

		$count = ExportAllCSV::get_export_count( $search, $avl_course_ids, $include );

		return [ 'count' => (int) $count ];
	}
}
