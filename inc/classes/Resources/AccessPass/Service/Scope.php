<?php
/**
 * AccessPass Scope（正向範圍展開）— Issue #252 補完
 *
 * Gate 解的是反向問題「這門課有沒有被這張通行證涵蓋」（觀看判定，compute-on-read）；
 * 前台「我的通行證」要解的是正向問題「這張通行證涵蓋哪些課」。兩者不能互相推導，
 * 故獨立一個 Service，但**共用 Gate::get_expanded_term_ids()**，確保 category 範圍在
 * 正反兩個方向拿到同一組 term（含子分類展開），不會出現「前台沒列出、實際卻看得到」的分歧。
 *
 * ⚠️ 與 Gate 的一處刻意落差：本 Service 只列 post_status=publish 且 _is_course=yes 的商品，
 * Gate 的 specific 判定則不看狀態（course_ids 命中即涵蓋）。理由：前台清單呈現的是學員實際
 * 看得到的課，草稿 / 已刪除 / 非課程商品不該出現；觀看判定另有 is_course_ready() 等閘門把關。
 *
 * 效能：count_courses() 走 transient 快取（TTL 1 小時），通行證範圍變更時由 Crud 主動失效。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;

/**
 * Class Scope
 * 把通行證的 scope 規則展開成實際的課程 id 清單。
 */
final class Scope {

	/** @var string count 快取的 transient key 前綴 */
	private const COUNT_TRANSIENT_PREFIX = 'pc_ap_scope_count_';

	/** 涵蓋課程數快取的存活秒數（1 小時） */
	private const COUNT_TRANSIENT_TTL = \HOUR_IN_SECONDS;

	/**
	 * 取得通行證涵蓋的課程 id（分頁）
	 *
	 * @param AccessPass $pass   權限包 Model
	 * @param int        $limit  每頁筆數（<= 0 時回傳全部）
	 * @param int        $offset 位移
	 *
	 * @return array<int> 課程（商品）post ID 清單；範圍為空時回傳空陣列
	 */
	public static function get_course_ids( AccessPass $pass, int $limit = 20, int $offset = 0 ): array {
		$args = self::build_query_args( $pass );
		if ( ! $args ) {
			return [];
		}

		$args['posts_per_page'] = $limit > 0 ? $limit : -1;
		$args['offset']         = \max( 0, $offset );

		$query = new \WP_Query( $args );

		// fields=ids 下 posts 應為 int[]，但 WP_Query 的型別宣告是 int[]|WP_Post[]，逐筆收斂
		$ids = [];
		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return $ids;
	}

	/**
	 * 計算通行證涵蓋的課程總數（transient 快取）
	 *
	 * @param AccessPass $pass 權限包 Model
	 *
	 * @return int 涵蓋的課程數；範圍為空時回傳 0
	 */
	public static function count_courses( AccessPass $pass ): int {
		$cache_key = self::COUNT_TRANSIENT_PREFIX . $pass->id;
		$cached    = \get_transient( $cache_key );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$args = self::build_query_args( $pass );
		if ( ! $args ) {
			\set_transient( $cache_key, 0, self::COUNT_TRANSIENT_TTL );
			return 0;
		}

		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;

		$query = new \WP_Query( $args );
		$count = (int) $query->found_posts;

		\set_transient( $cache_key, $count, self::COUNT_TRANSIENT_TTL );
		return $count;
	}

	/**
	 * 失效指定通行證的 count 快取
	 *
	 * 由 Crud::update() / Crud::delete() 在範圍或狀態變更後呼叫。
	 *
	 * @param int $pass_id 權限包 post ID
	 *
	 * @return void
	 */
	public static function flush_count_cache( int $pass_id ): void {
		\delete_transient( self::COUNT_TRANSIENT_PREFIX . $pass_id );
	}

	/**
	 * 依 scope_type 組出 WP_Query 參數
	 *
	 *   - all      → 全部已發布的課程商品
	 *   - category → 課程的 product_cat / product_tag 命中「所選 term ∪ 其子孫分類」任一
	 *   - specific → post__in 固定清單（依設定順序）
	 *
	 * ⚠️ category 分支刻意用 include_children=false：子分類已由 Gate::get_expanded_term_ids()
	 * 展開過，再讓 WP 展開一次會與 Gate 的判定範圍不一致。
	 *
	 * @param AccessPass $pass 權限包 Model
	 *
	 * @return array<string, mixed> WP_Query 參數；範圍為空 / scope_type 非法時回傳空陣列
	 */
	private static function build_query_args( AccessPass $pass ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_key'       => '_is_course',  // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => 'yes',         // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'posts_per_page' => -1,
		];

		switch ( $pass->scope_type ) {
			case 'all':
				return $args;

			case 'category':
				$expanded = Gate::get_expanded_term_ids( $pass->id, $pass->term_ids );
				if ( ! $expanded ) {
					return [];
				}
				$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'relation' => 'OR',
					[
						'taxonomy'         => 'product_cat',
						'field'            => 'term_id',
						'terms'            => $expanded,
						'include_children' => false,
					],
					[
						'taxonomy'         => 'product_tag',
						'field'            => 'term_id',
						'terms'            => $expanded,
						'include_children' => false,
					],
				];
				return $args;

			case 'specific':
				if ( ! $pass->course_ids ) {
					return [];
				}
				$args['post__in'] = $pass->course_ids;
				$args['orderby']  = 'post__in';
				return $args;

			default:
				return [];
		}
	}
}
