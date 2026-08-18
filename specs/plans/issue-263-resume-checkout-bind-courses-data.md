---
issue: 263
title: 重試付款後訂單項目遺失 _bind_courses_data，導致課程權限不自動開通、通知信也不寄
status: planned
verified_against:
  - Power Course @ master (v1.8.7)
  - WooCommerce 10.1.4（本機）/ 10.5.3（回報站台）
created: 2026-08-18
---


---

## 1. 判定

**成立，但 issue 低估了損害範圍、且提出的「方案 B」（掛 `woocommerce_resume_order`）100% 無效。**

根因一句話：`Resources\Order` 的**寫入端只掛在 `woocommerce_new_order`**，而 WooCommerce 傳統結帳的 resume 分支會「先砍光所有 order items（含 itemmeta）、再從購物車重建」，且該路徑走 data store `update()` 不再觸發 `woocommerce_new_order` → 方案內含商品與所有 power-course item meta 永久遺失。

### 最關鍵三條證據

| # | 證據 | 說明 |
|---|---|---|
| 1 | `woocommerce/includes/class-wc-checkout.php:401-413`<br>`if ( $order && $order->has_cart_hash( $cart_hash ) && $order->has_status( array( PENDING, FAILED ) ) ) { do_action( 'woocommerce_resume_order', $order_id ); /* Remove all items */ $order->remove_order_items(); }` | resume hook 在 **remove 前 3 行**，之後所有 item 被無條件刪除 |
| 2 | `woocommerce/includes/data-stores/abstract-wc-order-data-store-cpt.php:651-652`<br>`DELETE itemmeta FROM ...order_itemmeta ... INNER JOIN ...order_items ... WHERE items.order_id = %d` / `DELETE FROM ...order_items WHERE order_id = %d` | itemmeta + items 一起硬刪；HPOS `OrdersTableDataStore` 未覆寫 `delete_items()`，兩種 store 行為相同 |
| 3 | `woocommerce/includes/data-stores/class-wc-order-data-store-cpt.php:222-233` + `src/Internal/DataStores/Orders/OrdersTableDataStore.php:2830-2848`<br>`update()` 只在「前狀態是 draft 系列且新狀態非 draft」時 fire `woocommerce_new_order`，否則 fire `woocommerce_update_order` | resume 時 pending→pending（`class-wc-checkout.php` 全檔無 `set_status`/`update_status`）→ `woocommerce_new_order` **不會**二次觸發 |

### 對 issue 敘述的三處更正

**更正 1 — 方案 B（掛 `woocommerce_resume_order` 補寫）完全無效，不是「部分有效」。**
`woocommerce_resume_order`（`class-wc-checkout.php:410`）觸發時，訂單上掛的還是「上一次嘗試」留下的舊 items（那批本來就有正確 meta）。在它們身上補寫任何 item meta 或再 `add_product()`，都會在下一行 `remove_order_items()` 被 DELETE。之後 `set_data_from_cart()` → `create_order_line_items()` 只從 `WC()->cart` 重建，新 items 是全新 row。**該 hook 唯一能安全做的事是設旗標，實際補寫必須延後到 items 重建之後。**

**更正 2 — 「單買課程商品也不會開通」是錯的。**
`add_meta_to_avl_course()` 的 `is_course` 判定讀的是**商品 post meta** `_is_course`（`inc/classes/Utils/Course.php:88-99`），到期日由 `Limit::instance($product_id)` 讀**商品** post meta 重算（`inc/classes/Resources/Course/Limit.php:161-172`），兩者都不依賴 item meta。所以**單買課程商品的 resume 訂單仍會正常開通**，只是到期日用「當下商品設定」而非下單快照。真正 100% 失效的是「綁定課程（`_bind_courses_data`）」與「銷售方案內含商品」兩條。

**更正 3 — 「銷售方案只是 meta 缺失」低估了。**
銷售方案是**訂單內容缺料**：`inc/classes/Resources/Order.php:135-143` 用 `$order->add_product()` 憑空塞進去的「方案內含商品 order item」在 resume 時被刪掉，而 `create_order_line_items()` 只依購物車重建（購物車裡只有 bundle 本身）→ **永久消失，無法靠補寫任何 item meta 救回**。

**更正 4 — 訂閱路徑不受影響。**
`woocommerce_subscription_payment_complete`（`inc/classes/Resources/Order.php:27`）在付款完成的獨立請求觸發，parent order items 早已入庫，`$item->save_meta_data()` 真的會寫入。修復不需動訂閱路徑。

---

## 2. 完整影響清單

