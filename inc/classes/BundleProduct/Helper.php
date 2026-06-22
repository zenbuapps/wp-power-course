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
	const INCLUDE_PRODUCT_IDS_META_KEY  = 'pbp_product_ids'; // 此銷售方案裡面包含的商品 ids
	const LINK_COURSE_IDS_META_KEY      = 'link_course_ids'; // 此銷售方案歸屬於哪個課程 id(s)
	const PRODUCT_QUANTITIES_META_KEY   = 'pbp_product_quantities'; // 此銷售方案裡面每個商品的數量（JSON 物件）

	// 站長是否曾明確編輯過商品列表（Issue #249）。'yes' = 已明確編輯 → 尊重真實列表，向下相容補課程邏輯不再介入。
	// 與已廢棄的 exclude_main_course 正交：exclude_main_course 表「明確排除課程」，本旗標表「站長已接管列表」。
	const EDITED_PRODUCT_IDS_META_KEY   = 'bundle_edited_product_ids';

	// 自動上下線排程 meta（Issue #247）。Unix timestamp，0 = 無排程。
	const SCHEDULE_ONLINE_META_KEY          = 'bundle_schedule_online';  // 自動上線時間（到點且 draft → publish）
	const SCHEDULE_OFFLINE_META_KEY         = 'bundle_schedule_offline'; // 自動下線時間（到點且 publish → draft）
	const SCHEDULE_ONLINE_DONE_AT_META_KEY  = 'bundle_schedule_online_done_at';  // 最近一次自動上線執行時間（供後台可感知）
	const SCHEDULE_OFFLINE_DONE_AT_META_KEY = 'bundle_schedule_offline_done_at'; // 最近一次自動下線執行時間（供後台可感知）

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
	 * 取得自動上線時間（Issue #247）
	 *
	 * @return int Unix timestamp，0 = 無排程
	 */
	public function get_schedule_online(): int {
		return (int) \get_post_meta( $this->product->get_id(), self::SCHEDULE_ONLINE_META_KEY, true );
	}

	/**
	 * 取得自動下線時間（Issue #247）
	 *
	 * @return int Unix timestamp，0 = 無排程
	 */
	public function get_schedule_offline(): int {
		return (int) \get_post_meta( $this->product->get_id(), self::SCHEDULE_OFFLINE_META_KEY, true );
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
		$str_product_ids = [];
		foreach ( $product_ids as $pid ) {
			$str_product_ids[] = is_scalar( $pid ) ? (string) $pid : '';
		}
		$unique_product_ids = array_unique( $str_product_ids );

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
		// WC_Data::delete_meta_data_value 用 === 比較，meta 值存為 string，需轉型
		$this->product->delete_meta_data_value( self::INCLUDE_PRODUCT_IDS_META_KEY, (string) $product_id );
		$this->product->save_meta_data();
	}

	/**
	 * 清空此銷售方案內的所有 bundled product ids
	 *
	 * 同時清除 `pbp_product_quantities` 與已廢棄的 `exclude_main_course` meta（Issue #185），
	 * 確保儲存後舊資料殘留被移除。
	 *
	 * @return void
	 */
	public function clear_bundled_ids(): void {
		$this->product->delete_meta_data( self::INCLUDE_PRODUCT_IDS_META_KEY );
		$this->product->delete_meta_data( self::PRODUCT_QUANTITIES_META_KEY );
		// 清除已廢棄的向下相容 meta
		$this->product->delete_meta_data( 'exclude_main_course' );
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

	/**
	 * 取得方案中每個商品的數量
	 * 若 meta 不存在，fallback 所有商品數量為 1
	 *
	 * @return array<string, int> {"product_id": qty, ...}
	 */
	public function get_product_quantities(): array {
		$id  = $this->product->get_id();
		$raw = \get_post_meta( $id, self::PRODUCT_QUANTITIES_META_KEY, true );

		$quantities = [];
		if (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				foreach ($decoded as $decoded_pid => $decoded_qty) {
					$quantities[ (string) $decoded_pid ] = (int) $decoded_qty;
				}
			}
		}

		// fallback：如果 quantities 為空或缺少某些商品，預設為 1
		$product_ids = $this->get_product_ids();
		foreach ($product_ids as $product_id) {
			$str_id = (string) $product_id;
			if (!isset($quantities[ $str_id ]) || $quantities[ $str_id ] < 1) {
				$quantities[ $str_id ] = 1;
			}
		}

		return $quantities;
	}

	/**
	 * 取得方案中特定商品的數量
	 *
	 * @param int $product_id 商品 ID
	 * @return int 數量（至少 1）
	 */
	public function get_product_quantity( int $product_id ): int {
		$quantities = $this->get_product_quantities();
		return max( 1, (int) ( $quantities[ (string) $product_id ] ?? 1 ) );
	}

	/**
	 * 儲存方案中每個商品的數量
	 *
	 * @param array<string|int, int> $quantities {"product_id": qty, ...}
	 * @return void
	 */
	public function set_product_quantities( array $quantities ): void {
		// 清理：確保每個 qty 至少為 1，最大為 999
		$clean = [];
		foreach ($quantities as $product_id => $qty) {
			$qty                           = (int) $qty;
			$clean[ (string) $product_id ] = max( 1, min( 999, $qty ) );
		}

		$encoded = \wp_json_encode( $clean );
		$this->product->update_meta_data(
			self::PRODUCT_QUANTITIES_META_KEY,
			false === $encoded ? '' : $encoded
		);
		$this->product->save_meta_data();
	}

	/**
	 * 取得商品 IDs（含向下相容邏輯）
	 *
	 * 向下相容：若 exclude_main_course ≠ 'yes'、站長未曾明確編輯過商品列表，
	 * 且 link_course_id 不在列表中，自動補入 link_course_id 到列表前面。
	 *
	 * Issue #249：一旦 bundle_edited_product_ids = 'yes'（站長已透過 UI / API / MCP 明確
	 * 編輯過商品列表），即尊重真實列表，不再補入課程，避免課程移除後又被自動補回。
	 *
	 * @return array<string> product_ids
	 */
	public function get_product_ids_with_compat(): array {
		$product_ids = $this->get_product_ids();
		$product_id  = $this->product->get_id();

		$exclude_main_course = (string) \get_post_meta( $product_id, 'exclude_main_course', true );

		// 如果 exclude_main_course = 'yes'，不補入當前課程
		if ($exclude_main_course === 'yes') {
			return $product_ids;
		}

		// Issue #249：站長已明確編輯過商品列表，尊重真實列表，不補入課程
		if (\get_post_meta( $product_id, self::EDITED_PRODUCT_IDS_META_KEY, true ) === 'yes') {
			return $product_ids;
		}

		// 如果 link_course_id 有值，且不在 product_ids 中，補入
		$course_id = (string) $this->link_course_id;
		if ($this->link_course_id > 0 && !in_array( $course_id, $product_ids, true )) {
			array_unshift( $product_ids, $course_id );
		}

		return $product_ids;
	}
}
