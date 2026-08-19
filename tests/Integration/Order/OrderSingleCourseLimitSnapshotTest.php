<?php
/**
 * 單一課程到期日「下單快照」整合測試（Issue #263 對抗式審查衍生 — 修復 3）
 *
 * 病灶：`Order::_handle_add_course_item_meta_by_order_item()` 從一開始就把商品的
 * `limit_type` / `limit_value` / `limit_unit` 以 `_` 前綴寫成 order item meta，
 * 意圖顯然是「用購買當下的條件計算到期日」—— 但這份快照**長期沒有任何讀取端**：
 * `Order::handle_single_course()` 一律 `Limit::instance( $product_id )` 從**商品現況**重算。
 *
 * 後果：站長只要改過商品的期限設定，舊訂單再次進入 trigger 狀態
 * （trigger + completed 兩個狀態各觸發一次 `add_meta_to_avl_course()`，
 * 手動改單、續付、後台重跑狀態也都會再觸發），
 * 既有學員的 `expire_date` 就被回溯改寫 —— `LifeCycle::add_student_to_course()`
 * 對 `expire_date` 是**無條件覆寫**，沒有「只在更長時才延長」這種保護。
 *
 * 修復：新增 `Limit::from_order_item( $item ): ?self`（讀 `_limit_*` 快照，
 * 缺 `_limit_type` 回 null），`handle_single_course()` 改為
 * `Limit::from_order_item( $item ) ?? Limit::instance( $product_id )`。
 *
 * 與 Issue #263 主修同一條原則：**fallback 不得回溯改寫歷史**。
 *
 * ⚠️ 這裡刻意**沒有** `@group` —— 檔案 docblock（`declare` 之前）的 annotation
 * PHPUnit 讀不到，寫了只會給下一位維護者「已經標好了」的錯覺。
 * 真正生效的 `@group` 在下面的 class docblock 與各 method docblock。
 */

declare( strict_types=1 );

namespace Tests\Integration\Order;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Order as OrderResource;

/**
 * Class OrderSingleCourseLimitSnapshotTest
 * 驗證單課到期日以「下單當時的 limit 快照」為準，缺快照才 fallback 商品現況
 *
 * `@group` 一律寫在 **class docblock 與 method docblock**，不寫在檔案 docblock：
 * 放在 `declare` 之前的檔案 docblock PHPUnit 讀不到，`--group xxx` 會回
 * 「No tests executed!」（本 repo 既有測試檔全踩過這個坑）。
 * class 層必須含白名單 group（happy / edge / error / smoke / security 之一以上），
 * 因為 `phpunit.xml.dist` 的 `<groups><include>` 只收這五個，CI 是不帶 `--group` 的預設執行。
 *
 * @group order
 * @group limit
 * @group issue-263-followup
 * @group happy
 * @group edge
 */
class OrderSingleCourseLimitSnapshotTest extends TestCase {

	/**
	 * 顧客用戶 ID
	 *
	 * @var int
	 */
	private int $customer_id;

	/**
	 * 初始化依賴
	 *
	 * `Resources\Order` 的 hook 由 Bootstrap 在外掛載入時註冊；
	 * 這裡再 `instance()` 一次只是確保（SingletonTrait 不會重複掛 hook）。
	 */
	protected function configure_dependencies(): void {
		OrderResource::instance();
	}