| 層級 | 影響 | 嚴重度 | 同一根因？ |
|---|---|---|---|
| **課程開通 — 綁定課程** | `Order.php:235` `if ( !$is_course && !$bind_courses_data ) continue;` → `_bind_courses_data` 為空，非課程商品的 item 直接跳過，**綁定課程 100% 不開通** | 🔴 P0 | 是 |
| **課程開通 — 方案內含課程** | 子商品 order item 不存在 → `handle_single_course` 根本不會被呼叫；bundle 本身 `is_course_product()` 為 false 且 `_bind_courses_data` 空 → **純 bundle 訂單零開通**（雙路徑同時被斬斷） | 🔴 P0 | 是 |
| **課程開通 — 單買課程商品** | 仍正常開通；副作用是到期日用商品「現況」而非下單快照 | 🟢 無實害 | — |
| **AccessPass（權限包）** | 掛在**方案商品本身**的 pass 不受影響（`Grant::get_item_pass_id` 讀 product meta，`Grant.php:194`）；但 `grant_passes_from_order` 是 `foreach ( $order->get_items() )`（`Grant.php:160-163`），**掛在方案內含商品上的 pass 一併不授予**。前提 5 需限縮 | 🟠 P1 | 是 |
| **通知信（PowerEmail）** | 開通信 / 課程通知由 `LifeCycle::ADD_STUDENT_TO_COURSE_ACTION` 鏈觸發；課程沒開通 → 信不寄。**衍生，非獨立缺陷** | 🟠 P1 | 是（衍生） |
| **後台 / 前台訂單明細** | 內含商品那幾列（名稱「方案名 - 商品名」、金額 0）在感謝頁 / 我的帳號 / 後台訂單 / 訂單信全部消失。訂單**金額不受影響**（subtotal/total 皆 0）——別因為金額對就判定沒事 | 🟡 P2 | 是 |
| **MCP `order_get`** | `Query.php:160-161` `continue` → `courses` 回空陣列，AI Agent 看到「這張訂單沒有任何課程」；`Query.php:203` `items_count` 少算 | 🟠 P1 | 是 |
| **MCP `order_grant_courses`（人工補救工具）** | `OrderGrantCoursesTool.php:134` 用同一組判準 → `granted_count = 0` 且回訊息「訂單內沒有課程商品可授權。」（**說謊**），其呼叫的 `add_meta_to_avl_course` 同樣什麼都不授予 → **連補救路徑都被堵死** | 🔴 P0 | 是 |
| **佈景主題公開 API** | `Utils\Course::get_course_order_item_ids_by_user()` / `has_bought()`（`Utils/Course.php:466-491`、`769-800`）靠 `_is_course` **item meta** 做 SQL，resume 後查不到列。外掛內部無呼叫端，只影響有用到的佈景 | 🟡 P2 | 是 |
| **Admin React SPA** | **不受影響** — js 端只讀商品層 `bind_courses_data`（`Api/Product.php:685`），無任何前端讀 order item meta | 🟢 無 | — |

---

## 3. 修復方案（已決定，非選擇題）

**採用組合：主修 A（補掛兩個 checkout hook + 冪等閘門）＋ 補強修 B（fallback helper 統一三處讀取）＋ 補強修 C（BindCoursesData 髒資料防守）＋ 補強修 D（MCP tool 先修復再統計）。**

理由：
- 光靠 fallback（issue 的方案 A）**救不回不存在的方案內含商品 item**，只修一半。
- 光靠補掛 hook，救不了**已中招的歷史訂單**，也擋不住未知的第三條路徑。
- 兩者相加才同時覆蓋「未來不再發生」與「現在能救」。

---

### 主修 A — `inc/classes/Resources/Order.php`

#### A-1. 新增 marker 常數與 hook 註冊

把 `__construct()` 上半段改成：

```php
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

	/** Constructor */
	public function __construct() {
		// 後台 / REST / MCP 建單，以及區塊結帳 draft→pending 轉換（data store create() 內觸發）
		\add_action( 'woocommerce_new_order', [ $this, 'add_course_item_meta' ], 10, 2 );

		// Issue #263：傳統結帳「續用既有 pending/failed 訂單」的 resume 分支
		// （WC_Checkout::create_order，class-wc-checkout.php:401-413）會先 remove_order_items()
		// 再從購物車重建 items，且走 data store update() 不再觸發 woocommerce_new_order，
		// 導致方案內含商品與 _bind_courses_data 全部遺失。
		// woocommerce_checkout_order_processed（class-wc-checkout.php:1352）在 create_order() 之後觸發，
		// 新單與 resume 單都會走到，且傳入的 $order 是重新 wc_get_order() 的物件
		// （items 已落地、具真實 order_item_id，save_meta_data() 才真的會寫入），故補掛於此。
		\add_action( 'woocommerce_checkout_order_processed', [ $this, 'add_course_item_meta_on_checkout' ], 10, 3 );

		// 區塊結帳（Store API）：woocommerce_checkout_order_processed 在此路徑不會觸發，
		// 另掛 Store API 對應 hook（src/StoreApi/Routes/V1/Checkout.php:549），
		// 涵蓋 OrderController::update_line_items_from_cart() 因 cart hash 變動重建 items 的情境。
		\add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'add_course_item_meta_on_store_api_checkout' ], 10, 1 );

		\add_action( 'woocommerce_subscription_payment_complete', [ $this, 'add_course_item_meta_by_subscription' ], 10, 1 );

		// ...（以下 grant_statuses 迴圈維持原樣）
```

> ⚠️ **必須保留 `woocommerce_new_order`**：後台 / REST / MCP 建單與區塊結帳的 draft→pending 轉換只有這個 hook 會觸發。三個 hook 並存 + 冪等閘門是唯一可行組合。

#### A-2. 兩個新 callback

插在 `add_course_item_meta()` 之後：

```php
	/**
	 * 傳統結帳建單完成後補跑（Issue #263）
	 *
	 * 新單情境下會與 woocommerce_new_order 在同一請求內先後觸發兩次，
	 * 由 _handle_add_course_item_meta_by_order() 的冪等閘門吸收。
	 *
	 * @param int                  $order_id    訂單 ID
	 * @param array<string, mixed> $posted_data 結帳表單資料（未使用）
	 * @param \WC_Order            $order       訂單
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
	 * @param \WC_Order $order 訂單
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
	 * 對外公開的修復入口，供 MCP tool / WP-CLI 回填歷史受害訂單使用。
	 * 具冪等性：已展開過的方案不會重複塞入，已存在的 item meta 只會被覆寫成相同值。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return void
	 */
	public function repair_order_items( \WC_Order $order ): void {
		$this->_handle_add_course_item_meta_by_order( $order );
	}
```

#### A-3. 冪等化 `_handle_add_course_item_meta_by_order()`

整段替換（`Order.php:102-154`）：

