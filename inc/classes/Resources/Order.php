<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources;

use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Admin\Product as AdminProduct;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\PowerCourse\Resources\Course\BindCourseData;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\Resources\Course\Service\AddStudent;

/**
 * Class Order
 * 處理訂單相關業務
 */
final class Order {
	use \J7\WpUtils\Traits\SingletonTrait;


	/** Constructor */
	public function __construct() {
		\add_action( 'woocommerce_new_order', [ $this, 'add_course_item_meta' ], 10, 2 );
		\add_action( 'woocommerce_subscription_payment_complete', [ $this, 'add_course_item_meta_by_subscription' ], 10, 1 );

		$settings = Settings::instance();
		\add_action( "woocommerce_order_status_{$settings->course_access_trigger}", [ $this, 'add_meta_to_avl_course' ], 10, 1 );

		// \add_action( 'woocommerce_subscription_pre_update_status', [ $this, 'subscription_failed' ], 10, 3 );

		// \add_action( 'woocommerce_subscription_pre_update_status', [ $this, 'subscription_success' ], 10, 3 );
	}

	/**
	 * 訂單成立時，新增課程資訊到訂單
	 *
	 * @param int       $order_id 訂單 ID
	 * @param \WC_Order $order    訂單
	 *
	 * @return void
	 */
	public function add_course_item_meta( int $order_id, \WC_Order $order ): void {
		if (class_exists('WC_Subscription')) {
			$is_subscription = \wcs_order_contains_subscription($order, [ 'parent', 'resubscribe', 'switch', 'renewal' ]);
			// 如果此筆訂單是訂閱相關訂單，就不處理，改用 woocommerce_subscription_payment_complete hook 來處理
			if ($is_subscription) {
				return;
			}
		}

		$this->_handle_add_course_item_meta_by_order( $order );
	}


	/**
	 * 訂閱的上層訂單成立時，新增課程資訊到訂單
	 *
	 * @param \WC_Subscription $subscription subscription
	 * @return void
	 */
	public function add_course_item_meta_by_subscription( \WC_Subscription $subscription ): void {
		$parent_order = $subscription->get_parent();

		if ( ! ( $parent_order instanceof \WC_Order ) ) {
			return;
		}

		$parent_order_id = $parent_order->get_id();

		$related_order_ids = $subscription->get_related_orders();

		// 確保只有一筆訂單 (parent order) 才會觸發，續訂不觸發
		if ( count( $related_order_ids ) !== 1 ) {
			return;
		}
		// 唯一一筆關聯訂單必須要 = parent order id
		if ( ( (int) reset( $related_order_ids ) ) !== ( (int) $parent_order_id )) {
			return;
		}

		$this->_handle_add_course_item_meta_by_order( $parent_order );
	}


	/**
	 * 處理新增課程資訊到訂單
	 *
	 * @param \WC_Order $order    訂單
	 *
	 * @return void
	 */
	private function _handle_add_course_item_meta_by_order( \WC_Order $order ): void {
		/** @var \WC_Order_Item_Product[] $items */
		$items = $order->get_items();

		// 檢查訂單是否有銷售方案商品，如果有將課程限制條件存入為 order item
		foreach ( $items as $item ) {

			$product_id = $item->get_product_id();
			$product    = \wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			// 如果是銷售方案商品，這訂單是購買銷售方案
			// 就把銷售方案包含的商品，加到訂單中，且售價修改為 0
			$helper = Helper::instance( $product );
			if ( $helper?->is_bundle_product ) {
				$included_product_ids = $helper?->get_product_ids() ?: []; // 綑綁的商品們

				foreach ( $included_product_ids as $included_product_id ) {
					$included_product = \wc_get_product( $included_product_id );
					if ( ! $included_product ) {
						continue;
					}

					// ex: 買了 3 份銷售方案，應該要扣除3份庫存
					$qty = $item->get_quantity() ?: 1;

					$order->add_product(
						$included_product,
						$qty, // TODO: 應該也要記錄數量
						[
							'name'     => $product->get_name() . ' - ' . $included_product->get_name(),
							'subtotal' => 0,
							'total'    => 0,
						]
					);
				}
				$order->save();
			}
		}

		// 處理完銷售方案，重新拿一次 items
		$items = $order->get_items();
		foreach ( $items as $item ) {
			$this->_handle_add_course_item_meta_by_order_item( $item );
		}
	}


