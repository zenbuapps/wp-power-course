<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources;

use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Admin\Product as AdminProduct;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\PowerCourse\Resources\Course\BindCourseData;
use J7\PowerCourse\Resources\Course\Service\AddStudent;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;

/**
 * Class Order
 * 處理訂單相關業務
 */
final class Order {
	use \J7\WpUtils\Traits\SingletonTrait;


	/**
	 * 方案內含商品的「來源方案」標記 item meta key（值 = 母方案商品 ID）
	 *
	 * Issue #263 冪等閘門：標記刻意寫在 **item meta** 而非 order meta，
	 * 因為 WC 的 remove_order_items() 只刪 items、不刪 order meta；
	 * item meta 會隨 item 一起被刪，正好等於「回到未展開狀態」的重置語意。
	 * 若改用 order meta 布林旗標，resume 時旗標會殘留成 true，反而把該補的展開擋掉。
	 *
	 * @var string
	 */
	public const BUNDLED_FROM_META_KEY = '_pc_bundled_from';

	/**
	 * 母方案 line item 的「已展開」標記 item meta key（值固定為 'yes'）
	 *
	 * Issue #263 冪等閘門 2。與 BUNDLED_FROM_META_KEY 同樣寫在 item meta，
	 * 隨 item 被 remove_order_items() 一起清除 = 回到未展開狀態。
	 *
	 * @var string
	 */
	public const BUNDLE_EXPANDED_META_KEY = '_pc_bundle_expanded';