```php
	private function _handle_add_course_item_meta_by_order( \WC_Order $order ): void {
		/** @var \WC_Order_Item_Product[] $items */
		$items = $order->get_items();

		// Issue #263 冪等閘門：先收集訂單上「已被展開過」的母方案商品 ID。
		// 新單情境下 woocommerce_new_order 與 woocommerce_checkout_order_processed
		// 會在同一請求內先後觸發，沒有這道閘門會讓內含商品全部翻倍。
		$expanded_bundle_ids = [];
		foreach ( $items as $item ) {
			$bundled_from = (int) $item->get_meta( self::BUNDLED_FROM_META_KEY );
			if ( $bundled_from > 0 ) {
				$expanded_bundle_ids[] = $bundled_from;
			}
		}

		// 檢查訂單是否有銷售方案商品，如果有將課程限制條件存入為 order item
		foreach ( $items as $item ) {

			// 本身就是被展開出來的內含商品 → 跳過。
			// 避免「方案內含另一個方案」在重跑時被遞迴展開多層。
			if ( (int) $item->get_meta( self::BUNDLED_FROM_META_KEY ) > 0 ) {
				continue;
			}

			$product_id = (int) $item->get_product_id();

			// 此方案已展開過，不再重複塞入
			if ( \in_array( $product_id, $expanded_bundle_ids, true ) ) {
				continue;
			}

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

					// 寫入來源標記作為下次重跑的冪等依據。
					// add_product() 內部已 set_order_id() + save()（abstract-wc-order.php:1625-1631），
					// 故此時 item 必有真實 order_item_id，save_meta_data() 會真的落地。
					$bundled_item = $order->get_item( (int) $bundled_item_id, false );
					if ( $bundled_item instanceof \WC_Order_Item_Product ) {
						$bundled_item->update_meta_data( self::BUNDLED_FROM_META_KEY, (string) $product_id );
						$bundled_item->save_meta_data();
					}
				}

				$expanded_bundle_ids[] = $product_id;
				$order->save();
			}
		}

		// 處理完銷售方案，重新拿一次 items
		$items = $order->get_items();
		foreach ( $items as $item ) {
			$this->_handle_add_course_item_meta_by_order_item( $item );
		}
	}
```

#### A-4. 明確化 `_handle_add_course_item_meta_by_order_item()` 的落地契約

把 `Order.php:200` 的 `$item->save_meta_data();` 換成：

```php
		// 落地契約（Issue #263）：
		// - item 已有 order_item_id（checkout_order_processed / store_api / 修復重跑）→ 此處真的寫入 DB
		// - item 尚無 id（woocommerce_new_order 早於 WC_Abstract_Order::save_items()，
		//   abstract-wc-order.php:221-227）→ add_metadata(object_id=0) 直接 return false，
		//   此呼叫是 no-op，meta 由 WC 稍後的 save_items() → item data store create()
		//   （abstract-wc-order-item-type-data-store.php:83）第二次 save_meta_data() 落地。
		// 兩條路徑都不需要在此呼叫 $item->save()（此時 order_id 可能尚未設定）。
		$item->save_meta_data();
```

---

### 補強修 B — 統一的 `bind_courses_data` 讀取 helper（含 fallback + self-heal）

新增到 `Order.php`（放在 `add_meta_to_avl_course()` 之前）：

```php
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
	 * 三處呼叫端共用此方法：本類別的 add_meta_to_avl_course() 與 handle_bind_courses()、
	 * Resources\Order\Service\Query::get_with_courses()、
	 * Api\Mcp\Tools\Order\OrderGrantCoursesTool::execute()。
	 *
	 * @param \WC_Order_Item|\WC_Order_Item_Product $item      訂單項目
	 * @param bool                                  $self_heal fallback 命中時是否回寫 item meta
	 *
	 * @return array<int, array{id: int, name: string, limit_type: string, limit_value: int|null, limit_unit: string|null}>
	 */
	public static function get_item_bind_courses_data( $item, bool $self_heal = true ): array {
		if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
			return [];
		}

		$snapshot = $item->get_meta( '_bind_courses_data' );
		if ( \is_array( $snapshot ) && $snapshot ) {
			return $snapshot;
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

		return $current;
	}
```

**三處呼叫端一起改（缺一則無效）：**

1. `Order.php:231` →
   ```php
   		$bind_courses_data = self::get_item_bind_courses_data( $item );
   ```
2. `Order.php:272-273` →
   ```php
   		/** @var array<int, array{id: int, name: string, limit_type: string, limit_value: int|null, limit_unit: string|null}> $bind_courses_data */
   		$bind_courses_data          = self::get_item_bind_courses_data( $item );
   		$bind_courses_data_instance = new BindCoursesData( $bind_courses_data );
   ```
   > ⚠️ 只改 231 是**靜默無效**：guard 過了、進入 `handle_bind_courses`，但 273 仍拿到 `[]` → 一圈都不跑、也不報錯，比不修更難查。
3. `inc/classes/Resources/Order/Service/Query.php:157` →
   ```php
   		$bind_courses_data = \J7\PowerCourse\Resources\Order::get_item_bind_courses_data( $item );
   ```
   （檔頭加 `use J7\PowerCourse\Resources\Order as OrderResource;` 後寫成 `OrderResource::get_item_bind_courses_data( $item )`）
4. `inc/classes/Api/Mcp/Tools/Order/OrderGrantCoursesTool.php:134` →
   ```php
   			$bind_courses_data = OrderResource::get_item_bind_courses_data( $item );
   ```

---

### 補強修 C — `BindCoursesData` 髒資料防守（**必要，不是 nice-to-have**）

`inc/classes/Resources/Course/BindCoursesData.php:41-43` 目前直接取 `['limit_type']` / `['limit_unit']`，缺 key 在 `strict_types=1` 下丟 **TypeError**；`BindCourseData.php:43` 在 `course_id=0` 時 `throw new \Exception`。而 WooCommerce 的 status transition 只 `catch ( Exception $e )`（`woocommerce/includes/class-wc-order.php:431`、`:488`），**不接 `\TypeError`/`\Error`**：

