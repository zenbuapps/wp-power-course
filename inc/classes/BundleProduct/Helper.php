<?php
/**
 * 銷售方案類別
 * 所有商品都可以實力化，並調用方法
 * 但只有 "簡單"跟"簡易訂閱"商品 才是有效的且可創建的 銷售方案
 * 其他商品類型取得 包含商品時，會拿不到資料
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\BundleProduct;

/**
 * 銷售方案 Helper
 */
final class Helper {
	const INCLUDE_PRODUCT_IDS_META_KEY = 'pbp_product_ids'; // 此銷售方案裡面包含的商品 ids
	const LINK_COURSE_IDS_META_KEY     = 'link_course_ids'; // 此銷售方案歸屬於哪個課程 id(s)

	/**
	 * 銷售方案類型 'bundle'
	 *
	 * @var string
	 */
	public string $bundle_type;

	/**
	 * 是否為銷售方案
	 * 用 bundle_type 欄位是否存在判斷
	 *
	 * @var bool
	 */
	public bool $is_bundle_product = false;

	/**
	 * 銷售方案連結的課程 id
	 *
	 * @var int
	 */
	public int $link_course_id;

	/**
	 * Constructor
	 *
	 * @param \WC_Product $product product.
	 *
	 * @throws \Exception 如果找不到商品
	 */
	public function __construct( public \WC_Product $product ) {
		$product_id        = $this->product->get_id();
		$this->bundle_type = (string) \get_post_meta( $product_id, 'bundle_type', true );

		$this->is_bundle_product = (bool) $this->bundle_type;

		$this->link_course_id = (int) \get_post_meta( $product_id, self::LINK_COURSE_IDS_META_KEY, true );
	}


	/**
	 * 實例化 Helper
	 *
	 * @param \WC_Product|int $product product.
	 *
	 * @return self|null
	 */
	public static function instance( \WC_Product|int $product ): self|null {
		if (is_numeric($product)) {
			$product = \wc_get_product($product);
			if (!$product) {
				return null;
			}
		}
		return new self($product);
	}


	/**
	 * 取得某個課程的銷售方案
	 *
	 * @param int                       $course_id 課程 id
	 * @param bool|null                 $return_ids 是否只回傳 id
	 * @param array<string>|null|string $post_status 文章狀態
	 *
	 * @return array<\WC_Product|int> bundle_ids (銷售方案)
	 */
	public static function get_bundle_products( int $course_id, ?bool $return_ids = false, $post_status = [ 'any' ] ): array {

		$args = [
			'post_type'   => 'product',
			'numberposts' => - 1,
			'post_status' => $post_status,
			'meta_key'    => self::LINK_COURSE_IDS_META_KEY,
			'meta_value'  => (string) $course_id,
			'fields'      => 'ids',
			'orderby'     => [
				'menu_order' => 'ASC',
				'ID'         => 'DESC',
				'date'       => 'DESC',
			],
		];
		// @phpstan-ignore-next-line
		$ids = \get_posts($args);
		if ($return_ids) {
			return $ids;
		}
		$products = [];
		foreach ($ids as $id) {
			$product = \wc_get_product($id);
			if (!$product) {
				continue;
			}

			$helper = self::instance($product);
			if (!$helper?->is_bundle_product) {
				continue;
			}
			$products[] = $product;
		}
		return $products;
	}

	/**
	 * 此銷售方案都有哪些商品
	 * 取得 unique 的 product_ids
	 *
	 * @return array<string> 被綑綁的 product_ids
	 */
	public function get_product_ids(): array {
		$id          = $this->product->get_id();
		$product_ids = \get_post_meta( $id, self::INCLUDE_PRODUCT_IDS_META_KEY );
		if (!is_array($product_ids)) {
			$product_ids = [];
		}
		/** @var array<string> $unique_product_ids */
		$unique_product_ids = array_unique( $product_ids );

		// 確保不會因為重複的 meta_value，使得meta_key 不連續，導致在前端應該顯示為 array 的資料變成 object
		return array_values( $unique_product_ids );
	}

	/**
	 * 往銷售方案裡面添加商品 id
	 *
	 * @param int $product_id product_id.
	 *
	 * @return void
	 */
	public function add_bundled_ids( int $product_id ): void {
		$bundle_ids = $this->get_product_ids();
		if ( in_array( (string) $product_id, $bundle_ids, true ) ) {
			return;
		}
		$this->product->add_meta_data( self::INCLUDE_PRODUCT_IDS_META_KEY, (string) $product_id );
		$this->product->save_meta_data();
	}


	/**
	 * 直接設定銷售方案裡面商品 ids
	 *
	 * @param array<int> $product_ids product_ids.
	 *
	 * @return void
	 */
	public function set_bundled_ids( array $product_ids ): void {
		$this->product->delete_meta_data( self::INCLUDE_PRODUCT_IDS_META_KEY );
		foreach ($product_ids as $product_id) {
			$this->product->add_meta_data( self::INCLUDE_PRODUCT_IDS_META_KEY, (string) $product_id );
		}
		$this->product->save_meta_data();
	}

	/**
	 * Delete bundle_ids
	 *
	 * @param int $product_id product_id.
	 *
	 * @return void
	 */
	public function delete_bundled_ids( int $product_id ): void {
		$this->product->delete_meta_data_value( self::INCLUDE_PRODUCT_IDS_META_KEY, $product_id );
		$this->product->save_meta_data();
	}


	/**
	 * 取得銷售方案連結的課程商品
	 * 如果銷售方案沒有連結課程，則回傳 null
	 *
	 * @return \WC_Product|null
	 */
	public function get_course_product(): \WC_Product|null {
		$course_product = $this->link_course_id;
		$course_product = wc_get_product($course_product);
		if (!$course_product) {
			return null;
		}
		return $course_product;
	}
}
