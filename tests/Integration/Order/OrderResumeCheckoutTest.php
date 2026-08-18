<?php
/**
 * 續付結帳（resume）保留方案內含商品與 _bind_courses_data 整合測試
 *
 * Issue #263：WooCommerce 傳統結帳的 resume 分支（class-wc-checkout.php:401-413）
 * 在「顧客付款失敗、回到結帳頁再送一次」時，會先 remove_order_items() 砍光所有
 * order item（含 itemmeta），再依購物車重建 line item；且該路徑走 data store
 * update()，**不會**二次觸發 woocommerce_new_order。
 *
 * 修復前 Resources\Order 的寫入端只掛在 woocommerce_new_order，於是：
 * 1. 「銷售方案內含商品」的 order item 永久消失（購物車裡只有方案本身，重建救不回來）
 * 2. _bind_courses_data item meta 一併消失 → 綁定課程 100% 不開通、通知信不寄
 *
 * 修復後補掛 woocommerce_checkout_order_processed（新單 / resume 單都會走到，
 * 且此時 items 已落地、具真實 order_item_id），並以 item meta marker 當冪等閘門。
 *
 * Plan: specs/plans/issue-263-resume-checkout-bind-courses-data.md（第 5 節測試計畫）
 *
 * @group order
 * @group bundle
 * @group issue-263
 * @group happy
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Order;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Resources\Order as OrderResource;

/**
 * Class OrderResumeCheckoutTest
 * 驗證 resume 結帳後方案內含商品與 item meta 仍完整，且重跑具冪等性
 *
 * @group order
 * @group bundle
 * @group issue-263
 * @group happy
 * @group edge
 */
class OrderResumeCheckoutTest extends TestCase {

	/**
	 * 顧客用戶 ID
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * 初始化依賴
	 *
	 * Resources\Order 的 hook 由 Bootstrap 在外掛載入時註冊；
	 * 這裡再 instance() 一次只是確保（SingletonTrait 不會重複掛 hook）。
	 */
	protected function configure_dependencies(): void {
		OrderResource::instance();
	}

