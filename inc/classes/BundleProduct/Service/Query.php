<?php
/**
 * Bundle Query Service — 銷售方案查詢服務
 *
 * 供 MCP tool（bundle_list）使用，列出 bundle 類型的商品。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\BundleProduct\Service;

use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class Query
 * 銷售方案（bundle_type 商品）讀取服務
 */
final class Query {

	/**
	 * 列出銷售方案（bundle_type 商品）
	 *
	 * 透過 `wc_get_products` 過濾出 `bundle_type` meta 存在（非空）的商品。
	 *
	 * @param array<string, mixed> $args 查詢參數：
	 *                                   - status: array<string>|string 文章狀態（預設 publish/draft）
	 *                                   - paged: int 頁碼
	 *                                   - posts_per_page: int 每頁筆數
	 *                                   - orderby: string 排序欄位
	 *                                   - order: 'ASC'|'DESC'
	 *                                   - link_course_id: int 篩選綁定某課程的方案
	 *                                   - s: string 搜尋關鍵字（商品標題）
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 *         銷售方案清單與分頁資訊
	 */
	public static function list( array $args = [] ): array {
		$meta_query = [
			[
				'key'     => 'bundle_type',
				'compare' => 'EXISTS',
			],
		];

		// 可選：篩選綁定的課程
		if ( isset( $args['link_course_id'] ) && (int) $args['link_course_id'] > 0 ) {
			$meta_query[] = [
				'key'     => Helper::LINK_COURSE_IDS_META_KEY,
				'value'   => (string) (int) $args['link_course_id'],
				'compare' => '=',
			];
		}

		$default_args = [
			'status'         => [ 'publish', 'draft' ],
			'paginate'       => true,
			'posts_per_page' => 10,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		];

		// 合併除 meta_query 以外的 args（meta_query 固定由此方法控制）
		unset( $args['link_course_id'] );
		$query_args = \wp_parse_args( $args, $default_args );

		/** @var object{total:int, max_num_pages:int, products:array<int, \WC_Product>} $results */
		$results     = \wc_get_products( $query_args );
		$total       = (int) $results->total;
		$total_pages = (int) $results->max_num_pages;
		$products    = $results->products;

		$items = [];
		foreach ( $products as $product ) {
			$helper = Helper::instance( $product );
			if ( null === $helper || ! $helper->is_bundle_product ) {
				continue;
			}

			$items[] = self::format( $product, $helper );
		}

		return [
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * 格式化銷售方案摘要資料
	 *
	 * @param \WC_Product $product 商品物件
	 * @param Helper      $helper Helper 實例
	 * @return array<string, mixed>
	 */
	public static function format( \WC_Product $product, Helper $helper ): array {
		$bundle_id = (int) $product->get_id();

		return [
			'id'              => $bundle_id,
			'name'            => $product->get_name(),
			'status'          => $product->get_status(),
			'price'           => (string) $product->get_price(),
			'regular_price'   => (string) $product->get_regular_price(),
			'bundle_type'     => $helper->bundle_type,
			'link_course_id'  => $helper->link_course_id,
			'product_ids'     => array_map( 'intval', $helper->get_product_ids() ),
			'product_ids_with_compat' => array_map( 'intval', $helper->get_product_ids_with_compat() ),
			'product_quantities' => $helper->get_product_quantities(),
		];
	}
}