- Exception → 被吞掉、整段 transition 中止（同訂單後續 item、`$add_student->do_action()`、`Grant::on_order_completed()` 全跳過），只留一則訂單備註
- TypeError → 直接 fatal 500

fallback 會把「商品現況」這份更可能含髒列的資料引入授權路徑，必須先加防守：

```php
		foreach ($bind_courses_data as $bind_course_data) {
			// Issue #263：fallback 會引入商品現況資料，可能含髒列。
			// 逐列防守 + try/catch，讓單筆壞資料只被跳過，
			// 不會讓 BindCourseData 的 Exception / TypeError 中斷整張訂單的 status transition
			// （WC_Order::status_transition 只 catch \Exception，不接 \TypeError）。
			if ( !\is_array( $bind_course_data ) ) {
				continue;
			}

			$course_id = (int) ( $bind_course_data['id'] ?? 0 );
			if ( !$course_id ) {
				continue;
			}

			try {
				$this->bind_courses_data[] = new BindCourseData(
					$course_id,
					(string) ( $bind_course_data['limit_type'] ?? 'unlimited' ),
					isset( $bind_course_data['limit_value'] ) ? (int) $bind_course_data['limit_value'] : null,
					isset( $bind_course_data['limit_unit'] ) ? (string) $bind_course_data['limit_unit'] : null
				);
			} catch ( \Throwable $e ) {
				\J7\WpUtils\Classes\WC::log(
					[
						'course_id' => $course_id,
						'error'     => $e->getMessage(),
					],
					'BindCoursesData::__construct 略過無效的綁定課程資料'
				);
			}
		}
```

> 行為不變證明：`Limit::set_limit_value()`（`Limit.php:205-212`）對 `0` 與 `null` 一律設成 `null`，所以把原本的 `(int) $bind_course_data['limit_value']` 改成「未設定時傳 null」不會改變任何既有結果。
> 另外 `Order.php:278` 的 `if (!$bind_course_data->course_id) { continue; }` 在此修法後正式成為死碼（原本就是），可保留當防禦。

同時把 `Order.php:274` 的 `new BindCoursesData($bind_courses_data)` 保持不變即可 —— helper 已保證回傳 `array`。

---

### 補強修 D — MCP `order_grant_courses` 先修復再統計

`OrderGrantCoursesTool.php` 的 `execute()`，在 `$granted_count` 統計迴圈**之前**插入：

```php
		// Issue #263：resume 訂單缺 item meta 與方案內含商品，先跑一次修復再統計 / 授權，
		// 否則本工具會回報 granted_count = 0 與「訂單內沒有課程商品可授權。」（說謊），
		// 且其呼叫的 add_meta_to_avl_course() 同樣什麼都不會授予。
		// repair_order_items() 具冪等性，對正常訂單重跑無副作用。
		try {
			OrderResource::instance()->repair_order_items( $order );
			$order = \wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return new \WP_Error(
					'mcp_order_not_found',
					__( '找不到指定的訂單。', 'power-course' ),
					[ 'status' => 404 ]
				);
			}
		} catch ( \Throwable $e ) {
			$logger->log( $this->get_name(), $user_id, $args, $e->getMessage(), false );
			return new \WP_Error( 'mcp_order_repair_failed', $e->getMessage(), [ 'status' => 500 ] );
		}
```

（`$logger` / `$user_id` 的宣告需上移到此段之前。）

同時更正 `get_description()` 的 idempotent 宣告 —— 它只在 `avl_course_ids` 層面成立（`LifeCycle.php:138` 有 `in_array` 去重），`expire_date` 是無條件覆寫（`LifeCycle.php:142`），**不是**冪等：

```php
			'針對指定訂單，手動重跑課程授權流程。已授權過的學員不會重複新增 avl_course_ids；但到期日（expire_date）會依商品當下設定重新計算並覆寫。',
```

---

### 冪等性保證總表

| 風險 | 保證機制 | 證據 |
|---|---|---|
| **方案內含商品重複塞入** | item meta marker `_pc_bundled_from`（值 = 母方案 product_id）。展開前掃描收集已展開的 bundle id；命中就整段 skip | `add_product()` 內部 `set_order_id()` + `save()`（`abstract-wc-order.php:1625-1631`）保證 marker 能立即落地 |
| **旗標在 resume 時殘留** | 刻意用 item meta 而非 order meta。`remove_order_items()` 刪 items 但**不刪 order meta**，用 order meta 布林旗標會在 resume 時殘留成 true，把該補的展開擋掉 | `abstract-wc-order-data-store-cpt.php:651-652` 只 DELETE items/itemmeta |
| **巢狀方案遞迴展開** | 迴圈開頭 `if ( (int) $item->get_meta( BUNDLED_FROM_META_KEY ) > 0 ) continue;` — 被展開出來的 item 永不再被當 bundle 掃描 | — |
| **item meta 重複寫入** | `update_meta_data()` 是覆寫語意，同值重寫無害 | — |
| **課程重複開通** | 本次**不新增**任何開通觸發點（開通仍只由 `woocommerce_order_status_{status}` 驅動），呼叫次數與現況相同；`AddStudent::add_item()` 以 (customer_id, course_id) 去重（`AddStudent.php:36-51`），`LifeCycle.php:138` 對 `avl_course_ids` 再去重 | — |
| **expire_date 被反覆覆寫** | 既有行為（`LifeCycle.php:142` 無條件覆寫 × `Grant::grant_statuses()` 兩個狀態各掛一次）。本次**不擴大**：fallback 的 self-heal 回寫確保同一張訂單第二次之後讀到的是固定快照 | `Grant.php:46-49`、`Order.php:31-33` |

### 「快照 vs 現況」語意落差怎麼處理

三層處理，不加設定旗標（多一個 config 面就多一種 support 情境）：

