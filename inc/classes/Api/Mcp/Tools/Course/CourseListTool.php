<?php
/**
 * MCP Tool：course_list — 列出課程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Resources\Course\Service\Query;

/**
 * Class CourseListTool
 * 列出課程（支援分頁、篩選、排序）
 */
final class CourseListTool extends AbstractTool {

	/**
	 * 取得 tool 名稱
	 *
	 * @return string
	 */
	public function get_name(): string {
		return 'course_list';
	}

	/**
	 * 取得 tool 標籤（繁體中文）
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( '列出課程', 'power-course' );
	}

	/**
	 * 取得 tool 描述（供 LLM 判斷何時呼叫）
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'列出 Power Course 系統中的課程清單，支援分頁、狀態篩選、排序與關鍵字搜尋。適用於查詢課程總覽、篩選特定狀態的課程、或在管理介面取得課程列表。',
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
				'paged'          => [
					'type'        => 'integer',
					'minimum'     => 1,
					'default'     => 1,
					'description' => __( '頁碼（從 1 開始）', 'power-course' ),
				],
				'posts_per_page' => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
					'description' => __( '每頁筆數，最大 100', 'power-course' ),
				],
				'status'         => [
					'type'        => 'array',
					'items'       => [
						'type' => 'string',
						'enum' => [ 'publish', 'draft', 'pending', 'private', 'trash', 'any' ],
					],
					'default'     => [ 'publish', 'draft' ],
					'description' => __( '課程狀態清單', 'power-course' ),
				],
				'orderby'        => [
					'type'        => 'string',
					'enum'        => [ 'date', 'title', 'ID', 'menu_order', 'modified' ],
					'default'     => 'date',
					'description' => __( '排序欄位', 'power-course' ),
				],
				'order'          => [
					'type'        => 'string',
					'enum'        => [ 'ASC', 'DESC' ],
					'default'     => 'DESC',
					'description' => __( '排序方向', 'power-course' ),
				],
				's'              => [
					'type'        => 'string',
					'description' => __( '搜尋關鍵字（比對課程標題）', 'power-course' ),
				],
			],
			'required'   => [],
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
				'items'       => [
					'type'        => 'array',
					'description' => __( '課程清單', 'power-course' ),
					'items'       => [
						'type' => 'object',
					],
				],
				'total'       => [
					'type'        => 'integer',
					'description' => __( '總筆數', 'power-course' ),
				],
				'total_pages' => [
					'type'        => 'integer',
					'description' => __( '總頁數', 'power-course' ),
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
	 * @param array<string, mixed> $args 呼叫參數（已通過 permission_callback 驗證）
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	protected function execute( array $args ): array {
		// 清洗並整理查詢參數
		$query_args = [];

		if ( isset( $args['paged'] ) ) {
			$query_args['paged'] = max( 1, (int) $args['paged'] );
		}
		if ( isset( $args['posts_per_page'] ) ) {
			$query_args['posts_per_page'] = min( 100, max( 1, (int) $args['posts_per_page'] ) );
		}
		if ( isset( $args['status'] ) && is_array( $args['status'] ) ) {
			$query_args['status'] = array_values( array_filter( array_map( 'sanitize_key', $args['status'] ) ) );
		}
		if ( isset( $args['orderby'] ) && is_string( $args['orderby'] ) ) {
			$query_args['orderby'] = sanitize_key( $args['orderby'] );
		}
		if ( isset( $args['order'] ) && is_string( $args['order'] ) ) {
			$order                = strtoupper( sanitize_key( $args['order'] ) );
			$query_args['order']  = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
		}
		if ( isset( $args['s'] ) && is_string( $args['s'] ) && '' !== $args['s'] ) {
			$query_args['s'] = sanitize_text_field( $args['s'] );
		}

		return Query::list( $query_args );
	}
}
