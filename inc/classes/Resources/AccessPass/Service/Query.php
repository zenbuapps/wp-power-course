<?php
/**
 * AccessPass Service Query（Issue #252）
 *
 * 提供課程權限包（pc_access_pass CPT）的查詢業務邏輯，供 REST callback 使用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;

/**
 * Class Query
 *
 * 職責：封裝課程權限包列表查詢，並注入每筆已掛載的商品數（attached_product_count）。
 */
final class Query {

	/** @var array<string> 合法的狀態篩選值 */
	private const STATUSES = [ 'active', 'disabled' ];

	/**
	 * 權限包列表
	 *
	 * 支援篩選：
	 *   - status：active | disabled（未指定則回傳全部，含 disabled）
	 *
	 * 每筆結果除 AccessPass::to_array() 欄位外，額外注入：
	 *   - attached_product_count：已掛載此權限包的商品數（反查 product 的 access_pass_id meta = 此 id）
	 *
	 * @param array<string, mixed> $args 查詢參數
	 *
	 * @return array<int, array<string, mixed>> 格式化後的權限包列表
	 */
	public static function list( array $args = [] ): array {
		$query_args = [
			'post_type'      => CPT::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		// status 篩選（以 postmeta access_pass_status 比對）
		$status = isset( $args['status'] ) ? (string) $args['status'] : '';
		if ( \in_array( $status, self::STATUSES, true ) ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => 'access_pass_status',
					'value'   => $status,
					'compare' => '=',
				],
			];
		}

		/** @var array<int> $ids */
		$ids = \get_posts( $query_args );

		$list = [];
		foreach ( $ids as $id ) {
			$model = AccessPass::instance( (int) $id );
			if ( null === $model ) {
				continue;
			}
			$item                           = $model->to_array();
			$item['attached_product_count'] = self::count_attached_products( (int) $id );
			$list[]                         = $item;
		}

		return $list;
	}

	/**
	 * 計算已掛載指定權限包的商品數
	 *
	 * 反查有多少 product 的 access_pass_id meta 等於此權限包 ID。
	 *
	 * @param int $pass_id 權限包 post ID
	 *
	 * @return int 已掛載的商品數
	 */
	private static function count_attached_products( int $pass_id ): int {
		$product_ids = \get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'access_pass_id',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $pass_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return \count( $product_ids );
	}
}