1. **主修讓落差不再產生** —— resume 單在 `woocommerce_checkout_order_processed` 當下就寫入正確快照，時間點與新單只差毫秒。
2. **fallback 只當第二道防線** —— 只在 item meta 為空時觸發（歷史受害訂單 / 未知第三條路徑），並且 `WC::log` 留稽核紀錄。
3. **fallback 命中即凍結** —— self-heal 立刻回寫 item meta，**同一張訂單只會讀一次現況**。之後任何重跑（processing → completed 的第二次授權、MCP 手動重跑）都讀到同一份，消除「每次結果不同」的不確定性。

> 補充事實：`_limit_type` / `_limit_value` / `_limit_unit` 這三個 item meta 是 **write-only 死資料**（`Order.php:186-190` 寫入，全 repo 無讀取端），單課到期日一律由 `Limit::instance($product_id)` 從商品現況重算。所以「快照語意」在單課路徑上**本來就不存在**——本次不假裝它存在，也不動它（見第 4 節）。

---

## 4. 不做什麼

| 項目 | 理由 | 處置 |
|---|---|---|
| **附帶回報①：PowerEmail `trigger_at` 空值** | 與 #263 無因果關係；且 issue 未指出真正成因 —— `Email.php:90-96` 讀 `condition` meta 早退在讀 `trigger_at` **之前**，新建信件被 GET 回來就是 `trigger_at: ""`，refine `initialValues` + rc-field-form（只在 `undefined` 才套 Item `initialValue`）→ Select 空白 → 使用者一存檔就把 `'course_granted'` 洗成 `''`。修 UI 必填前必須先對調那兩行 | **另開 issue（P1）**，含 `LifeCycle.php:534-543` 撤銷排程同樣漏掉、`Condition.php:92` 的 `??` 只擋 null 不擋 `''` 導致 required_ids 撈成全站章節 |
| **附帶回報②：`course_schedule = 0` 開課事件不觸發** | **issue 定性錯誤。** `pm2.meta_value > 0`（`LifeCycle.php:376-381`）是**刻意守門**：本專案 `0`/`'0'`/`''`/不存在 一律等於「未設定開課時間」（Issue #203/#222 已定案，見 `Api/Course.php:534-543` 與 `CourseScheduleNullableTest.php`），而未設定時 `is_course_ready()` 直接回 true（`Utils/Course.php:108-117`）→ 不存在「未開課→已開課」的狀態轉換可當事件。移掉 `> 0` 會讓所有沒設排程的課程在下一次 cron 一次全部觸發、對所有既有學員群發 course_launch 信 | **不改 SQL。另開 issue（P3）**：真正缺口是「沒設開課時間的課程，course_launch 信註定不寄，後台零提示」，應在 `Condition.tsx` 加 UI 驗證 |
| **附帶回報③：`can_send()` 沒查已寄過** | **敘述已過時。** `At.php:83-103` 的 `trigger_condition`（`power_email_can_send` filter 唯一訂閱者，`At.php:47`）**有**查 `pc_email_records.mark_as_sent`，但被 `allow_repeat_send` 開關閘住，而該開關**預設開啟**（`Email.php:87-88`、`Condition.tsx:384-401`）。實務結論不變，原因不同 | **不改。另開 issue（P2）**：`is_sent()` 的 identifier 與寫紀錄的 identifier 不一致（`Email.php:318` 用 `[$post_id]`、`CPT.php:119` 用 `[$course_id, $chapter_id]`）→ 章節類信件去重永遠對不上 |
| **`_limit_*` write-only 死 meta** | 修掉會改變單課到期日的計算語意（改讀快照），是**行為變更**，blast radius 遠大於 #263 | **另開 issue（P2）** |
| **`is_course_product()` 的 variation 不對稱** | `Order.php:229`、`Query.php:156`、`OrderGrantCoursesTool.php:133` 用裸 `get_product_id()`，與 WC 慣例（`class-wc-order-item-product.php:349-354`）和 `Grant.php:189` 不一致。但課程勾選框只顯示於 simple/external/subscription（`Admin/Product.php:55`），variation 幾乎不可能帶 `_is_course` → **潛在不一致而非現行 bug** | **不改**（本次只在 bind_courses_data 的解析上對齊 variation-first）。**另開 issue（P3）** |
| **`Order.php:147` 的巢狀 `$order->save()`** | 在 `woocommerce_new_order`（＝外層 `save()` 中途）內再 `save()`，會多觸發一次 `woocommerce_update_order`。目前可運作，且是 `add_product()` 落地的必要條件 | **不動**（拿掉會讓 marker 與內含商品在 new_order 路徑失效） |
| **`pc_email_records` 的 SQL 字串拼接**（`CRUD.php:25-31` 無 `prepare`） | 既有 security 面，與 #263 無關 | **另開 issue（P2, security）** |
| **E2E 模擬 resume checkout** | 三種驗證途徑中最脆弱：BACS/COD 成功即清空購物車（`class-wc-shortcode-checkout.php:288-290`、`class-wc-checkout.php:1123`）→ cart_hash 必變 → resume 分支永遠進不去；要靠 offsite gateway + `page.route()` 攔外部導轉，需新增基礎設施 | **不做**。主力回歸放 PHPUnit（`WC_Checkout::create_order()` 是 public，`class-wc-checkout.php:375`，能精準紅→綠且秒級） |

---

## 5. 測試計畫

### 5-1. 新增測試檔

**`tests/Integration/Order/OrderResumeCheckoutTest.php`**（此目錄目前對 `Resources\Order` 是**零覆蓋** —— `OrderAutoGrantCourseTest.php` 的 docblock 自陳只測業務核心邏輯，全檔未 import `Resources\Order`）