	/** Constructor */
	public function __construct() {
		// 後台 / REST / MCP 建單，以及區塊結帳 draft→pending 轉換（data store create() 內觸發）
		\add_action( 'woocommerce_new_order', [ $this, 'add_course_item_meta' ], 10, 2 );

		// Issue #263：傳統結帳「續用既有 pending/failed 訂單」的 resume 分支
		// （WC_Checkout::create_order，class-wc-checkout.php:401-413）會先 remove_order_items()
		// 再從購物車重建 items，且走 data store update() 不再觸發 woocommerce_new_order，
		// 導致方案內含商品與 _bind_courses_data 全部遺失。
		// woocommerce_checkout_order_processed（class-wc-checkout.php:1352）在 create_order() 之後觸發，
		// 新單與 resume 單都會走到，且此時 items 已落地、具真實 order_item_id
		// （save_meta_data() 才真的會寫入），故補掛於此。
		\add_action( 'woocommerce_checkout_order_processed', [ $this, 'add_course_item_meta_on_checkout' ], 10, 3 );

		// 區塊結帳（Store API）：woocommerce_checkout_order_processed 在此路徑不會觸發，
		// 另掛 Store API 對應 hook（src/StoreApi/Routes/V1/Checkout.php:521），
		// 涵蓋因 cart hash 變動而重建 items 的情境。
		\add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'add_course_item_meta_on_store_api_checkout' ], 10, 1 );

		\add_action( 'woocommerce_subscription_payment_complete', [ $this, 'add_course_item_meta_by_subscription' ], 10, 1 );

		// 課程 / 權限包開通：掛在「開通狀態集合」的每個狀態（course_access_trigger + completed），
		// 修正 pending→completed 直跳（跳過 trigger）導致完全不授予（Grant::grant_statuses 為統一來源）
		foreach ( Grant::grant_statuses() as $grant_status ) {
			\add_action( "woocommerce_order_status_{$grant_status}", [ $this, 'add_meta_to_avl_course' ], 10, 1 );
		}

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
	 * 傳統結帳建單完成後補跑（Issue #263）
	 *
	 * 新單情境下會與 woocommerce_new_order 在同一請求內先後觸發兩次，
	 * 由 _handle_add_course_item_meta_by_order() 的冪等閘門吸收。
	 *
	 * @param int                  $order_id    訂單 ID
	 * @param array<string, mixed> $posted_data 結帳表單資料（未使用）
	 * @param mixed                $order       訂單（hook 參數不可信，執行期再驗型）
	 *
	 * @return void
	 */
	public function add_course_item_meta_on_checkout( $order_id, $posted_data, $order ): void {
		unset( $posted_data );
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}
		$this->add_course_item_meta( (int) $order_id, $order );
	}


	/**
	 * 區塊結帳（Store API）建單完成後補跑（Issue #263）
	 *
	 * @param mixed $order 訂單（hook 參數不可信，執行期再驗型）
	 *
	 * @return void
	 */
	public function add_course_item_meta_on_store_api_checkout( $order ): void {
		if ( ! ( $order instanceof \WC_Order ) ) {
			return;
		}
		$this->add_course_item_meta( (int) $order->get_id(), $order );
	}


	/**
	 * 重新補齊訂單的「方案內含商品」與課程 item meta（Issue #263）
	 *
	 * 對外公開的修復入口，供 MCP tool / 一次性回填腳本使用。
	 * 具冪等性：已展開過的方案不會重複塞入，已存在的 item meta 只會被覆寫成相同值。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return void
	 */
	public function repair_order_items( \WC_Order $order ): void {
		// legacy_safe = true：修復前建立的訂單沒有 marker，額外以「內含商品是否已在訂單上」
		// 判斷，避免對「已正常展開的舊訂單」重複塞入
		$this->_handle_add_course_item_meta_by_order( $order, true );
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

		// Issue #252 §D：訂閱首期付款完成時，授予訂閱商品掛載的「課程權限包」持有關係
		// （跟隨訂閱 → expire_date = subscription_{id}）。續訂不觸發（沿用上方 parent order 閘門）。
		Grant::on_subscription_payment_complete( $subscription );
	}


	/**
	 * 處理新增課程資訊到訂單
	 *
	 * @param \WC_Order $order       訂單
	 * @param bool      $legacy_safe 是否啟用「相容修復前訂單」的額外閘門（見下方閘門 3）。
	 *                               hook 路徑傳 false（marker 為權威，行為與修復前完全一致）；
	 *                               repair_order_items() 傳 true。
	 *
	 * @return void
	 */
	private function _handle_add_course_item_meta_by_order( \WC_Order $order, bool $legacy_safe = false ): void {
		/** @var \WC_Order_Item_Product[] $items */
		$items = $order->get_items();

		// 訂單上已存在的商品 ID（供閘門 3 使用）
		$existing_product_ids = [];
		foreach ( $items as $item ) {
			$existing_product_ids[] = (int) $item->get_product_id();
		}

		// 檢查訂單是否有銷售方案商品，如果有將課程限制條件存入為 order item
		foreach ( $items as $item ) {

			// Issue #263 閘門 1：本身就是被展開出來的內含商品 → 跳過。
			// 避免「方案內含另一個方案」在重跑時被遞迴展開多層。
			if ( $item->get_meta( self::BUNDLED_FROM_META_KEY ) ) {
				continue;
			}

			// Issue #263 閘門 2：此 line item 已展開過 → 跳過。
			// 新單情境下 woocommerce_new_order 與 woocommerce_checkout_order_processed
			// 會在同一請求內先後觸發，沒有這道閘門會讓內含商品全部翻倍。
			//
			// 標記刻意寫在「母方案 line item 自己身上」而非 order meta，理由有二：
			// 1. WC 的 remove_order_items() 只刪 items、不刪 order meta。用 order meta 布林旗標
			// 會在 resume 時殘留成 true，反而把該補的展開擋掉。
			// 2. 逐 line item 記錄，同一張訂單出現兩列同一個方案商品時各自獨立判斷。
			if ( 'yes' === $item->get_meta( self::BUNDLE_EXPANDED_META_KEY ) ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			$product = \wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			// 如果是銷售方案商品，這訂單是購買銷售方案
			// 就把銷售方案包含的商品，加到訂單中，且售價修改為 0
			$helper = Helper::instance( $product );
			if ( $helper?->is_bundle_product ) {
				$included_product_ids = $helper?->get_product_ids_with_compat() ?: []; // 綑綁的商品們（含向下相容）
				$quantities           = $helper?->get_product_quantities() ?: []; // 各商品設定數量
				$order_qty            = $item->get_quantity() ?: 1; // 購買份數

				foreach ( $included_product_ids as $included_product_id ) {
					// Issue #263 閘門 3（只在 repair 路徑啟用）：
					// 修復前建立的「健康」訂單其內含商品沒有 marker，光靠閘門 1/2 認不出來，
					// 回填時會被重複塞一份。改以「此內含商品是否已是訂單項目」判斷。
					// hook 路徑刻意不啟用，維持與修復前完全相同的行為
					// （顧客同時單買 P 又買含 P 的方案時，仍會照舊各得一列）。
					if ( $legacy_safe && \in_array( (int) $included_product_id, $existing_product_ids, true ) ) {
						continue;
					}

					$included_product = \wc_get_product( $included_product_id );
					if ( ! $included_product ) {
						continue;
					}

					// 方案設定數量（fallback 為 1）
					$bundle_qty = max( 1, (int) ( $quantities[ (string) $included_product_id ] ?? 1 ) );
					// 最終數量 = 方案設定數量 × 購買份數
					$final_qty = $bundle_qty * $order_qty;

					$bundled_item_id = $order->add_product(
						$included_product,
						$final_qty,
						[
							'name'     => $product->get_name() . ' - ' . $included_product->get_name(),
							'subtotal' => 0,
							'total'    => 0,
						]
					);

					// 寫入來源標記作為下次重跑的冪等依據（Issue #263）。
					// add_product() 內部已 set_order_id() + save()（abstract-wc-order.php:1625-1631），
					// 故此時 item 必有真實 order_item_id，save_meta_data() 會真的落地。
					$bundled_item = $order->get_item( (int) $bundled_item_id, false );
					if ( $bundled_item instanceof \WC_Order_Item_Product ) {
						$bundled_item->update_meta_data( self::BUNDLED_FROM_META_KEY, (string) $product_id );
						$bundled_item->save_meta_data();
					}

					$existing_product_ids[] = (int) $included_product_id;
				}

				// 標記此 line item 已展開（閘門 2 的依據）。
				// item 尚無 id 時（woocommerce_new_order 早於 save_items）此呼叫為 no-op，
				// 但緊接著的 $order->save() → save_items() 會一併落地。
				$item->update_meta_data( self::BUNDLE_EXPANDED_META_KEY, 'yes' );
				$item->save_meta_data();

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

		// 落地契約（Issue #263）：
		// - item 已有 order_item_id（checkout_order_processed / store_api / 修復重跑）→ 此處真的寫入 DB
		// - item 尚無 id（woocommerce_new_order 早於 WC_Abstract_Order::save_items()，
		// abstract-wc-order.php:221-227）→ add_metadata(object_id=0) 直接 return false，
		// 此呼叫是 no-op，meta 由 WC 稍後的 save_items() → item data store create()
		// （abstract-wc-order-item-type-data-store.php:83）第二次 save_meta_data() 落地。
		// 兩條路徑都不需要在此呼叫 $item->save()（此時 order_id 可能尚未設定）。
		$item->save_meta_data();
	}



	/**
	 * 取得 order item 的綁定課程資料（Issue #263 單一真相來源）
	 *
	 * 讀取順序：
	 * 1. order item meta `_bind_courses_data`（下單當時快照，優先）
	 * 2. fallback：商品現況 post meta `bind_courses_data`（變體優先、fallback 主商品，
	 *    與寫入端 _handle_add_course_item_meta_by_order_item() 及
	 *    AccessPass\Grant::get_item_pass_id() 的解析慣例一致）
	 *
	 * fallback 命中時會「自癒」回寫 item meta，讓同一張訂單之後每次重跑都拿到相同結果
	 * （否則站長中途換課會讓每次重跑授到不同課程），並讓後台 / MCP 讀到一致內容。
	 *
	 * @param \WC_Order_Item|\WC_Order_Item_Product $item      訂單項目
	 * @param bool                                  $self_heal fallback 命中時是否回寫 item meta
	 *
	 * ⚠️ 回傳的是 **DB 原始列**，每一列的形狀無保證（站長可能存過舊格式 / 半殘資料）。
	 * 逐列驗證由 BindCoursesData::__construct 負責，故此處刻意不宣告精確 shape。
	 *
	 * @return array<int, mixed>
	 */
	public static function get_item_bind_courses_data( $item, bool $self_heal = true ): array {
		if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
			return [];
		}

		$snapshot = $item->get_meta( '_bind_courses_data' );
		if ( \is_array( $snapshot ) && $snapshot ) {
			// array_values：DB 可能存成帶字串 key 的關聯陣列，統一正規化為 list，
			// 讓下游 BindCoursesData 的 foreach 語義一致
			return \array_values( $snapshot );
		}

		// fallback：讀商品現況（變體優先）
		$product_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
		if ( $product_id <= 0 ) {
			return [];
		}

		$current = \get_post_meta( $product_id, 'bind_courses_data', true );

		// 變體無綁定時，fallback 查主商品
		if ( ! \is_array( $current ) || ! $current ) {
			$parent_id = (int) $item->get_product_id();
			$current   = ( $parent_id > 0 && $parent_id !== $product_id )
			? \get_post_meta( $parent_id, 'bind_courses_data', true )
			: [];
		}

		if ( ! \is_array( $current ) || ! $current ) {
			return [];
		}

		// 稽核：讓「這筆是 fallback 授權」可追查
		\J7\WpUtils\Classes\WC::log(
			[
				'order_item_id' => (int) $item->get_id(),
				'order_id'      => (int) $item->get_order_id(),
				'product_id'    => $product_id,
				'course_count'  => \count( $current ),
			],
			'Order::get_item_bind_courses_data — _bind_courses_data 缺失，fallback 讀商品現況（Issue #263）'
		);

		// self-heal：item 已落地才能寫入
		if ( $self_heal && (int) $item->get_id() > 0 ) {
			$item->update_meta_data( '_bind_courses_data', $current );
			$item->save_meta_data();
		}

		return \array_values( $current );
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

			// Issue #263：item meta 缺失時 fallback 讀商品現況（resume 訂單 / 歷史受害訂單）
			$bind_courses_data = self::get_item_bind_courses_data( $item );
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

		// Issue #252 §D：訂單達 trigger 狀態時，授予商品掛載的「課程權限包」持有關係。
		// 與 handle_single_course / handle_bind_courses 並列，不影響既有逐課綁定流程（ASM-D1）。
		Grant::on_order_completed( $order_id );
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
		// 從訂單拿 _bind_courses_data（Issue #263：缺失時 fallback 讀商品現況）
		// ⚠️ 這裡必須與 add_meta_to_avl_course() 用同一支 helper，
		// 否則 guard 過了卻在這裡拿到空陣列 → 一圈都不跑、也不報錯，比不修更難查。

		$bind_courses_data          = self::get_item_bind_courses_data( $item );
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