	/**
	 * 每個測試前建立顧客並設為當前使用者
	 *
	 * WC_Checkout::create_order() 是用 get_current_user_id() 決定訂單顧客
	 * （woocommerce_checkout_customer_id filter），
	 * 所以走真結帳的測試必須先 wp_set_current_user()。
	 */
	public function set_up(): void {
		parent::set_up();

		$this->truncate_wc_order_item_tables();

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_login' => 'buyer_' . uniqid(),
				'user_email' => 'buyer_' . uniqid() . '@test.com',
			]
		);

		\wp_set_current_user( $this->customer_id );
	}

	/**
	 * 每個測試後清理 WC 全域狀態與自訂表
	 *
	 * WC 的 session / cart 是 global 單例，WP_UnitTestCase 的 DB transaction rollback
	 * 蓋不到它們；order_awaiting_payment 若殘留會讓下一個測試誤入 resume 分支。
	 * 另外 TestCase::clean_custom_tables() 不含 pc_user_access_pass
	 * （Grant::on_order_completed() 會寫入該表），需自行清理。
	 */
	public function tear_down(): void {
		if ( \function_exists( 'WC' ) ) {
			if ( \WC()->cart instanceof \WC_Cart ) {
				\WC()->cart->empty_cart();
			}
			if ( null !== \WC()->session ) {
				\WC()->session->set( 'order_awaiting_payment', null );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	// ========== T1 ~ T3：忠實層（走真的 WC_Checkout::create_order()） ==========

	/**
	 * @test
	 * @group happy
	 *
	 * T1 — resume 結帳後，方案內含商品的 order item 仍在。
	 *
	 * 修復前為什麼會紅：resume 分支 remove_order_items() 砍光 items 後，
	 * 只依購物車重建 line item（購物車裡只有方案本身），而補內含商品的程式碼
	 * 只掛在 woocommerce_new_order —— 該 hook 在 resume（data store update()）
	 * 不會二次觸發，內含商品因此永久消失。
	 */
	public function test_resume結帳後方案內含商品仍存在(): void {
		$course_id = $this->make_course( '課程_T1' );
		$bundle_id = $this->make_bundle( '方案_T1', [ $course_id ], $course_id );

		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$this->assertNotFalse( $cart->add_to_cart( $bundle_id, 1 ), '前置條件：方案應可加入購物車' );
		$cart->calculate_totals();
		$this->assertCount( 1, $cart->get_cart(), '前置條件：購物車應只有方案本身' );

		// 第一次結帳（訂單維持 pending，等同顧客尚未付款成功）
		$order_id_1 = $this->run_checkout();

		$order = \wc_get_order( $order_id_1 );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertCount( 1, $this->get_bundled_items( $order, $bundle_id ), '前置條件：第一次結帳應已把方案內含商品塞成 order item' );

		// 模擬 WC_Checkout::process_order_payment()（class-wc-checkout.php:1077）
		// 把訂單存進 session；顧客付款失敗回到結帳頁再送一次時，
		// create_order() 才會走進 resume 分支
		\WC()->session->set( 'order_awaiting_payment', $order_id_1 );

		// 購物車刻意完全不動 → cart_hash 相同 → 必定進入 resume 分支
		$order_id_2 = $this->run_checkout();

		// 這條斷言是「真的走了 resume 分支」的唯一證明。
		// 若購物車被動過（cart_hash 改變），WC 會改建一張新訂單，
		// 此測試就會退化成「測新單」的說謊儀器。
		$this->assertSame( $order_id_1, $order_id_2, 'cart_hash 未變且訂單為 pending，第二次結帳應續用同一張訂單（走 resume 分支）' );

		$order = \wc_get_order( $order_id_2 );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$bundled_items = $this->get_bundled_items( $order, $bundle_id );
		$this->assertCount( 1, $bundled_items, 'resume 後方案內含商品應仍存在，且只有一份（Issue #263）' );

		$bundled_item = \reset( $bundled_items );
		$this->assertSame( $course_id, (int) $bundled_item->get_product_id(), '內含商品應是方案設定的課程商品' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * T2 — resume 結帳後，方案 line item 的 _bind_courses_data 仍在。
	 *
	 * 修復前為什麼會紅：itemmeta 與 item 一起被 DELETE
	 * （abstract-wc-order-data-store-cpt.php:651-652），重建出來的新 item 是全新 row，
	 * 而唯一的寫入時機 woocommerce_new_order 不再觸發 → 快照永久遺失，
	 * add_meta_to_avl_course() 的「不是課程商品且沒有綁定課程」守門會直接 continue。
	 *
	 * 這裡刻意讀「原始 item meta」而不是走 Order::get_item_bind_courses_data()，
	 * 否則補強修 B 的 fallback（讀商品現況）會把 item meta 遺失這件事遮掉。
	 */
	public function test_resume結帳後bind_courses_data不遺失(): void {
		$course_id = $this->make_course( '課程_T2' );
		$bound_id  = $this->make_course( '綁定課程_T2' );
		$bind_data = [ $this->bind_row( $bound_id ) ];
		$bundle_id = $this->make_bundle( '方案_T2', [ $course_id ], $course_id, $bind_data );

		$order_id = $this->checkout_then_resume( $bundle_id );

		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$bundle_item = $this->get_line_item_by_product( $order, $bundle_id );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $bundle_item, 'resume 後訂單應仍有方案本身的 line item' );

		$snapshot = $bundle_item->get_meta( '_bind_courses_data' );
		$this->assertIsArray( $snapshot, 'resume 後 _bind_courses_data item meta 應為陣列' );
		$this->assertNotEmpty( $snapshot, 'resume 後 _bind_courses_data item meta 不應遺失（Issue #263）' );
		$this->assertEquals(
			\get_post_meta( $bundle_id, 'bind_courses_data', true ),
			$snapshot,
			'_bind_courses_data 內容應等同商品當下的 bind_courses_data'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * T3 — resume 的訂單完成後，仍會授予方案內含的課程。
	 *
	 * 修復前為什麼會紅：這個方案刻意不設 bind_courses_data，
	 * 唯一的開通路徑就是「方案內含商品 order item」→ handle_single_course()。
	 * 而那正是 resume 會弄丟、且任何 item meta fallback 都救不回來的東西
	 * （購物車裡根本沒有這個商品，重建不會生出它）→ 課程 100% 不開通。
	 */
	public function test_resume訂單完成後仍授予方案內課程(): void {
		$course_id = $this->make_course( '課程_T3' );
		$bundle_id = $this->make_bundle( '方案_T3', [ $course_id ], $course_id );

		$this->assert_user_has_no_course_access( $this->customer_id, $course_id );

		$order_id = $this->checkout_then_resume( $bundle_id );

		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->update_status( 'completed' );

		$this->assert_user_has_course_access( $this->customer_id, $course_id );
	}

	// ========== T4 ~ T6：冪等性 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * T4 — 新單（非 resume）不會把方案內含商品塞兩次。
	 *
	 * 為什麼需要這條：修復補掛了 woocommerce_checkout_order_processed，
	 * 新單情境下它會與 woocommerce_new_order 在**同一個請求**內先後觸發兩次。
	 * 沒有 item meta 冪等閘門（_pc_bundle_expanded / _pc_bundled_from）的話，
	 * 內含商品會整批翻倍 —— 這是修復本身最大的迴歸風險。
	 */
	public function test_新單不會重複塞入方案內含商品(): void {
		$course_id  = $this->make_course( '課程_T4' );
		$product_id = $this->make_simple_product( '週邊商品_T4' );
		$bundle_id  = $this->make_bundle( '方案_T4', [ $course_id, $product_id ], $course_id );

		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$this->assertNotFalse( $cart->add_to_cart( $bundle_id, 1 ), '前置條件：方案應可加入購物車' );
		$cart->calculate_totals();

		// 單次結帳：woocommerce_new_order 與 woocommerce_checkout_order_processed 都會跑到
		$order_id = $this->run_checkout();

		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$items = $order->get_items();
		$this->assertCount( 3, $items, '訂單應只有 方案本身 + 2 個內含商品，共 3 列（重複觸發不得翻倍）' );
		$this->assertCount( 2, $this->get_bundled_items( $order, $bundle_id ), '內含商品應只被展開一輪' );

		$product_ids = $this->get_item_product_ids( $order );
		$this->assertSame( 1, \count( \array_keys( $product_ids, $course_id, true ) ), '內含課程商品只能有一列' );
		$this->assertSame( 1, \count( \array_keys( $product_ids, $product_id, true ) ), '內含週邊商品只能有一列' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * T5 — 重複呼叫 repair_order_items() 不會增加 item。
	 *
	 * 為什麼需要這條：repair_order_items() 是對外的修復入口
	 * （MCP order_grant_courses 與一次性回填腳本都會呼叫），
	 * 站長重複點擊 / 腳本重跑是常態；沒有冪等性就會把訂單灌成一堆重複列。
	 */
	public function test_重複呼叫repair_order_items不會增加item(): void {
		$course_id  = $this->make_course( '課程_T5' );
		$product_id = $this->make_simple_product( '週邊商品_T5' );
		$bundle_id  = $this->make_bundle( '方案_T5', [ $course_id, $product_id ], $course_id );

		// wc_create_order() 建立當下訂單還沒有任何 item，
		// 所以 woocommerce_new_order 觸發時沒東西可展開；
		// 之後 add_product() + save() 走 update()，也不會展開 → 得到「只有方案本身」的受害訂單
		$order = $this->make_order_with_product( $bundle_id );
		$this->assertCount( 1, $order->get_items(), '前置條件：訂單應只有方案本身這一列' );

		OrderResource::instance()->repair_order_items( $order );
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$count_after_first = \count( $order->get_items() );
		$this->assertSame( 3, $count_after_first, '第一次修復應補回 2 個內含商品（共 3 列）' );

		// 連跑第 2、3 次
		for ( $i = 0; $i < 2; $i++ ) {
			OrderResource::instance()->repair_order_items( $order );
			$order = \wc_get_order( $order->get_id() );
			$this->assertInstanceOf( \WC_Order::class, $order );
		}

		$this->assertSame( $count_after_first, \count( $order->get_items() ), 'repair_order_items() 連跑 3 次後 item 數量不應改變' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * T6 — 巢狀方案（方案 A 內含方案 B）不會被遞迴展開。
	 *
	 * 為什麼需要這條：修復把展開邏輯從「只跑一次」變成「可重跑」，
	 * 若沒有 _pc_bundled_from 閘門，第二次重跑時被展開出來的方案 B 會被當成
	 * 一般方案再展開一層，訂單每跑一次就多長出一批 item。
	 */
	public function test_巢狀方案不會遞迴展開(): void {
		$course_id       = $this->make_course( '課程_T6' );
		$inner_bundle_id = $this->make_bundle( '內層方案_T6', [ $course_id ], $course_id );
		$outer_bundle_id = $this->make_bundle( '外層方案_T6', [ $inner_bundle_id ] );

		$order = $this->make_order_with_product( $outer_bundle_id );

		OrderResource::instance()->repair_order_items( $order );
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertCount( 2, $order->get_items(), '外層方案只展開一層：外層方案 + 內層方案，共 2 列' );

		// 再跑一次，確認不會「補展開」內層方案
		OrderResource::instance()->repair_order_items( $order );
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertCount( 2, $order->get_items(), '重跑後仍應只有 2 列（內層方案不得被再展開一層）' );

		$this->assertNotContains( $course_id, $this->get_item_product_ids( $order ), '內層方案的內含課程不應被遞迴展開成 order item' );
	}

	// ========== T7：hook 契約（不依賴真 checkout / cart / session） ==========

	/**
	 * @test
	 * @group happy
	 *
	 * T7 — resume 單真正會觸發的是 woocommerce_checkout_order_processed；
	 *      只要它觸發，方案內含商品就會被補回、課程就會開通。
	 *
	 * 這是 T1~T3 的廉價層備援：完全不碰 WC cart / session / gateway，
	 * 只驗「hook 契約」這一件事 —— 而那正是 Issue #263 的修復本體。
	 *
	 * 為什麼**不**用 remove_order_items() + add_product() 手工重演 resume：
	 * 那個序列在同一個 WC_Order 物件上有 WooCommerce 自己的行為（實測後訂單會變成 0 列），
	 * 與真實 resume 走 WC_Checkout::create_order() 的結果不同 ——
	 * 用不忠實的 fixture 去驗真實 bug，測到的是 fixture 不是 bug。
	 * 真實的 remove/rebuild 路徑由 T1~T3 以真的 WC()->checkout()->create_order() 覆蓋。
	 *
	 * 修復前為什麼會紅：woocommerce_checkout_order_processed 上**沒有掛任何東西**，
	 * 這個 do_action 等於空轉 → 內含課程不會被補回 → 訂單完成也不開通。
	 */
	public function test_只觸發checkout_order_processed也會補回內含商品並開通(): void {
		$course_id = $this->make_course( '課程_T7' );
		$bundle_id = $this->make_bundle( '方案_T7', [ $course_id ], $course_id );

		$this->assert_user_has_no_course_access( $this->customer_id, $course_id );

		// Given：一張「只有方案本身」的受害訂單 —— 這正是 resume 後的訂單長相
		// （remove_order_items() 砍光後，create_order_line_items() 只依購物車重建，
		//   而購物車裡只有方案本身）
		$order = $this->make_order_with_product( $bundle_id );
		$this->assertCount( 1, $order->get_items(), '前置條件：受害訂單應只有方案本身這一列' );

		// When：**刻意不** do_action( 'woocommerce_new_order' )。
		//
		// 給下一位維護者：不要「順手補上」那一行。
		// resume 分支的 $order->save() 走的是 data store 的 update()，
		// 而 update() 只在「前狀態是 draft 系列」時才 fire woocommerce_new_order
		// （class-wc-order-data-store-cpt.php:222-233、OrdersTableDataStore.php:2830-2848）；
		// resume 是 pending→pending，所以該 hook 在真實情境下**絕對不會**再觸發。
		// repo 內既有的 4 支端到端測試（FreeBundleCheckoutTest.php:239、
		// BundleRemoveCourseTest.php:243/348、CourseDuplicateBundleBindTest.php:295）
		// 全部手工 fire woocommerce_new_order，把「hook 一定會 fire」寫死成前提 ——
		// 這正是 Issue #263 躲過所有測試出貨的原因。補上那一行，這支測試就永遠是綠的。
		//
		// resume 單真正會觸發的是 woocommerce_checkout_order_processed
		// （class-wc-checkout.php:1352，新單與 resume 單都會走到，
		//   且傳入的是重新 wc_get_order() 的物件、items 已落地、具真實 order_item_id）
		\do_action( 'woocommerce_checkout_order_processed', $order->get_id(), [], \wc_get_order( $order->get_id() ) );

		// Then
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertCount( 2, $order->get_items(), '內含課程應被補回（方案 + 課程，共 2 列）' );
		$this->assertContains( $course_id, $this->get_item_product_ids( $order ), '補回的應該就是方案內含的課程' );

		$order->update_status( 'completed' );

		$this->assert_user_has_course_access( $this->customer_id, $course_id );
	}

	// ========== 結帳流程 helper ==========

	/**
	 * 初始化 WooCommerce 購物車；環境不支援時 skip
	 *
	 * 沿用 tests/Integration/BundleProduct/BundleSellabilityTest.php 的既有做法。
	 *
	 * @return \WC_Cart|null
	 */
	private function init_cart(): ?\WC_Cart {
		if ( ! \function_exists( 'WC' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入' );
		}

		if ( null === \WC()->session ) {
			\WC()->initialize_session();
		}
		if ( null === \WC()->cart ) {
			\WC()->initialize_cart();
		}

		$cart = \WC()->cart;
		if ( ! ( $cart instanceof \WC_Cart ) ) {
			$this->markTestSkipped( '測試環境無法初始化 WooCommerce 購物車' );
		}

		$cart->empty_cart();
		\WC()->session->set( 'order_awaiting_payment', null );

		return $cart;
	}

	/**
	 * 結帳表單資料（WC_Checkout::create_order() 的 $data）
	 *
	 * payment_method 必填：create_order() 內部直接取 $data['payment_method']，
	 * 缺 key 在 PHP 8 會噴 warning。此處不需要真的啟用任何 gateway ——
	 * 找不到對應 gateway 時 WC 會退化成把字串原樣存進訂單，
	 * 對本測試（只在意 order items 與 item meta）沒有影響。
	 * shipping 亦不需要：create_order_shipping_lines() 只在
	 * packages 與 chosen_shipping_methods 都有值時才建立運費列。
	 *
	 * @return array<string, mixed>
	 */
	private function checkout_data(): array {
		return [
			'payment_method' => 'bacs',
			'billing_email'  => 'buyer@example.com',
			'order_comments' => '',
		];
	}

	/**
	 * 重演 WC_Checkout::process_checkout() 中「建單 → 觸發 checkout_order_processed」兩步
	 *
	 * 為什麼不直接呼叫 process_checkout()：它需要 $_POST + nonce，
	 * 且結尾會 wp_send_json / wp_die，無法在 PHPUnit 內收斂。
	 * 這裡呼叫的 create_order() 是 public（class-wc-checkout.php:375），
	 * 緊接著補的 do_action 與 class-wc-checkout.php:1352 完全一致
	 * （同樣傳入重新 wc_get_order() 的物件），
	 * 所以 resume 分支（:401-413）是真的被執行到的。
	 *
	 * @return int 訂單 ID
	 */
	private function run_checkout(): int {
		$data     = $this->checkout_data();
		$order_id = \WC()->checkout()->create_order( $data );

		$this->assertNotWPError( $order_id, 'WC_Checkout::create_order() 應成功建單' );

		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order, 'create_order() 之後應取得訂單物件' );

		\do_action( 'woocommerce_checkout_order_processed', $order_id, $data, $order );

		return (int) $order_id;
	}

	/**
	 * 走一次「第一次結帳 → 付款失敗 → 再送一次（resume）」的完整流程
	 *
	 * @param int $bundle_id 要買的方案商品 ID
	 * @return int 訂單 ID（resume 成功時前後兩次相同）
	 */
	private function checkout_then_resume( int $bundle_id ): int {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			$this->markTestSkipped( '測試環境無法初始化 WooCommerce 購物車' );
		}

		$this->assertNotFalse( $cart->add_to_cart( $bundle_id, 1 ), '前置條件：方案應可加入購物車' );
		$cart->calculate_totals();

		$order_id_1 = $this->run_checkout();

		\WC()->session->set( 'order_awaiting_payment', $order_id_1 );

		// 購物車刻意不動，確保 cart_hash 相同 → 走 resume 分支
		$order_id_2 = $this->run_checkout();

		$this->assertSame( $order_id_1, $order_id_2, 'cart_hash 未變且訂單為 pending，第二次結帳應續用同一張訂單（走 resume 分支）' );

		return $order_id_2;
	}

	/**
	 * 建立一張只含指定商品的訂單（不觸發任何 power-course 展開）
	 *
	 * wc_create_order() 建立當下訂單沒有任何 item，woocommerce_new_order
	 * 觸發時沒東西可展開；之後 add_product() + save() 走 data store update()，
	 * 同樣不會展開 —— 正好等同「受害訂單」的狀態。
	 *
	 * @param int $product_id 商品 ID
	 * @return \WC_Order
	 */
	private function make_order_with_product( int $product_id ): \WC_Order {
		$order = \wc_create_order( [ 'customer_id' => $this->customer_id ] );

		if ( \is_wp_error( $order ) ) {
			$this->fail( 'wc_create_order 失敗：' . $order->get_error_message() );
		}

		$product = \wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		$order->add_product( $product, 1 );
		$order->save();

		return $order;
	}

	// ========== 訂單項目查詢 helper ==========

	/**
	 * 取出訂單中「由指定方案展開出來」的內含商品項目
	 *
	 * @param \WC_Order $order     訂單
	 * @param int       $bundle_id 母方案商品 ID
	 * @return array<int, \WC_Order_Item_Product>
	 */
	private function get_bundled_items( \WC_Order $order, int $bundle_id ): array {
		$result = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			if ( (int) $item->get_meta( OrderResource::BUNDLED_FROM_META_KEY ) === $bundle_id ) {
				$result[] = $item;
			}
		}

		return $result;
	}

	/**
	 * 依商品 ID 取出訂單項目（取第一筆）
	 *
	 * @param \WC_Order $order      訂單
	 * @param int       $product_id 商品 ID
	 * @return \WC_Order_Item_Product|null
	 */
	private function get_line_item_by_product( \WC_Order $order, int $product_id ): ?\WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			if ( (int) $item->get_product_id() === $product_id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * 取得訂單所有項目的商品 ID
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, int>
	 */
	private function get_item_product_ids( \WC_Order $order ): array {
		$product_ids = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! ( $item instanceof \WC_Order_Item_Product ) ) {
				continue;
			}
			$product_ids[] = (int) $item->get_product_id();
		}

		return $product_ids;
	}

	// ========== fixture ==========

	/**
	 * 建立課程商品
	 *
	 * @param string $title 課程標題
	 * @param string $price 價格
	 * @return int 課程商品 ID
	 */
	private function make_course( string $title, string $price = '999' ): int {
		$course_id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\wp_set_object_terms( $course_id, 'simple', 'product_type' );
		\update_post_meta( $course_id, '_is_course', 'yes' );
		\update_post_meta( $course_id, '_price', $price );
		\update_post_meta( $course_id, '_regular_price', $price );
		\update_post_meta( $course_id, '_stock_status', 'instock' );
		\update_post_meta( $course_id, 'limit_type', 'unlimited' );
		\clean_post_cache( $course_id );

		return $course_id;
	}

	/**
	 * 建立一般商品（非課程）
	 *
	 * @param string $title 商品標題
	 * @param string $price 價格
	 * @return int 商品 ID
	 */
	private function make_simple_product( string $title, string $price = '500' ): int {
		$product_id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\wp_set_object_terms( $product_id, 'simple', 'product_type' );
		\update_post_meta( $product_id, '_price', $price );
		\update_post_meta( $product_id, '_regular_price', $price );
		\update_post_meta( $product_id, '_stock_status', 'instock' );
		\clean_post_cache( $product_id );

		return $product_id;
	}

	/**
	 * 建立銷售方案（bundle 商品）
	 *
	 * @param string                           $title          方案標題
	 * @param array<int>                       $included_ids   方案內含商品 IDs（pbp_product_ids）
	 * @param int                              $link_course_id 方案歸屬課程 ID（0 = 不設定）
	 * @param array<int, array<string, mixed>> $bind_data      bind_courses_data（空 = 不設定）
	 * @return int 方案商品 ID
	 */
	private function make_bundle( string $title, array $included_ids, int $link_course_id = 0, array $bind_data = [] ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\wp_set_object_terms( $bundle_id, 'simple', 'product_type' );
		\update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_id, '_price', '399' );
		\update_post_meta( $bundle_id, '_regular_price', '399' );
		\update_post_meta( $bundle_id, '_stock_status', 'instock' );

		if ( $link_course_id > 0 ) {
			\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $link_course_id );
		}

		// 內含商品清單（多列 meta）與各商品數量（JSON）
		$quantities = [];
		foreach ( $included_ids as $included_id ) {
			\add_post_meta( $bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $included_id );
			$quantities[ (string) $included_id ] = 1;
		}
		\update_post_meta( $bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, \wp_json_encode( $quantities ) );

		// 站長已明確編輯過商品列表（Issue #249）：
		// 讓 get_product_ids_with_compat() 尊重真實列表，不再自動補入 link_course_id，
		// 內含商品數量才是可預期的定值
		\update_post_meta( $bundle_id, Helper::EDITED_PRODUCT_IDS_META_KEY, 'yes' );

		if ( $bind_data ) {
			\update_post_meta( $bundle_id, 'bind_courses_data', $bind_data );
			foreach ( $bind_data as $row ) {
				\add_post_meta( $bundle_id, 'bind_course_ids', (string) $row['id'] );
			}
		}

		\clean_post_cache( $bundle_id );
		\wc_delete_product_transients( $bundle_id );

		return $bundle_id;
	}

	/**
	 * 產生一筆 bind_courses_data
	 *
	 * @param int    $course_id   課程 ID
	 * @param string $limit_type  限制類型
	 * @param int    $limit_value 限制值
	 * @param string $limit_unit  限制單位
	 * @return array<string, mixed>
	 */
	private function bind_row( int $course_id, string $limit_type = 'fixed', int $limit_value = 30, string $limit_unit = 'day' ): array {
		return [
			'id'          => $course_id,
			'name'        => \get_the_title( $course_id ),
			'limit_type'  => $limit_type,
			'limit_value' => $limit_value,
			'limit_unit'  => $limit_unit,
		];
	}

	/**
	 * 清空 WooCommerce 訂單項目表（測試隔離）
	 *
	 * `wp_woocommerce_order_items` / `order_itemmeta` 的殘留列會被「重複使用相同 order_id」
	 * 的新訂單撿走，讓 `wc_create_order()` 一建立就帶著上一輪測試的 item
	 * （實測過：建單當下 items 已經是 2，其中一列還帶著 `_pc_bundle_expanded` 標記）。
	 * 後果是本檔所有「訂單應只有 N 列」的前置條件全部隨執行順序飄移 ——
	 * 單獨跑綠、整包跑紅，是最難查的那種假訊號。
	 *
	 * 放在 set_up() 而非 tear_down()：前一個**測試檔**留下的殘留也要清掉。
	 */
	private function truncate_wc_order_item_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

}