```php
/**
 * Issue #263：結帳 resume（續用既有 pending/failed 訂單）後課程未開通
 *
 * @group order
 * @group bundle
 * @group issue-263
 * @group happy
 * @group edge
 */
```

> 🔴 **必掛白名單 group**：`phpunit.xml.dist` 的 `<groups><include>` 只收 `smoke/happy/error/edge/security`，而 CI（`pipe.yml:680-681`）與 `composer run test` 都是**不帶 `--group`** 的預設執行。只掛 `@group issue-263` 會讓 CI 綠燈但一支都沒跑。
> （順帶更正 memory：PHPUnit 9.6.34 是「CLI 覆蓋 XML」不是取交集，`vendor/phpunit/phpunit/src/TextUI/TestRunner.php:1001`，所以 `--group issue-263` **會**正常執行。）

### 5-2. 測試案例

**忠實層（主力，走真 `WC_Checkout::create_order()`）**

| # | 案例 | 斷言 |
|---|---|---|
| T1 | `test_resume結帳後方案內含商品仍存在` | `init_cart()` → `add_to_cart(bundle)` → `create_order($data)` 第一次（維持 pending）→ `WC()->session->set('order_awaiting_payment', $id1)` → **同一 cart 不動**再 `create_order($data)` → 斷言 `$id2 === $id1`（證明真的走了 resume 分支）→ 斷言訂單含 `_pc_bundled_from` = bundle_id 的 item |
| T2 | `test_resume結帳後bind_courses_data不遺失` | 同上流程，斷言 bundle item 的 `_bind_courses_data` **非空且內容等於商品 post meta** |
| T3 | `test_resume訂單完成後仍授予方案內課程` | 承 T1 → `update_status('completed')` → `assert_user_has_course_access()` |
| T4 | `test_新單不會重複塞入方案內含商品`（冪等） | 單次 `create_order()`（`woocommerce_new_order` + `woocommerce_checkout_order_processed` 同請求先後觸發）→ 斷言內含商品**只有一份**、`count($order->get_items())` 等於預期值 |
| T5 | `test_重複呼叫repair_order_items不會增加item` | `repair_order_items()` 連跑 3 次 → items 數量不變 |
| T6 | `test_巢狀方案不會遞迴展開` | 方案 A 內含方案 B → 重跑後 B 的內含商品不會被再展開一層 |

**廉價層（若真 checkout 在 wp-env 跑不起來，作為 fallback）**

| # | 案例 | 做法 |
|---|---|---|
| T7 | `test_手工重演resume流程後仍授予課程` | `do_action('woocommerce_resume_order', $id)` → `$order->remove_order_items()` → 依購物車重建 line item → **刻意不 `do_action('woocommerce_new_order')`** → `do_action('woocommerce_checkout_order_processed', $id, [], $order)` → `update_status('completed')` → 斷言授權 |

> 🔴 T7 的註解**必須明寫「刻意不 fire `woocommerce_new_order`，因為 resume 走 data store `update()`」**。既有 4 支端到端測試（`FreeBundleCheckoutTest.php:239`、`BundleRemoveCourseTest.php:243/348`、`CourseDuplicateBundleBindTest.php:295`）全部手工 `do_action('woocommerce_new_order')`，把「hook 一定會 fire」寫死成前提 —— 這正是 #263 被遮蔽的原因。沒有這段註解，下一個維護者會「順手補上」而把測試變成永遠綠。

**補強修測試（可放同檔或 `tests/Integration/Order/OrderBindCoursesFallbackTest.php`）**

| # | 案例 | 斷言 |
|---|---|---|
| T8 | `test_item_meta缺失時fallback讀商品現況並self_heal` | 手動 `delete_metadata('order_item', $item_id, '_bind_courses_data')` → 呼叫 `get_item_bind_courses_data()` → 回傳非空 **且** item meta 已被回寫 |
| T9 | `test_fallback後MCP order_get回傳課程清單非空` | `Query::get_with_courses()` 的 `courses` 非空 |
| T10 | `test_order_grant_courses對resume訂單回報正確granted_count` | `granted_count > 0` 且訊息不是「訂單內沒有課程商品可授權。」 |
| T11 | `test_髒bind_courses_data不會中斷授權`（@group edge） | post meta 塞 `[['limit_type'=>'fixed'], ['id'=>$c,'limit_type'=>'fixed','limit_value'=>30,'limit_unit'=>'day']]`（第一列缺 `id`）→ 斷言第二列的課程**仍被授予**、無 fatal、`did_action('woocommerce_order_status_completed')` 完整跑完 |

### 5-3. 可複用的既有範本

- 端到端骨架：`tests/Integration/BundleProduct/FreeBundleCheckoutTest.php:225-247`（含 `make_course()` / `make_bundle()` fixture）
- 建單 helper：`tests/Integration/AccessPass/PurchaseGrantAccessPassTest.php:260-275`
- **WC session/cart 初始化（repo 內唯一先例）**：`tests/Integration/BundleProduct/BundleSellabilityTest.php:617-635` 的 `init_cart()`
- 基底 helper：`tests/Integration/TestCase.php` 的 `create_course()` / `assert_user_has_course_access()` / `assert_action_fired()`

### 5-4. 執行指令

```bash
# 0. 先啟動 Docker Desktop（本機目前 docker API 連不上）
pnpm run env:start

# 1. 只跑新測試（--group 會覆蓋 XML 白名單）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --group issue-263 --testdox

# 2. 確認在 CI 的預設執行下也會被跑到（驗證白名單 group 有掛對）
npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course -- vendor/bin/phpunit --testdox

# 3. PHP 品質（CI 沒有 PHPStan gate，只能靠本機）
pnpm run lint:php
```

> `--` 是必須的（`node_modules/@wordpress/env/lib/commands/run.js:39-42`）。**不要**照抄 `.github/workflows/pipe.yml:680-681`：它少了 `--` 且 `--env-cwd` 是 `wp-power-package`（CI checkout 目錄名），本機必然找不到。