	/**
	 * 根據訂單項目儲存課程限制資訊到訂單項目元數據中。
	 *
	 * 此方法檢查傳入的訂單項目是否為 WooCommerce 的產品項目
	 * 如果是，則檢查該產品是否為課程商品
	 * 對於課程商品，此方法會從產品中提取課程的限制條件（如限制類型、限制值和限制單位）並將這些資訊儲存到訂單項目的元數據中。
	 * 這樣做可以在後續處理中輕鬆訪問和使用這些課程限制資訊。
	 *
	 * @param \WC_Order_Item|\WC_Order_Item_Product $item 訂單項目，需為 WooCommerce 的產品項目實例。
	 *
	 * @return void
	 */
	private function _handle_add_course_item_meta_by_order_item( $item ): void {
		if (!( $item instanceof \WC_Order_Item_Product )) {
			return;
		}

		$product_id = $item->get_variation_id() ?: $item->get_product_id();

		$product = \wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		// 如果是課程商品
		if ( CourseUtils::is_course_product( $product_id ) ) {
			// 將課程限制條件紀錄到訂單
			$meta_keys = Limit::get_meta_keys();
			foreach ( $meta_keys as $meta_key ) {
				/** @var string $meta_value */
				$meta_value = $product->get_meta( $meta_key );
				$item->update_meta_data( "_{$meta_key}", $meta_value );
			}
			$item->update_meta_data( '_' . AdminProduct::PRODUCT_OPTION_NAME, 'yes' );
		}

		/** @var array<int, array{id: int, name: string, limit_type: string, limit_value: int|null, limit_unit: string|null}> $bind_courses_data */
		$bind_courses_data = \get_post_meta( $product_id, 'bind_courses_data', true ) ?: [];

		if ( $bind_courses_data ) {
			$item->update_meta_data( '_bind_courses_data', $bind_courses_data );
		}
		$item->save_meta_data();
	}



	/**
	 * 訂單完成時將元數據添加到訂單中的可用課程。
	 *
	 * 此函數遍歷訂單中的每個商品，檢查是否為課程商品。如果是，則將課程的限制條件（如限制類型、限制值和限制單位）
	 * 紀錄到訂單中。根據這些限制條件，計算並設定課程的到期日存入 avl_coursemeta 表中。
	 *
	 * @param int $order_id 訂單ID。
	 * @return void
	 */
	public function add_meta_to_avl_course( int $order_id ): void {
		$order = \wc_get_order($order_id);

		if (!( $order instanceof \WC_Order )) {
			return;
		}

		// 使用 AddStudent 來處理課程授權
		$add_student = new AddStudent();

		$items = $order->get_items();
		foreach ( $items as $item ) {
			/**
			 * @var \WC_Order_Item_Product $item
			 */
			$product_id = $item->get_product_id();

			$bind_courses_data = $item->get_meta( '_bind_courses_data' ) ?: [];
			$is_course         = CourseUtils::is_course_product( $product_id );

			// 如果 "不是課程商品" 或 "沒有綁定課程"，就什麼也不做
			if ( !$is_course && !$bind_courses_data ) {
				continue;
			}

			// 如果是單一課程，就處理單一課程
			if ($is_course) {
				$this->handle_single_course( $order, $item, $add_student );
			}

			// 如果有綁定課程，就處理綁定課程
			if ($bind_courses_data) {
				$this->handle_bind_courses( $order, $item, $add_student );
			}
		}

		$add_student->do_action();
	}

	/**
	 * 開通銷售方案中包含的課程
	 *
	 * @param \WC_Order              $order 訂單
	 * @param \WC_Order_Item_Product $item 訂單項目，需為 WooCommerce 的產品項目實例。
	 * @param AddStudent             $add_student 新增學員到課程
	 * @return void
	 */
	public function handle_bind_courses( $order, $item, $add_student ): void {
		$customer_id = $order->get_customer_id();
		if (!$customer_id) {
			return;
		}
		// 從訂單拿 _bind_courses_data

		/** @var array<int, array{id: int, name: string, limit_type: string, limit_value: int|null, limit_unit: string|null}> $bind_courses_data */
		$bind_courses_data          = $item->get_meta( '_bind_courses_data' ) ?: [];
		$bind_courses_data_instance = new BindCoursesData($bind_courses_data);

		foreach ($bind_courses_data_instance->get_data() as $bind_course_data) {
			/** @var BindCourseData $bind_course_data */
			if (!$bind_course_data->course_id) {
				continue;
			}

			$expire_date = $bind_course_data->calc_expire_date($order);

			$add_student->add_item( $customer_id, $bind_course_data->course_id, $expire_date, $order );
		}
	}


	/**
	 * 開通單一課程
	 *
	 * @param \WC_Order              $order 訂單
	 * @param \WC_Order_Item_Product $item 訂單項目，需為 WooCommerce 的產品項目實例。
	 * @param AddStudent             $add_student 新增學員到課程
	 * @return void
	 */
	public function handle_single_course( $order, $item, $add_student ): void {
		$customer_id = $order->get_customer_id();
		if (!$customer_id) {
			return;
		}

		$product_id  = (int) $item->get_product_id();
		$expire_date = Limit::instance($product_id)->calc_expire_date($order);
		$add_student->add_item( $customer_id, $product_id, $expire_date, $order );
	}
}