	/**
	 * 每個測試前清訂單項目表並建立顧客
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
	}

	/**
	 * 每個測試後清理 `pc_user_access_pass`
	 *
	 * `TestCase::clean_custom_tables()` 只清 5 張表，**不含** `pc_user_access_pass`；
	 * 而 `add_meta_to_avl_course()` 結尾會走到
	 * `AccessPass\Service\Grant::on_order_completed()`。
	 * 照 `OrderBindCoursesFallbackTest::tear_down()` 的既有範本自行補清。
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	// ========== 主線：快照優先 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 有快照時，到期日以「下單當時」的 limit 計算，不受商品現況影響。
	 *
	 * 修復前為什麼會紅：`handle_single_course()` 舊版是
	 * `Limit::instance( $product_id )->calc_expire_date( $order )` ——
	 * 完全不看 order item 上的 `_limit_*` 快照，一律讀商品**現況**。
	 * 商品被改成 365 天後，這張早就下好的訂單一旦再次進入 trigger 狀態，
	 * 學員到期日就會被回溯改寫成「+365 天」，本測試的 assertSame（+30 天）必紅。
	 */
	public function test_有快照時以下單當時的limit計算到期日(): void {
		$course_id = $this->make_course_product( '快照課程_30天', 'fixed', '30', 'day' );

		$order   = $this->make_order_with_snapshot( $course_id );
		$item_id = $this->get_only_item_id( $order );

		// 前置條件：下單快照真的落地了。
		// 沒有這條，快照缺席時測試會退化成「測 fallback」而照樣綠 —— 說謊儀器。
		$this->assert_item_limit_snapshot( $item_id, 'fixed', '30', 'day' );

		// 站長事後把商品期限從 30 天改成 365 天
		$this->set_product_limit( $course_id, 'fixed', '365', 'day' );

		// 前置條件：商品現況真的變了。
		// 沒有這條，若 meta 因快取沒生效，「到期日 ≈ 30 天」會是廢話式綠燈。
		$this->assertSame(
			365,
			Limit::instance( $course_id )->limit_value,
			'前置條件：商品現況應已被改成 365 天'
		);

		// 訂單完成 → woocommerce_order_status_completed → add_meta_to_avl_course()
		$order->update_status( 'completed' );

		$this->assert_user_has_course_access( $this->customer_id, $course_id );

		$expire_date = (int) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' );

		$this->assertSame(
			$this->expected_fixed_expire_date( 30, 'day' ),
			$expire_date,
			'到期日應以下單當時的快照（30 天）計算'
		);
		$this->assertNotSame(
			$this->expected_fixed_expire_date( 365, 'day' ),
			$expire_date,
			'到期日不得被商品現況（365 天）回溯改寫'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 快照是 `unlimited`（永久）時，不會被商品現況的 `fixed` 蓋掉。
	 *
	 * 為什麼要獨立一條：`fixed` 對 `fixed` 只差在數字，
	 * 但 `unlimited` → `fixed` 是**語意翻面** —— 買的是永久課程，
	 * 站長改成「30 天」後，既有學員會憑空長出一個到期日、時間到就看不了。
	 * 這是同一個 bug 裡傷害最大的形態。
	 *
	 * 修復前為什麼會紅：`Limit::instance()` 讀到商品現況的 `fixed / 30 / day`，
	 * `calc_expire_date()` 回傳 +30 天的 timestamp，
	 * 學員的 `expire_date` 從 `0`（永久）被改寫成有限日期。
	 */
	public function test_unlimited快照不會被商品現況的fixed覆蓋(): void {
		$course_id = $this->make_course_product( '永久課程', 'unlimited' );

		$order   = $this->make_order_with_snapshot( $course_id );
		$item_id = $this->get_only_item_id( $order );

		// 前置條件：`unlimited` 商品沒有 limit_value / limit_unit，
		// 寫入端會把空字串一起寫進 item meta（`_limit_type` 非空才是快照存在的判準）
		$this->assert_item_limit_snapshot( $item_id, 'unlimited', '', '' );

		// 站長事後把商品改成「固定 30 天」
		$this->set_product_limit( $course_id, 'fixed', '30', 'day' );
		$this->assertSame(
			'fixed',
			Limit::instance( $course_id )->limit_type,
			'前置條件：商品現況應已被改成 fixed'
		);

		$order->update_status( 'completed' );

		$this->assert_user_has_course_access( $this->customer_id, $course_id );

		// `Limit::calc_expire_date()` 對 unlimited 回傳 int 0，
		// 存進 pc_avl_coursemeta 後讀回來是字串 '0'（見 CourseLimitTest / OrderAutoGrantCourseTest）
		$expire_date = (string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' );

		$this->assertSame( '0', $expire_date, '快照為 unlimited，到期日應維持 0（永久）' );
	}

	// ========== 邊緣：缺快照 / 重複觸發 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 沒有快照時 fallback 商品現況：不 fatal、不變成永久。
	 *
	 * 為什麼要有這條：修復上線**前**建立的訂單、以及被 Issue #263 的 resume 分支
	 * 砍掉 itemmeta 的訂單，其 order item 上根本沒有 `_limit_*`。
	 * 若 `handle_single_course()` 只認快照而沒有 fallback，這些訂單會：
	 * - `from_order_item()` 回 null → 對 null 呼叫 `calc_expire_date()` → fatal，或
	 * - 退化成 `new Limit( '', null, null )` → `set_limit_type()` 把未知值收斂成 `unlimited`
	 * → 把限時課程免費送成永久課程。
	 *
	 * 這裡刻意把商品現況改成「60 天」（與被抹掉的 30 天快照不同數字），
	 * 讓「真的 fallback 讀了商品現況」與「其實快照還在」在數值上可區分 ——
	 * 否則兩者都回 +30 天，測試證明不了任何事。
	 *
	 * 修復前為什麼會紅：修復前根本沒有 `Limit::from_order_item()` 這支方法，
	 * `handle_single_course()` 也沒有 `?? Limit::instance()` 這條 fallback ——
	 * 本測試呼叫的 `Limit::from_order_item()` 是「呼叫不存在的靜態方法」，直接 fatal。
	 */
	public function test_無快照時fallback商品現況(): void {
		$course_id = $this->make_course_product( 'fallback課程', 'fixed', '30', 'day' );

		$order   = $this->make_order_with_snapshot( $course_id );
		$item_id = $this->get_only_item_id( $order );

		$this->assert_item_limit_snapshot( $item_id, 'fixed', '30', 'day' );

		// Given：把整組 limit 快照抹掉 = 修復上線前建立的訂單。
		//
		// 用 `wc_delete_order_item_meta()` 而非裸 `delete_metadata()`：前者才會一併
		// `WC_Cache_Helper::invalidate_cache_group( 'object_{item_id}' )`
		// （wc-order-item-functions.php），否則 `WC_Data::read_meta_data()`
		// 仍會從 cache 讀到已刪除的舊值，測試會假綠。
		//
		// 三個 key 全刪而不是只刪 `_limit_type`：真實的舊訂單三個都沒有，
		// 只刪一個等於在模擬一個現實不存在的狀態。
		foreach ( [ '_limit_type', '_limit_value', '_limit_unit' ] as $meta_key ) {
			\wc_delete_order_item_meta( $item_id, $meta_key );
		}

		$this->assertNull(
			Limit::from_order_item( $this->reload_item( $item_id ) ),
			'前置條件：快照應已完全不存在，from_order_item() 必須回 null'
		);

		// 商品現況改成 60 天（與被抹掉的 30 天快照刻意不同）
		$this->set_product_limit( $course_id, 'fixed', '60', 'day' );
		$this->assertSame(
			60,
			Limit::instance( $course_id )->limit_value,
			'前置條件：商品現況應已被改成 60 天'
		);

		// 重新取回訂單：手上這顆 $order 的 line item 是在刪 item meta **之前**讀進記憶體的，
		// `$order->save()` 時 `save_items()` 會拿那份舊快照去跑 `save_meta_data()`。
		// （實際上未變更的 meta 不會被重寫，但依賴這個實作細節等於在測 WooCommerce 內部行為。）
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );

		$order->update_status( 'completed' );

		// Then：仍然開通、仍然算得出到期日
		$this->assert_user_has_course_access( $this->customer_id, $course_id );

		$expire_date = (int) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' );

		$this->assertGreaterThan( 0, $expire_date, '缺快照不得退化成永久（expire_date = 0）' );
		$this->assertSame(
			$this->expected_fixed_expire_date( 60, 'day' ),
			$expire_date,
			'缺快照時應 fallback 讀商品現況（60 天）'
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * `Limit::from_order_item()` 的契約：非商品列 / 無快照回 null，有快照回實例。
	 *
	 * 這條是純契約測試（不建訂單），刻意與上面的端到端測試分開 ——
	 * 端到端測試紅的時候，這條能立刻回答「是 helper 壞了，還是接線壞了」。
	 *
	 * 修復前為什麼會紅：`Limit::from_order_item()` 不存在，
	 * 呼叫不存在的靜態方法在 PHP 8 是 `Error`，整條測試 fatal。
	 */
	public function test_from_order_item對非課程item回傳null(): void {
		// 1. 非 `WC_Order_Item_Product`（手續費列 / 運費列都繼承自 `WC_Order_Item`）。
		// 這道守門很重要：訂單上本來就混著這些列，
		// 對它們呼叫 `get_meta('_limit_type')` 不會爆，但語意上「它們沒有課程期限」。
		$this->assertNull(
			Limit::from_order_item( new \WC_Order_Item_Fee() ),
			'手續費列不是商品列，應回 null'
		);
		$this->assertNull(
			Limit::from_order_item( new \WC_Order_Item_Shipping() ),
			'運費列不是商品列，應回 null'
		);

		// 2. 是商品列，但身上沒有 `_limit_type` 快照（修復上線前的訂單 / 非課程商品）
		$bare_item = new \WC_Order_Item_Product();
		$this->assertNull(
			Limit::from_order_item( $bare_item ),
			'沒有 _limit_type 快照時應回 null，交由呼叫端 fallback'
		);

		// 3. 有 `_limit_type = unlimited` 快照 → 回實例，且 limit_type 正確。
		// `unlimited` 商品的 value / unit 是空字串（寫入端原樣寫入），
		// `from_order_item()` 的 `(int) '' ?: null` / `(string) '' ?: null` 應收斂成 null。
		$unlimited_item = new \WC_Order_Item_Product();
		$unlimited_item->update_meta_data( '_limit_type', 'unlimited' );
		$unlimited_item->update_meta_data( '_limit_value', '' );
		$unlimited_item->update_meta_data( '_limit_unit', '' );

		$unlimited_limit = Limit::from_order_item( $unlimited_item );

		$this->assertInstanceOf( Limit::class, $unlimited_limit, '有 _limit_type 快照時應回 Limit 實例' );
		$this->assertSame( 'unlimited', $unlimited_limit->limit_type );
		$this->assertNull( $unlimited_limit->limit_value, 'unlimited 的 limit_value 應收斂成 null' );
		$this->assertNull( $unlimited_limit->limit_unit, 'unlimited 的 limit_unit 應收斂成 null' );
		$this->assertSame( 0, $unlimited_limit->calc_expire_date( null ), 'unlimited 快照應算出 0（永久）' );

		// 4. 有 `fixed / 30 / day` 快照 → 三個欄位都要正確還原。
		// 只驗 limit_type 不夠：value / unit 讀錯（例如 `_limit_value` 拿成 0）
		// 會讓 `calc_expire_date()` 算出 `strtotime('+ day')` 這種垃圾值。
		$fixed_item = new \WC_Order_Item_Product();
		$fixed_item->update_meta_data( '_limit_type', 'fixed' );
		$fixed_item->update_meta_data( '_limit_value', '30' );
		$fixed_item->update_meta_data( '_limit_unit', 'day' );

		$fixed_limit = Limit::from_order_item( $fixed_item );

		$this->assertInstanceOf( Limit::class, $fixed_limit );
		$this->assertSame( 'fixed', $fixed_limit->limit_type );
		$this->assertSame( 30, $fixed_limit->limit_value );
		$this->assertSame( 'day', $fixed_limit->limit_unit );
		$this->assertSame(
			$this->expected_fixed_expire_date( 30, 'day' ),
			$fixed_limit->calc_expire_date( null ),
			'fixed 快照應算出 +30 天的到期日'
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 同一張訂單重複觸發授權，到期日不得漂移。
	 *
	 * 為什麼要有這條：`add_meta_to_avl_course()` 掛在
	 * `Grant::grant_statuses()`（= course_access_trigger + completed）的**每個**狀態上，
	 * 所以一張正常訂單本來就會跑不只一次；站長手動改單狀態、續付、
	 * 後台重跑，都會再觸發。而 `LifeCycle::add_student_to_course()` 對 `expire_date`
	 * 是無條件覆寫 —— 只要每次都重算，學員的到期日就會隨商品設定漂移。
	 *
	 * 修復前為什麼會紅：兩次呼叫都走 `Limit::instance( $product_id )`，
	 * 中間改過商品設定後第二次算出 +365 天，`assertSame( $first, $second )` 必紅。
	 * （修復後兩次都讀同一份快照 —— `fixed` 的到期日會被
	 * `calc_expire_date()` 收斂到「當天 15:59:00」，同一天內重跑值完全相同。）
	 */
	public function test_重複觸發授權到期日不漂移(): void {
		$course_id = $this->make_course_product( '不漂移課程', 'fixed', '30', 'day' );

		$order    = $this->make_order_with_snapshot( $course_id );
		$order_id = (int) $order->get_id();

		$this->assert_item_limit_snapshot( $this->get_only_item_id( $order ), 'fixed', '30', 'day' );

		// 第 1 次：走真的狀態轉換 hook
		$order->update_status( 'completed' );

		$first_expire_date = (string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' );
		$this->assertSame(
			(string) $this->expected_fixed_expire_date( 30, 'day' ),
			$first_expire_date,
			'第一次授權應以快照（30 天）計算'
		);

		// 站長中途把商品期限改成 365 天
		$this->set_product_limit( $course_id, 'fixed', '365', 'day' );
		$this->assertSame(
			365,
			Limit::instance( $course_id )->limit_value,
			'前置條件：商品現況應已被改成 365 天'
		);

		// 第 2 次：直接呼叫。
		// 訂單狀態已經是 completed，沒辦法再轉換一次到同一個狀態，
		// 所以用直接呼叫模擬「trigger 與 completed 各觸發一次」的第二次。
		OrderResource::instance()->add_meta_to_avl_course( $order_id );

		$second_expire_date = (string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' );

		$this->assertSame(
			$first_expire_date,
			$second_expire_date,
			'重複觸發授權時，到期日不得因商品設定被改而漂移'
		);
	}

	// ========== fixture ==========

	/**
	 * 建立課程商品（`_is_course = yes`）並設定期限
	 *
	 * @param string $title       商品標題
	 * @param string $limit_type  限制類型 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	 * @param string $limit_value 限制值（unlimited 時給空字串）
	 * @param string $limit_unit  限制單位 'day' | 'month' | 'year' | 'timestamp'（unlimited 時給空字串）
	 * @return int 商品 ID
	 */
	private function make_course_product( string $title, string $limit_type, string $limit_value = '', string $limit_unit = '' ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $title . '_' . uniqid() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '1000' );
		$product->save();

		$product_id = (int) $product->get_id();

		\update_post_meta( $product_id, '_is_course', 'yes' );
		\update_post_meta( $product_id, 'limit_type', $limit_type );
		\update_post_meta( $product_id, 'limit_value', $limit_value );
		\update_post_meta( $product_id, 'limit_unit', $limit_unit );

		$this->flush_product_meta_cache( $product_id );

		return $product_id;
	}

	/**
	 * 改寫商品的期限設定（模擬站長事後修改）
	 *
	 * ⚠️ 刻意走 WC 的 `update_meta_data()` + `save()`，**不是**裸 `update_post_meta()`。
	 * `WC_Data::save_meta_data()` 結尾會 `wp_cache_delete()` 掉
	 * `{cache_group}...object_meta_{id}` 這個 key；裸 `update_post_meta()` 不會，
	 * 於是本測試稍早已經讀過一次商品（建單 / 寫入端都會 `wc_get_product()`）之後，
	 * `Limit::instance()` 會拿到**改之前**的舊值 —— 那會讓「到期日 = 30 天」
	 * 變成一句廢話（商品現況根本沒變）。
	 *
	 * 每個呼叫點後面都跟著一條「商品現況真的變了」的前置斷言，
	 * 就是為了讓這種快取失效問題以紅燈而非假綠現形。
	 *
	 * @param int    $product_id  商品 ID
	 * @param string $limit_type  限制類型
	 * @param string $limit_value 限制值
	 * @param string $limit_unit  限制單位
	 * @return void
	 */
	private function set_product_limit( int $product_id, string $limit_type, string $limit_value = '', string $limit_unit = '' ): void {
		$product = \wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product::class, $product, "找不到商品 {$product_id}" );

		$product->update_meta_data( 'limit_type', $limit_type );
		$product->update_meta_data( 'limit_value', $limit_value );
		$product->update_meta_data( 'limit_unit', $limit_unit );
		$product->save();

		$this->flush_product_meta_cache( $product_id );
	}

	/**
	 * 清掉商品的 WC meta 物件快取
	 *
	 * `WC_Data::read_meta_data()` 會把整包 meta 存進 `products` cache group，
	 * key 為 `WC_Data::generate_meta_cache_key()`
	 * （= `get_cache_prefix('products') . get_cache_prefix("object_{$id}") . "object_meta_{$id}"`）。
	 * `clean_post_cache()` 清的是 WP 的 `post_meta` 群組、
	 * `wc_delete_product_transients()` 清的是 `product_{$id}` 群組 ——
	 * **兩者都碰不到上面那個 key**。
	 * 唯一能讓它失效的是把 `object_{$id}` 這個群組的 prefix 推進一版。
	 *
	 * @param int $product_id 商品 ID
	 * @return void
	 */
	private function flush_product_meta_cache( int $product_id ): void {
		\clean_post_cache( $product_id );
		\wc_delete_product_transients( $product_id );
		\WC_Cache_Helper::invalidate_cache_group( 'object_' . $product_id );
	}

	/**
	 * 建立一張含指定商品、且 `_limit_*` 快照已落地的訂單
	 *
	 * ⚠️ 這裡觸發的是 `woocommerce_checkout_order_processed` 而不是
	 * `woocommerce_new_order`，理由有二：
	 * 1. `wc_create_order()` 的 `save()` 會 fire 一次 `woocommerce_new_order`，
	 * 但那時訂單還沒有任何 line item；之後的 `add_product()` + `save()` 走 data store
	 * `update()`（前狀態 pending，非 draft 系列）→ fire 的是 `woocommerce_update_order`，
	 * **不會**再 fire `woocommerce_new_order`。也就是說沒有人補 fire 的話，快照根本不會寫入。
	 * 2. `woocommerce_checkout_order_processed`（class-wc-checkout.php:1352）
	 * 是傳統結帳新單與 resume 單都會走到的 hook，此時 items 已落地、具真實
	 * `order_item_id`，`save_meta_data()` 才真的寫得進 DB。
	 *
	 * 給下一位維護者：不要「順手」把這裡改成手工
	 * `do_action( 'woocommerce_new_order', ... )` —— repo 內既有的端到端測試
	 * 就是這樣把「這個 hook 一定會 fire」寫死成前提，才讓 Issue #263 躲過所有測試出貨。
	 *
	 * @param int $product_id 商品 ID
	 * @return \WC_Order 重新從 DB 取回的訂單
	 */
	private function make_order_with_snapshot( int $product_id ): \WC_Order {
		$order = \wc_create_order( [ 'customer_id' => $this->customer_id ] );
		$this->assertNotWPError( $order, 'wc_create_order 失敗' );

		$product = \wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product::class, $product, "找不到商品 {$product_id}" );

		$order->add_product( $product, 1 );
		$order->save();

		$order_id = (int) $order->get_id();

		\do_action( 'woocommerce_checkout_order_processed', $order_id, [], \wc_get_order( $order_id ) );

		$order = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $order, "重新讀取訂單 {$order_id} 失敗" );

		return $order;
	}

	// ========== 查詢 / 斷言 helper ==========

	/**
	 * 取得訂單唯一 line item 的 ID
	 *
	 * @param \WC_Order $order 訂單
	 * @return int order_item_id
	 */
	private function get_only_item_id( \WC_Order $order ): int {
		$items = $order->get_items();
		$this->assertCount( 1, $items, '此 fixture 預期訂單只有一列 line item' );

		$item = \reset( $items );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		$item_id = (int) $item->get_id();
		$this->assertGreaterThan( 0, $item_id, 'line item 應已落地並取得 order_item_id' );

		return $item_id;
	}

	/**
	 * 從 DB 重新取得 order item（繞開手上舊物件的 meta 快照）
	 *
	 * @param int $item_id order_item_id
	 * @return \WC_Order_Item_Product
	 */
	private function reload_item( int $item_id ): \WC_Order_Item_Product {
		$item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item, "重新讀取 order item {$item_id} 失敗" );

		return $item;
	}

	/**
	 * 斷言 order item 上的 `_limit_*` 下單快照
	 *
	 * @param int    $item_id     order_item_id
	 * @param string $limit_type  期望的 `_limit_type`
	 * @param string $limit_value 期望的 `_limit_value`
	 * @param string $limit_unit  期望的 `_limit_unit`
	 * @return void
	 */
	private function assert_item_limit_snapshot( int $item_id, string $limit_type, string $limit_value, string $limit_unit ): void {
		$item = $this->reload_item( $item_id );

		$this->assertSame( $limit_type, (string) $item->get_meta( '_limit_type' ), '前置條件：_limit_type 快照應已落地' );
		$this->assertSame( $limit_value, (string) $item->get_meta( '_limit_value' ), '前置條件：_limit_value 快照應已落地' );
		$this->assertSame( $limit_unit, (string) $item->get_meta( '_limit_unit' ), '前置條件：_limit_unit 快照應已落地' );
	}

	/**
	 * 算出 `fixed` 類型應有的到期日 timestamp
	 *
	 * 與 `Limit::calc_expire_date()` 的算法逐字對齊：先 `strtotime('+N unit')`，
	 * 再把時間部分固定成當天的 15:59:00。
	 *
	 * 註：極小機率會在「production 算完 → 測試算期望值」之間跨過午夜而誤紅，
	 * 這是 repo 內 `CourseLimitTest` 既有的取捨（換來精確的 assertSame 而非鬆散的範圍斷言）。
	 *
	 * @param int    $limit_value 限制值
	 * @param string $limit_unit  限制單位
	 * @return int 到期日 timestamp
	 */
	private function expected_fixed_expire_date( int $limit_value, string $limit_unit ): int {
		$timestamp = (int) strtotime( "+{$limit_value} {$limit_unit}" );

		return (int) strtotime( date( 'Y-m-d', $timestamp ) . ' 15:59:00' );
	}

	/**
	 * 清空 WooCommerce 訂單項目表（測試隔離）
	 *
	 * `wp_woocommerce_order_items` / `order_itemmeta` 的殘留列會被「重複使用相同 order_id」
	 * 的新訂單撿走，讓 `wc_create_order()` 一建立就帶著上一輪測試的 item ——
	 * 本檔所有「訂單只有一列 line item」的前置條件會隨執行順序飄移，
	 * 單獨跑綠、整包跑紅，是最難查的那種假訊號。
	 *
	 * 放在 `set_up()` 而非 `tear_down()`：前一個**測試檔**留下的殘留也要清掉。
	 */
	private function truncate_wc_order_item_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

}