### 5-5. 驗收標準

1. 新測試在**修復前紅、修復後綠**（先 stash 修改跑一次確認會紅 —— 不會紅的測試是說謊儀器）
2. 不帶 `--group` 的預設執行**必須**包含新測試（`--testdox` 輸出裡找得到測試名）
3. PHPUnit 全量：`Failures` / `Errors` **不高於基線**。基線需在同一 session 先跑一次取得（memory 記載的 v1.8.6 後數字 44 failures + 8 errors 已可能飄移，禁止直接引用）
4. `pnpm run lint:php` 通過（PHPCS + PHPStan level 9）
5. `tests/Integration/BundleProduct/` 全部既有測試維持綠（尤其 `FreeBundleCheckoutTest` / `BundleRemoveCourseTest` / `CourseDuplicateBundleBindTest` —— 它們現在會多走一次 `woocommerce_checkout_order_processed`？**不會**，它們只手工 fire `woocommerce_new_order`，所以正好驗證冪等閘門對「只 fire 一次」的情境無副作用）
6. 手動 smoke（`https://local-turbo.powerhouse.tw`）：加入方案 → 結帳 → 選一個會失敗的付款方式 → 回結帳頁再送一次 → 訂單明細應含內含商品、完成訂單後課程開通

> ⚠️ `TestCase::ensure_tables_exist()`（`tests/Integration/TestCase.php:94-108`）與 `clean_custom_tables()`（:114-122）**不含 `pc_user_access_pass`**。新測試若走到 `Grant::on_order_completed()`，需自行照 `PurchaseGrantAccessPassTest.php:275-277` 清理。
> ⚠️ WC session/cart 是 global，`WP_UnitTestCase` 的 transaction rollback 蓋不到。tear_down 要 `$cart->empty_cart()` 並 `unset WC()->session->order_awaiting_payment`。

---

## 6. 回填既有受害訂單

### 6-1. 偵測全站受害訂單（SQL，CPT / HPOS 通用）

`wp_woocommerce_order_items` / `order_itemmeta` 兩張表在 CPT 與 HPOS 下都共用，故單一 SQL 即可：

```sql
-- 找出「item 對應商品是課程 / 有綁定課程 / 是銷售方案，但該 item 完全沒有 power-course item meta」的訂單
SELECT DISTINCT oi.order_id
FROM wp_woocommerce_order_items AS oi
INNER JOIN wp_woocommerce_order_itemmeta AS pim
        ON pim.order_item_id = oi.order_item_id AND pim.meta_key = '_product_id'
LEFT JOIN wp_woocommerce_order_itemmeta AS bcm
        ON bcm.order_item_id = oi.order_item_id AND bcm.meta_key = '_bind_courses_data'
LEFT JOIN wp_woocommerce_order_itemmeta AS icm
        ON icm.order_item_id = oi.order_item_id AND icm.meta_key = '_is_course'
LEFT JOIN wp_postmeta AS pm_course
        ON pm_course.post_id = pim.meta_value AND pm_course.meta_key = '_is_course'
LEFT JOIN wp_postmeta AS pm_bind
        ON pm_bind.post_id = pim.meta_value AND pm_bind.meta_key = 'bind_courses_data'
LEFT JOIN wp_postmeta AS pm_bundle
        ON pm_bundle.post_id = pim.meta_value AND pm_bundle.meta_key = 'bundle_type'
WHERE oi.order_item_type = 'line_item'
  AND bcm.meta_id IS NULL          -- 沒有 _bind_courses_data
  AND icm.meta_id IS NULL          -- 也沒有 _is_course
  AND (
        pm_course.meta_value IN ('yes','on')                              -- 商品是課程
     OR (pm_bind.meta_value IS NOT NULL AND pm_bind.meta_value <> '')     -- 商品有綁定課程
     OR (pm_bundle.meta_value IS NOT NULL AND pm_bundle.meta_value <> '') -- 商品是銷售方案
  )
ORDER BY oi.order_id DESC;
```

判準邏輯：只要 `_handle_add_course_item_meta_by_order_item()` 跑過，課程商品必有 `_is_course`、有綁定的必有 `_bind_courses_data`。兩者皆缺 = 該 item 從未被處理過。

**方案內含商品缺失的補充判準**（SQL 難表達，用 PHP dry-run 更準）：對每張含 bundle item 的訂單，比對 `Helper::get_product_ids_with_compat()` 與訂單實際 item 的 product_id 集合，缺口即為遺失的內含商品。

### 6-2. 回填腳本（不新增 CLI 基礎設施，用 `wp eval-file`）

外掛目前**沒有任何 WP-CLI 註冊**（`grep -rn "WP_CLI" inc/` → 0 命中），為 4 筆訂單建整套 CLI infra 不划算。建 `scripts/repair-issue-263.php`（**不進 autoload，一次性腳本**）：

