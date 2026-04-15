<?php
/**
 * Course Query Service — 課程查詢服務
 *
 * 抽取自 J7\PowerCourse\Api\Course 的 callback 業務邏輯，
 * 供 REST callback 與 MCP tool 共用，遵循 SRP。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class Query
 * 課程讀取（list / get）相關服務
 */
final class Query {

	/**
	 * 列出課程（支援分頁 / 篩選 / 排序）
	 *
	 * @param array<string, mixed> $args 查詢參數（對應 wc_get_products 的參數）
	 *                                   可包含：
	 *                                   - status: array<string>|string 文章狀態
	 *                                   - posts_per_page: int 每頁筆數
	 *                                   - paged: int 頁碼
	 *                                   - orderby: string 排序欄位
	 *                                   - order: 'ASC'|'DESC'
	 *                                   - s: string 搜尋關鍵字
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 *         回傳格式化後的課程資料與分頁資訊
	 */
	public static function list( array $args = [] ): array {
		$default_args = [
			'status'         => [ 'publish', 'draft' ],
			'paginate'       => true,
			'posts_per_page' => 10,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => '_is_course',
			'meta_value'     => 'yes',
		];

		$args = \wp_parse_args( $args, $default_args );

		/** @var object{total:int, max_num_pages:int, products:array<int, \WC_Product>} $results */
		$results     = \wc_get_products( $args );
		$total       = (int) $results->total;
		$total_pages = (int) $results->max_num_pages;
		$products    = $results->products;

		$api = CourseApi::instance();

		$formatted = array_values(
			array_map(
				/**
				 * @param \WC_Product $product WooCommerce 商品物件
				 * @return array<string, mixed>
				 */
				static fn( \WC_Product $product ): array => $api->format_course_base_records( $product ),
				$products
			)
		);

		return [
			'items'       => $formatted,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * 取得單一課程詳情
	 *
	 * @param int $id 課程（商品）ID
	 * @return array<string, mixed>|\WP_Error 格式化後的課程資料，找不到時回傳 WP_Error
	 */
	public static function get( int $id ): array|\WP_Error {
		if ( $id <= 0 ) {
			return new \WP_Error(
				'course_invalid_id',
				__( 'id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$product = \wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return new \WP_Error(
				'course_not_found',
				__( '找不到指定的課程', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$api = CourseApi::instance();
		return $api->format_course_records( $product );
	}
}
