<?php
/**
 * MCP Chapter Sort Tool
 *
 * 重排章節順序（原子操作）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Resources\Chapter\Service\Crud as ChapterCrud;

/**
 * Class ChapterSortTool
 *
 * 對應 MCP ability：power-course/chapter_sort
 *
 * 排序操作為原子：全部成功或全部失敗（依賴 Service\Crud::sort 內部 transaction）。
 */
final class ChapterSortTool extends AbstractTool {

	/**
	 * @inheritDoc
	 */
	public function get_name(): string {
		return 'chapter_sort';
	}

	/**
	 * @inheritDoc
	 */
	public function get_label(): string {
		return __( '章節排序', 'power-course' );
	}

	/**
	 * @inheritDoc
	 */
	public function get_description(): string {
		return __( '原子操作地重排章節順序；全部成功或全部失敗。', 'power-course' );
	}

	/**
	 * @inheritDoc
	 *
	 * sortable_data 為 array，每項 { id, parent_id, menu_order, depth }。
	 * 內部轉換為 Service\Crud::sort 所需的 from_tree / to_tree 結構。
	 */
	public function get_input_schema(): array {
		$node_schema = [
			'type'       => 'object',
			'required'   => [ 'id', 'parent_id', 'menu_order' ],
			'properties' => [
				'id'         => [
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( '章節 ID。', 'power-course' ),
				],
				'parent_id'  => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( '父節點 ID（depth=0 時為課程 ID，否則為父章節 ID）。', 'power-course' ),
				],
				'menu_order' => [
					'type'        => 'integer',
					'description' => __( '排序值，數字越小越前面。', 'power-course' ),
				],
				'depth'      => [
					'type'        => 'integer',
					'minimum'     => 0,
					'default'     => 0,
					'description' => __( '巢狀深度；0 表示頂層。', 'power-course' ),
				],
			],
		];

		return [
			'type'       => 'object',
			'properties' => [
				'sortable_data' => [
					'type'        => 'array',
					'items'       => $node_schema,
					'description' => __( '排序後的章節節點清單（新順序）。', 'power-course' ),
				],
				'from_data'     => [
					'type'        => 'array',
					'items'       => $node_schema,
					'description' => __( '排序前的章節節點清單（舊順序）；缺省時以 sortable_data 當作 baseline。', 'power-course' ),
				],
			],
			'required'   => [ 'sortable_data' ],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_output_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'success' => [
					'type'        => 'boolean',
					'description' => __( '是否排序成功。', 'power-course' ),
				],
				'total'   => [
					'type'        => 'integer',
					'description' => __( '被重排的章節數量。', 'power-course' ),
				],
			],
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get_capability(): string {
		return 'edit_posts';
	}

	/**
	 * @inheritDoc
	 */
	public function get_category(): string {
		return 'chapter';
	}

	/**
	 * 執行章節排序
	 *
	 * @param array<string, mixed> $args 輸入參數
	 *
	 * @return array{success: bool, total: int}|\WP_Error
	 */
	protected function execute( array $args ): array|\WP_Error {
		$sortable_data = $args['sortable_data'] ?? null;
		if ( ! is_array( $sortable_data ) || empty( $sortable_data ) ) {
			return new \WP_Error(
				'mcp_invalid_input',
				__( 'sortable_data 為必填且不可為空陣列。', 'power-course' ),
				[ 'status' => 422 ]
			);
		}

		$from_data = isset( $args['from_data'] ) && is_array( $args['from_data'] )
		? $args['from_data']
		: $sortable_data;

		$to_tree   = array_map( [ $this, 'normalize_node' ], $sortable_data );
		$from_tree = array_map( [ $this, 'normalize_node' ], $from_data );

		try {
			/** @var array{from_tree: array<int, array<string, mixed>>, to_tree: array<int, array<string, mixed>>} $params */
			$params = [
				'from_tree' => $from_tree,
				'to_tree'   => $to_tree,
			];
			ChapterCrud::sort( $params );
		} catch ( \RuntimeException $e ) {
			$logger = new ActivityLogger();
			$logger->log( $this->get_name(), \get_current_user_id(), $args, $e->getMessage(), false );

			return new \WP_Error(
				'mcp_chapter_sort_failed',
				$e->getMessage(),
				[ 'status' => 400 ]
			);
		}

		$total = count( $to_tree );

		$logger = new ActivityLogger();
		$logger->log(
			$this->get_name(),
			\get_current_user_id(),
			$args,
			[
				'success' => true,
				'total'   => $total,
			],
			true
		);

		return [
			'success' => true,
			'total'   => $total,
		];
	}

	/**
	 * 將輸入節點正規化為 sort_chapters 所需格式
	 *
	 * @param mixed $node 輸入節點
	 *
	 * @return array<string, string>
	 */
	private function normalize_node( mixed $node ): array {
		if ( ! is_array( $node ) ) {
			return [
				'id'         => '0',
				'parent_id'  => '0',
				'menu_order' => '0',
				'depth'      => '0',
				'name'       => '',
				'slug'       => '',
			];
		}

		return [
			'id'         => (string) ( $node['id'] ?? '0' ),
			'parent_id'  => (string) ( $node['parent_id'] ?? '0' ),
			'menu_order' => (string) ( $node['menu_order'] ?? '0' ),
			'depth'      => (string) ( $node['depth'] ?? '0' ),
			'name'       => (string) ( $node['name'] ?? '' ),
			'slug'       => (string) ( $node['slug'] ?? '' ),
		];
	}
}