```php
<?php
/**
 * Issue #263 一次性回填腳本
 *
 * 用法：
 *   wp eval-file scripts/repair-issue-263.php --dry-run
 *   wp eval-file scripts/repair-issue-263.php --apply
 *   wp eval-file scripts/repair-issue-263.php --apply --orders=1234,1235
 */

declare( strict_types=1 );

$argv_all = $GLOBALS['argv'] ?? [];
$apply    = in_array( '--apply', $argv_all, true );
$only     = [];
foreach ( $argv_all as $arg ) {
	if ( str_starts_with( (string) $arg, '--orders=' ) ) {
		$only = array_filter( array_map( 'intval', explode( ',', substr( (string) $arg, 9 ) ) ) );
	}
}

$order_ids = $only ?: /* 貼上 6-1 SQL 的結果，或直接 $wpdb->get_col( $sql ) */ [];

$repairer = \J7\PowerCourse\Resources\Order::instance();

foreach ( $order_ids as $order_id ) {
	$order = \wc_get_order( $order_id );
	if ( ! $order instanceof \WC_Order ) {
		\WP_CLI::warning( "訂單 {$order_id} 不存在，略過" );
		continue;
	}

	$before = count( $order->get_items() );

	if ( ! $apply ) {
		\WP_CLI::log( "[dry-run] 訂單 {$order_id}：目前 {$before} 個 item，狀態 {$order->get_status()}" );
		continue;
	}

	// 1. 補回方案內含商品 + item meta（冪等）
	$repairer->repair_order_items( $order );

	// 2. 重跑授權（AddStudent / LifeCycle 已對 avl_course_ids 去重）
	$repairer->add_meta_to_avl_course( (int) $order_id );

	$order = \wc_get_order( $order_id );
	$after = $order instanceof \WC_Order ? count( $order->get_items() ) : 0;

	\WP_CLI::success( "訂單 {$order_id} 已修復：item {$before} → {$after}" );
}
```

執行：

```bash
# 本機
wp eval-file scripts/repair-issue-263.php --dry-run
wp eval-file scripts/repair-issue-263.php --apply --orders=1234,1235,1236,1237
```

### 6-3. 給站長的手動路徑（4 筆訂單，最省事）

修復上線後，直接對每張訂單呼叫 MCP tool `order_grant_courses`（補強修 D 已讓它先跑 `repair_order_items()` 再統計授權）。修復前該工具對這 4 筆會回 `granted_count = 0` 且訊息說謊；修復後會正確補回內含商品並授權。

> ⚠️ **注意副作用**：回填會補開通課程 → 觸發 `LifeCycle::ADD_STUDENT_TO_COURSE_ACTION` → 可能寄出「課程開通信」（`allow_repeat_send` 預設為 true，見補強說明）。若不希望補寄，回填前先把相關 pe_email 設為 draft，或在腳本執行區間暫時 `remove_action` 郵件觸發。**4 筆訂單建議直接讓信寄出**（學員本來就該收到）。

---

## 7. 風險與回滾

### Blast radius

| 檔案 | 改動 | 影響面 |
|---|---|---|
| `inc/classes/Resources/Order.php` | 新增 2 個 hook + 1 個 const + 3 個 method；改寫 `_handle_add_course_item_meta_by_order()` | **所有訂單建立路徑**（傳統結帳 / 區塊結帳 / 後台 / REST / MCP / 訂閱） |
| `inc/classes/Resources/Course/BindCoursesData.php` | constructor 加防守 | 所有讀 `bind_courses_data` 的地方（商品編輯、購物車判定、授權） |
| `inc/classes/Resources/Order/Service/Query.php` | 1 行改用 helper | MCP `order_get` |
| `inc/classes/Api/Mcp/Tools/Order/OrderGrantCoursesTool.php` | 統計前先 repair + 改 description | MCP `order_grant_courses` |

### 主要迴歸點

| 風險 | 機率 | 緩解 |
|---|---|---|
| **內含商品被塞兩次**（新單走兩個 hook） | 中 → 靠 marker 消除 | T4/T5 專測；上線後查 `SELECT order_id, COUNT(*) FROM order_items WHERE order_item_type='line_item' GROUP BY order_id` 異常值 |
| **`get_item( $id, false )` 找不到 in-memory item** | 低 | `abstract-wc-order.php:1040-1052` 明確支援；找不到時 `instanceof` 檢查會安全跳過（只是少了 marker → 退化成「可能重複展開」而非 fatal） |
| **`woocommerce_checkout_order_processed` 早於付款處理** | 無害 | 它在 `class-wc-checkout.php:1352`，晚於 `create_order()`、早於 gateway。此時已有完整訂單與 items，正是我們要的時機 |
| **區塊結帳被雙重觸發**（`woocommerce_new_order` draft→pending + `store_api_checkout_order_processed`） | 中 → 靠 marker 消除 | 區塊結帳有自身 cart-hash 閘門（`OrderController.php:785-790`），加上 marker，雙保險 |
| **`Order.php:147` 巢狀 `$order->save()` 在新 hook 下的行為** | 低 | 在 `checkout_order_processed` 情境是正常的頂層 save（不再巢狀），比 `new_order` 情境**更安全** |
| **fallback 讓已授權學員 expire_date 被回溯改變** | 低 | self-heal 讓每張訂單只讀一次現況；且只在 item meta 缺失時才觸發（正常訂單不會走到） |
| **`BindCoursesData` 防守改變既有結果** | 極低 | `set_limit_value()` 對 `0` 與 `null` 同語意（`Limit.php:205-212`），已驗證行為不變 |
| **CI 沒有 PHPStan gate** | 中 | `grep -rn phpstan .github/` → 0 命中。型別問題不會被 CI 擋，**必須本機跑 `pnpm run lint:php`** |

### 回滾方案

改動集中在 4 個 PHP 檔、無 DB schema 變更、無前端變更 → **`git revert` 單一 commit 即可完全回滾**。

唯一的持久性副作用是新寫入的 `_pc_bundled_from` item meta 與 self-heal 回寫的 `_bind_courses_data`：

- `_pc_bundled_from` 在回滾後成為無害的孤兒 meta（舊程式不讀它），**不需清理**
- self-heal 回寫的 `_bind_courses_data` 正是舊程式**本來就期望存在**的資料，回滾後反而讓那些訂單變正常 → **不需清理**
- 回填腳本已授予的課程授權（`avl_course_ids` / `pc_avl_coursemeta`）不會被回滾撤銷 —— 這是**期望行為**（學員本來就該有），若真要撤銷需走既有的撤銷流程

**分階段上線建議**：先只上主修 A（hook + 冪等），觀察 1~2 天訂單 item 數量無異常，再上補強修 B/C/D 與回填。