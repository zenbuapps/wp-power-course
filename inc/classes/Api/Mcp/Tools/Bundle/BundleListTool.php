<?php
/**
 * MCP Tool：bundle_list — 列出銷售方案
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\BundleProduct\Service\Query;

/**
 * Class BundleListTool
 * 列出銷售方案商品（bundle_type 商品），支援分頁/篩選。
 */
final class BundleListTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'bundle_list';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '列出銷售方案', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __(
			'列出 Power Course 系統中的銷售方案（bundle 商品），支援分頁、狀態篩選、排序與依課程過濾。適用於查詢銷售方案總覽或特定課程下的方案。',
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
					'description' => __( '文章狀態清單', 'power-course' ),
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
				'link_course_id' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '篩選綁定指定課程 ID 的銷售方案', 'power-course' ),
				],
				's'              => [
					'type'        => 'string',
					'description' => __( '搜尋關鍵字（比對商品標題）', 'power-course' ),
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
				'items'       => [
					'type'        => 'array',
					'description' => __( '銷售方案清單', 'power-course' ),
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
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'manage_woocommerce';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'bundle';
	}

	/**
	 * 執行業務邏輯
	 *
	 * @param array<string, mixed> $args 呼叫參數
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 */
	protected function execute( array $args ): array {
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
			$order               = strtoupper( sanitize_key( $args['order'] ) );
			$query_args['order'] = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';
		}
		if ( isset( $args['link_course_id'] ) ) {
			$query_args['link_course_id'] = max( 0, (int) $args['link_course_id'] );
		}
		if ( isset( $args['s'] ) && is_string( $args['s'] ) && '' !== $args['s'] ) {
			$query_args['s'] = sanitize_text_field( $args['s'] );
		}

		return Query::list( $query_args );
	}
}
