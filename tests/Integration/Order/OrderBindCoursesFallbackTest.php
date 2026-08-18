<?php
/**
 * Issue #263 補強修（B/C/D）整合測試 —— `_bind_courses_data` 缺失時的 fallback / self-heal / 髒資料防守
 *
 * 背景：WooCommerce 傳統結帳的 resume 分支（`class-wc-checkout.php:401-413`）在重試付款時
 * 會先 `remove_order_items()` 再依購物車重建 line item，且走 data store `update()`
 * **不再觸發** `woocommerce_new_order` —— 於是 power-course 寫在該 hook 的
 * `_bind_courses_data` item meta 與「銷售方案內含商品 order item」全部遺失，
 * 課程不開通、通知信不寄，而後台 / MCP 卻回報「這張訂單沒有課程」。
 *
 * 本檔覆蓋修復計畫 §5-2 的 T8-T12（`specs/plans/issue-263-resume-checkout-bind-courses-data.md`）：
 * 主線 resume 情境（T1-T7）在 `OrderResumeCheckoutTest.php`，此處專測三支補強修：
 *   B. `Order::get_item_bind_courses_data()` 的 item meta → 商品現況 fallback + self-heal
 *   C. `Order\Service\Query::get_with_courses()` / `OrderGrantCoursesTool` 不再說謊
 *   D. `BindCoursesData` 逐列防守（髒資料不再中斷整張訂單的授權）
 *
 * @group order
 * @group bundle
 * @group issue-263
 * @group happy
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Order;

use Tests\Integration\Mcp\IntegrationTestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Order as OrderResource;
use J7\PowerCourse\Resources\Order\Service\Query as OrderQuery;
use J7\PowerCourse\Api\Mcp\Tools\Order\OrderGrantCoursesTool;

/**
 * Class OrderBindCoursesFallbackTest
 * 驗證 order item meta 遺失後，讀取端仍能從商品現況取回綁定課程，且髒資料不會中斷授權
 *
 * 為何繼承 `Mcp\IntegrationTestCase` 而非 `Tests\Integration\TestCase`：
 * T10 要跑真正的 `OrderGrantCoursesTool::run()`，而 `run()` 前有兩道閘門 ——
 * `manage_woocommerce` capability 與 `pc_mcp_settings` 的 `allow_update` 旗標（預設 false），
 * 且 `ActivityLogger` 會寫入 MCP 自訂表。這三件事只有 `Mcp\IntegrationTestCase`
 * 的 `set_up()`（`create_admin_user()` / `allow_mcp_write_operations()` / `ensure_mcp_tables_exist()`）
 * 已備妥；自己在這裡重造一份等於複製一份會腐爛的設定。
 *
 * @group order
 * @group bundle
 * @group issue-263
 * @group happy
 * @group edge
 */
class OrderBindCoursesFallbackTest extends IntegrationTestCase {

	/**
	 * Order item meta key —— 下單當時的綁定課程快照
	 *
	 * 刻意用字面量而非常數：production 端（`Order::_handle_add_course_item_meta_by_order_item()`）
	 * 也是字面量。若哪天有人改了 production 的 key 卻沒改這裡，這支測試就該紅。
	 *
	 * @var string
	 */
	private const ITEM_META_KEY = '_bind_courses_data';

	/**
	 * 「訂單內沒有課程商品可授權。」—— `OrderGrantCoursesTool` 在 granted_count = 0 時的說謊訊息
	 *
	 * @var string
	 */
	private const NO_COURSE_MESSAGE = '訂單內沒有課程商品可授權。';

	/**
	 * 買家用戶 ID
	 *
	 * @var int
	 */
	private int $buyer_id;

	/**
	 * 每個測試前建立買家
	 */
	public function set_up(): void {
		parent::set_up();

		$this->truncate_wc_order_item_tables();

		$this->buyer_id = $this->factory()->user->create(
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
	 * 而本檔的授權流程會走到 `AccessPass\Service\Grant::on_order_completed()`。
	 * 照 `PurchaseGrantAccessPassTest::tear_down()` 的既有範本自行補清。
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	// ========== T8：fallback + self-heal ==========

	/**
	 * @test
	 * @group happy
	 * T8：item meta 缺失時，`get_item_bind_courses_data()` 應 fallback 讀商品現況並自癒回寫
	 *
	 * 修復前為什麼紅：舊版 `add_meta_to_avl_course()` / `handle_bind_courses()` 直接
	 * `$item->get_meta('_bind_courses_data')`，item meta 一旦被 resume 清掉就是空陣列 ——
	 * 「不是課程商品 && 沒有綁定課程」的 guard 直接 `continue`，一圈都不跑、也不報錯。
	 * 那時根本沒有 `get_item_bind_courses_data()` 這支 helper，本測試連 fatal 都算不上，
	 * 是「呼叫不存在的靜態方法」。
	 */
	public function test_item_meta缺失時fallback讀商品現況並self_heal(): void {
		$course_id  = $this->create_wc_course( [ 'post_title' => '被綁定的課程' ] );
		$product_id = $this->make_bind_product( [ $this->bind_row( $course_id ) ] );

		$order   = $this->make_victim_order( $product_id );
		$item_id = $this->get_only_item_id( $order );

		// Given：受害訂單的 item meta 已經不見（precondition 必須真的成立，
		// 否則後面測到的只是「快照還在」，等於空轉綠燈）
		$victim = $this->reload_item( $item_id );
		$this->assertEmpty(
			$victim->get_meta( self::ITEM_META_KEY ),
			'前置條件：受害訂單的 _bind_courses_data item meta 應已遺失'
		);

		// When：透過單一真相來源的 helper 讀取
		// 第三參數 allow_fallback = true：fallback 預設關閉（見 helper docblock），
		// 只有人工修復路徑才開。自動授權路徑刻意不開，避免回溯授課。
		$result = OrderResource::get_item_bind_courses_data( $victim, true, true );

		// Then：fallback 讀到商品現況
		$this->assertNotEmpty( $result, 'item meta 缺失時應 fallback 讀商品現況的 bind_courses_data' );
		$this->assertSame( $course_id, (int) $result[0]['id'], 'fallback 讀到的課程應為商品現況綁定的課程' );

		// And：self-heal 已把快照回寫進 DB（下次重跑拿到同一份，站長中途換課也不會漂移）
		$healed = $this->reload_item( $item_id )->get_meta( self::ITEM_META_KEY );
		$this->assertIsArray( $healed, 'self-heal 應把 bind_courses_data 回寫成 item meta' );
		$this->assertSame( $course_id, (int) $healed[0]['id'], '回寫的內容應與 fallback 讀到的一致' );
	}

	/**
	 * @test
	 * @group happy
	 * T8b：`$self_heal = false` 時只讀不寫（`Query` service 的 read-only 契約）
	 *
	 * 修復前為什麼紅：同上，helper 不存在。
	 * 這條把守的是「read-only service 不得產生副作用」—— `Order\Service\Query::get_with_courses()`
	 * 是 MCP `order_get` 的底層，AI Agent 只是「看一眼訂單」不該順手改資料。
	 */
	public function test_self_heal關閉時不得回寫item_meta(): void {
		$course_id  = $this->create_wc_course( [ 'post_title' => '唯讀情境的課程' ] );
		$product_id = $this->make_bind_product( [ $this->bind_row( $course_id ) ] );

		$order   = $this->make_victim_order( $product_id );
		$item_id = $this->get_only_item_id( $order );

		// When：關閉 self-heal
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ), false, true );

		// Then：仍讀得到（不影響讀取正確性）
		$this->assertNotEmpty( $result, '關閉 self-heal 不應影響 fallback 讀取' );
		$this->assertSame( $course_id, (int) $result[0]['id'] );

		// And：DB 不得被寫入
		$this->assertEmpty(
			$this->reload_item( $item_id )->get_meta( self::ITEM_META_KEY ),
			'$self_heal = false 時不得回寫 item meta（read-only 契約）'
		);
	}

	// ========== T9：MCP order_get ==========

	/**
	 * @test
	 * @group happy
	 * T9：fallback 生效後，MCP `order_get` 的 courses 清單不再是空的
	 *
	 * 修復前為什麼紅：`Query::get_with_courses()` 只讀 item meta，受害訂單一律
	 * `continue` 掉每一個 item，`courses` 回傳空陣列 —— AI Agent 會被告知
	 * 「這張訂單沒有任何課程」，於是連「該補開通」都判斷不出來。
	 */
	public function test_fallback後MCP_order_get回傳課程清單非空(): void {
		$course_id  = $this->create_wc_course( [ 'post_title' => 'MCP 讀得到的課程' ] );
		$product_id = $this->make_bind_product( [ $this->bind_row( $course_id ) ] );

		$order    = $this->make_victim_order( $product_id );
		$order_id = $order->get_id();
		$item_id  = $this->get_only_item_id( $order );

		// When
		$payload = OrderQuery::get_with_courses( $order_id );

		// Then
		$this->assertNotWPError( $payload, 'MCP order_get 不應回傳錯誤' );
		$this->assertIsArray( $payload );
		$this->assertNotEmpty( $payload['courses'], 'fallback 後 courses 不應為空' );

		$first = $payload['courses'][0];
		$this->assertSame( $product_id, (int) $first['product_id'] );
		$this->assertNotEmpty( $first['bind_courses_data'], 'courses 內的 bind_courses_data 不應為空' );
		$this->assertSame( $course_id, (int) $first['bind_courses_data'][0]['id'] );

		// And：read-only —— 讀一次訂單不應把快照寫回去
		$this->assertEmpty(
			$this->reload_item( $item_id )->get_meta( self::ITEM_META_KEY ),
			'Query::get_with_courses() 是 read-only，不應產生 self-heal 副作用'
		);
	}

	// ========== T10：MCP order_grant_courses ==========

	/**
	 * @test
	 * @group happy
	 * T10：`order_grant_courses` 對 resume 受害訂單應先修復再統計，回報正確的 granted_count
	 *
	 * 情境重現：受害訂單只剩「母方案」一列 line item，方案內含商品完全沒被展開
	 * （resume 依購物車重建 items，而購物車裡只有母方案）。
	 *
	 * 修復前為什麼紅：tool 直接統計現有 items —— 母方案本身不是課程商品、也沒有
	 * bind_courses_data，於是 `granted_count = 0`、訊息回「訂單內沒有課程商品可授權。」，
	 * 而它呼叫的 `add_meta_to_avl_course()` 同樣什麼都不會授予。
	 * 那是一句 100% 的謊話：訂單裡明明買了含課程的方案。
	 */
	public function test_order_grant_courses對resume訂單回報正確granted_count(): void {
		$course_id = $this->create_wc_course( [ 'post_title' => '方案內含的課程' ] );
		$bundle_id = $this->create_wc_bundle_with_products( $course_id, [ $course_id ] );

		// Given：只有母方案一列的受害訂單（刻意不 fire `woocommerce_new_order`，
		// 因為 resume 分支走 data store update()，該 hook 根本不會觸發）
		$order = $this->create_order( $bundle_id );
		$this->assertCount( 1, $order->get_items(), '前置條件：受害訂單應只有母方案一列' );

		// 訂單必須處於授權狀態：tool 有狀態閘門（Grant::grant_statuses()），
		// 避免對已取消 / 已退款 / 從未付款的訂單授課。
		// 這次狀態轉換本身不會授課（母方案不是課程商品、也沒有 bind 快照），
		// 受害狀態維持不變。
		$order->update_status( 'completed' );
		$this->assert_user_has_no_course_access( $this->buyer_id, $course_id );

		// tool 需要 manage_woocommerce；基底類別只備妥 MCP 表與寫入旗標，不設定當前使用者
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		// When
		$tool   = new OrderGrantCoursesTool();
		$result = $tool->run( [ 'order_id' => $order->get_id() ] );

		// Then
		$this->assertIsArray( $result, 'tool 不應回傳 WP_Error（權限 / 旗標由基底類別備妥）' );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, (int) $result['granted_count'], '修復後應統計到可授權的課程 item' );
		$this->assertNotSame(
			self::NO_COURSE_MESSAGE,
			$result['message'],
			'訂單買了含課程的方案，訊息不該說「沒有課程商品可授權」'
		);

		// And：方案內含商品真的被補回訂單，且課程真的開通
		$this->assertCount(
			2,
			\wc_get_order( $order->get_id() )->get_items(),
			'repair_order_items() 應把方案內含商品補成 order item（母方案 + 內含課程 = 2 列）'
		);
		$this->assert_user_has_course_access( $this->buyer_id, $course_id );
	}

	// ========== T11：髒 bind_courses_data ==========

	/**
	 * @test
	 * @group edge
	 * T11：`bind_courses_data` 中的髒列只該被跳過，不該中斷整張訂單的授權
	 *
	 * 修復前為什麼紅：`BindCoursesData::__construct()` 直接把每一列丟給 `BindCourseData`，
	 * 而 `BindCourseData` 在 `course_id` 為 0 時 `throw new \Exception`。
	 * 髒列排在第一位 → 例外在迴圈第一圈就拋出 → `handle_bind_courses()` 中止 →
	 * `add_meta_to_avl_course()` 中止（`$add_student->do_action()` 根本沒跑到）→
	 * 第二列那門完全乾淨的課程也一起陪葬。
	 *
	 * 而且死得很安靜：`WC_Order::status_transition()` 只 catch `\Exception`
	 * （`class-wc-order.php:431`）並寫進 WC log，訂單狀態照樣變成 completed ——
	 * 站長看到「訂單已完成」卻沒開通，完全無跡可循。
	 * 所以本測試的重點斷言是「第二列的課程仍被授予」，而不是「狀態有沒有變成 completed」。
	 */
	public function test_髒bind_courses_data不會中斷授權(): void {
		// 課程商品本身的 limit 不影響本測試 —— 綁定課程的期限以 bind_courses_data 那一列為準
		$course_id = $this->create_wc_course( [ 'post_title' => '排在髒列後面的乾淨課程' ] );

		// 第一列刻意缺 id（站長存過舊格式 / 半殘資料的真實形狀）
		$dirty_rows = [
			[ 'limit_type' => 'fixed' ],
			[
				'id'          => $course_id,
				'limit_type'  => 'fixed',
				'limit_value' => 30,
				'limit_unit'  => 'day',
			],
		];

		$product_id = $this->make_bind_product( $dirty_rows );

		$order    = $this->create_order( $product_id );
		$order_id = $order->get_id();

		// 正常建單路徑：item meta 會被寫入（內容就是那份髒資料）
		\do_action( 'woocommerce_new_order', $order_id, $order );

		$order = \wc_get_order( $order_id );
		$this->assertNotEmpty(
			$this->get_only_item( $order )->get_meta( self::ITEM_META_KEY ),
			'前置條件：item meta 應已寫入那份髒資料'
		);

		// When：走完整的訂單狀態轉換（授權 hook 掛在 `woocommerce_order_status_completed`）
		$fired_before = \did_action( 'woocommerce_order_status_completed' );
		$order->update_status( 'completed' );

		// Then：狀態轉換完整跑完
		// （`did_action` 是全 process 累計值，直接斷言 > 0 會被別的測試餵飽 ——
		// 故改比對本次的增量，才是真的「這一張訂單的 hook 有跑」）
		$this->assertSame(
			$fired_before + 1,
			\did_action( 'woocommerce_order_status_completed' ),
			'woocommerce_order_status_completed 應恰好為此訂單觸發一次'
		);
		$this->assert_action_fired( 'woocommerce_order_status_completed' );
		$this->assertSame( 'completed', \wc_get_order( $order_id )->get_status() );

		// And：髒列被跳過，乾淨的第二列仍被授予
		$this->assert_user_has_course_access( $this->buyer_id, $course_id );

		// And：不是只寫了 avl_course_ids —— limit 也照第二列算出了到期日
		$expire_date = (int) $this->get_course_meta( $course_id, $this->buyer_id, 'expire_date' );
		$this->assertGreaterThan(
			time(),
			$expire_date,
			'第二列的 limit（fixed 30 day）應被完整套用，證明它真的走完整條授權流程'
		);
	}

	// ========== T12：變體商品的 fallback 解析順序 ==========

	/**
	 * @test
	 * @group edge
	 * T12：fallback 解析商品時，變體（variation）優先於主商品
	 *
	 * 解析順序必須與寫入端（`_handle_add_course_item_meta_by_order_item()` 的
	 * `get_variation_id() ?: get_product_id()`）以及 `AccessPass\Grant::get_item_pass_id()`
	 * 一致，否則同一張訂單「寫進去的」與「讀出來的」會是兩門不同的課。
	 *
	 * 修復前為什麼紅：helper 不存在；而唯一的讀取方式（item meta）在受害訂單上是空的，
	 * 根本走不到「該讀哪一個商品」這一步。
	 */
	public function test_變體商品的fallback以variation優先(): void {
		$parent_course_id    = $this->create_wc_course( [ 'post_title' => '主商品綁的課程' ] );
		$variation_course_id = $this->create_wc_course( [ 'post_title' => '變體綁的課程' ] );

		$parent_id    = $this->make_variable_product();
		$variation_id = $this->make_variation( $parent_id );

		// 主商品與變體各綁不同課程 —— 只有解析順序正確才分得出來
		\update_post_meta( $parent_id, 'bind_courses_data', [ $this->bind_row( $parent_course_id ) ] );
		\update_post_meta( $variation_id, 'bind_courses_data', [ $this->bind_row( $variation_course_id ) ] );

		$order   = $this->create_order_with_variation_item( $parent_id, $variation_id );
		$item_id = $this->get_only_item_id( $order );

		// When：item meta 從未寫入（刻意不 fire `woocommerce_new_order`），必走 fallback
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ), true, true );

		// Then
		$this->assertNotEmpty( $result );
		$this->assertSame(
			$variation_course_id,
			(int) $result[0]['id'],
			'變體有自己的 bind_courses_data 時，應以變體為準（variation_id ?: product_id）'
		);
	}

	/**
	 * @test
	 * @group edge
	 * T12b：變體沒有綁定時，fallback **不得**往上查主商品（必須與寫入端對稱）
	 *
	 * 寫入端（`_handle_add_course_item_meta_by_order_item()`）是
	 * `$product_id = get_variation_id() ?: get_product_id()` 之後只讀**那一個 id** 的
	 * `bind_courses_data`，不查母商品。fallback 若多查一層母商品，就會授予
	 * 「原本下單當下根本不會被授予」的課程 —— fallback 的職責是**還原**遺失的快照，
	 * 不是**創造**新的授權。
	 *
	 * 註：本專案的後台只把 `bind_courses_data` 寫在商品層、不寫變體
	 * （`Api/Product.php` 無任何變體寫入路徑），所以「變體商品 + 主商品層綁定」
	 * 在寫入端本來就拿不到綁定。那是另一個獨立議題，不在 #263 範圍內。
	 */
	public function test_變體無綁定時fallback不得查主商品(): void {
		$parent_course_id = $this->create_wc_course( [ 'post_title' => '只有主商品綁的課程' ] );

		$parent_id    = $this->make_variable_product();
		$variation_id = $this->make_variation( $parent_id );

		// 只有主商品有綁定，變體刻意留白
		\update_post_meta( $parent_id, 'bind_courses_data', [ $this->bind_row( $parent_course_id ) ] );
		$this->assertEmpty(
			\get_post_meta( $variation_id, 'bind_courses_data', true ),
			'前置條件：變體不應有自己的 bind_courses_data'
		);

		$order   = $this->create_order_with_variation_item( $parent_id, $variation_id );
		$item_id = $this->get_only_item_id( $order );

		// When
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ), true, true );

		// Then：與寫入端一致 —— 變體沒綁就是沒綁
		$this->assertSame( [], $result, 'fallback 的商品解析規則必須與寫入端對稱，不得多查母商品' );
	}

	// ========== fallback 閘門（Issue #263 的安全邊界）==========

	/**
	 * @test
	 * @group happy
	 * fallback 預設關閉：自動授權路徑不得回溯授課
	 *
	 * 為什麼這條比 T8 更重要：寫入端只在「商品有綁定課程」時才寫 `_bind_courses_data`，
	 * 所以「被 resume 砍掉的 item」與「下單當時本來就沒綁課程的正常 item」在 DB 上
	 * 無法區分。若 fallback 預設開啟，站長「後來才把課程綁到某商品」時，
	 * 該商品**所有舊訂單**只要再次進入 trigger 狀態就會把課程送給從未購買的舊買家。
	 */
	public function test_fallback預設關閉(): void {
		$course_id  = $this->create_wc_course( [ 'post_title' => '不該被回溯授予的課程' ] );
		$product_id = $this->make_bind_product( [ $this->bind_row( $course_id ) ] );

		$order   = $this->make_victim_order( $product_id );
		$item_id = $this->get_only_item_id( $order );

		// When：不傳第三參數（= 自動授權路徑的用法）
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ) );

		// Then
		$this->assertSame( [], $result, 'fallback 必須預設關閉，只有人工修復路徑才開' );
	}

	/**
	 * @test
	 * @group edge
	 * 帶有「已處理」戳記的 item 不得 fallback
	 *
	 * item 上只要有任何一絲寫入端留下的痕跡（`_pc_item_processed` /
	 * `_is_course` / `_limit_type`），就證明寫入端當時確實跑過，
	 * 此時 `_bind_courses_data` 缺席是「當時沒有綁定課程」的**權威證據**，
	 * 不是遺失，不該被商品現況覆蓋。
	 */
	public function test_已處理戳記的item不得fallback(): void {
		$course_id  = $this->create_wc_course( [ 'post_title' => '事後才綁上的課程' ] );
		$product_id = $this->make_bind_product( [ $this->bind_row( $course_id ) ] );

		$order   = $this->make_victim_order( $product_id );
		$item_id = $this->get_only_item_id( $order );

		// Given：item 帶著「寫入端跑過」的戳記，但沒有 bind_courses_data
		//        （= 下單當時這個商品還沒綁課程）
		$item = $this->reload_item( $item_id );
		$item->update_meta_data( '_pc_item_processed', 'yes' );
		$item->save_meta_data();

		// When：即使明確開啟 fallback
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ), true, true );

		// Then：閘門擋下，不得回溯授課
		$this->assertSame( [], $result, '有處理戳記時 _bind_courses_data 缺席是權威證據，不得 fallback' );
	}

	// ========== 回傳值正規化 ==========

	/**
	 * @test
	 * @group edge
	 * helper 的 `array_values()` 正規化：DB 存成帶字串 key 的關聯陣列時，回傳仍須是 list
	 *
	 * 修復前為什麼紅：helper 不存在；而各呼叫端各自 `$item->get_meta()` 後直接 `foreach`，
	 * 拿到的是帶字串 key 的關聯陣列 —— `foreach` 本身沒事，但下游任何
	 * `$data[0]` / `array_slice` / `wp_json_encode`（會變成 JSON object 而非 array）
	 * 的假設都會悄悄錯掉。統一在 helper 收斂成 list 才有一致語義。
	 */
	public function test_關聯陣列的bind_courses_data會被正規化為list(): void {
		$course_a = $this->create_wc_course( [ 'post_title' => '關聯陣列課程 A' ] );
		$course_b = $this->create_wc_course( [ 'post_title' => '關聯陣列課程 B' ] );

		$product_id = $this->make_bind_product( [ $this->bind_row( $course_a ) ] );
		$order      = $this->create_order( $product_id );
		$item_id    = $this->get_only_item_id( $order );

		// Given：item meta 存成帶字串 key 的關聯陣列（DB 裡真的可能長這樣）
		$item = $this->reload_item( $item_id );
		$item->update_meta_data(
			self::ITEM_META_KEY,
			[
				'row_a' => $this->bind_row( $course_a ),
				'row_b' => $this->bind_row( $course_b ),
			]
		);
		$item->save_meta_data();

		// When
		$result = OrderResource::get_item_bind_courses_data( $this->reload_item( $item_id ) );

		// Then：key 被正規化為連續整數
		$this->assertSame( [ 0, 1 ], array_keys( $result ), 'helper 應以 array_values() 把關聯陣列收斂成 list' );
		$this->assertSame( $course_a, (int) $result[0]['id'] );
		$this->assertSame( $course_b, (int) $result[1]['id'] );
	}

	// ========== Fixtures ==========

	/**
	 * 產生一筆 bind_courses_data 列
	 *
	 * @param int    $course_id   課程 ID
	 * @param string $limit_type  限制類型
	 * @param int    $limit_value 限制值
	 * @param string $limit_unit  限制單位
	 * @return array{id:int,name:string,limit_type:string,limit_value:int,limit_unit:string}
	 */
	private function bind_row( int $course_id, string $limit_type = 'fixed', int $limit_value = 30, string $limit_unit = 'day' ): array {
		return [
			'id'          => $course_id,
			'name'        => (string) \get_the_title( $course_id ),
			'limit_type'  => $limit_type,
			'limit_value' => $limit_value,
			'limit_unit'  => $limit_unit,
		];
	}

	/**
	 * 建立一個「本身不是課程、但綁定課程權限」的商品
	 *
	 * 刻意不設 `bundle_type`，避免走進銷售方案的展開分支 ——
	 * 本檔測的是 bind_courses_data 這條獨立路徑。
	 *
	 * @param array<int, mixed> $bind_rows bind_courses_data 內容（可含髒列）
	 * @return int 商品 ID
	 */
	private function make_bind_product( array $bind_rows ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( '綁課程權限商品_' . uniqid() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '999' );
		$product->save();

		$product_id = $product->get_id();
		\update_post_meta( $product_id, 'bind_courses_data', $bind_rows );
		foreach ( $bind_rows as $row ) {
			if ( \is_array( $row ) && isset( $row['id'] ) ) {
				\add_post_meta( $product_id, 'bind_course_ids', (string) $row['id'] );
			}
		}

		return $product_id;
	}

	/**
	 * 建立變體商品的主商品
	 *
	 * @return int 主商品 ID
	 */
	private function make_variable_product(): int {
		$product = new \WC_Product_Variable();
		$product->set_name( '變體商品_' . uniqid() );
		$product->set_status( 'publish' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * 建立一個變體
	 *
	 * @param int $parent_id 主商品 ID
	 * @return int 變體 ID
	 */
	private function make_variation( int $parent_id ): int {
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent_id );
		$variation->set_status( 'publish' );
		$variation->set_regular_price( '999' );
		$variation->save();

		return $variation->get_id();
	}

	/**
	 * 建立一張含指定商品的訂單（**不** fire `woocommerce_new_order`）
	 *
	 * ⚠️ 這裡刻意不手工 `do_action( 'woocommerce_new_order', ... )`。
	 * 既有 4 支端到端測試（`FreeBundleCheckoutTest.php:239` 等）全都手工 fire 它，
	 * 把「這個 hook 一定會觸發」寫死成前提 —— 那正是 Issue #263 被遮蔽兩年的原因：
	 * resume 分支走 data store `update()`，`woocommerce_new_order` 根本不會觸發。
	 * 下一個維護者若「順手補上」這一行，本檔多數測試會變成永遠綠的說謊儀器。
	 *
	 * 註：`wc_create_order()` 內部的 `save()` 會觸發一次 `woocommerce_new_order`，
	 * 但那時訂單還沒有任何 line item，對本外掛的 handler 而言是 no-op。
	 *
	 * @param int $product_id 商品 ID
	 * @return \WC_Order
	 */
	private function create_order( int $product_id ): \WC_Order {
		$order = \wc_create_order( [ 'customer_id' => $this->buyer_id ] );
		$this->assertNotWPError( $order, 'wc_create_order 失敗' );

		$product = \wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product::class, $product, "找不到商品 {$product_id}" );

		$order->add_product( $product, 1 );
		$order->save();

		return $order;
	}

	/**
	 * 建立一張含「變體 line item」的訂單（**不** fire `woocommerce_new_order`）
	 *
	 * @param int $parent_id    主商品 ID
	 * @param int $variation_id 變體 ID
	 * @return \WC_Order
	 */
	private function create_order_with_variation_item( int $parent_id, int $variation_id ): \WC_Order {
		$order = \wc_create_order( [ 'customer_id' => $this->buyer_id ] );
		$this->assertNotWPError( $order, 'wc_create_order 失敗' );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $parent_id );
		$item->set_variation_id( $variation_id );
		$item->set_name( '變體項目' );
		$item->set_quantity( 1 );
		$order->add_item( $item );
		$order->save();

		return $order;
	}

	/**
	 * 建立一張「Issue #263 受害訂單」：item meta 先寫入、再被抹掉
	 *
	 * 這是 resume 分支的等價狀態 —— items 被 `remove_order_items()` 清光後依購物車重建，
	 * 新的 line item 身上沒有任何 power-course 寫的 item meta。
	 * 以「先正常建單、再刪 item meta」重現，是為了讓商品現況與訂單其餘欄位都維持真實。
	 *
	 * @param int $product_id 商品 ID
	 * @return \WC_Order 重新從 DB 取回的訂單
	 */
	private function make_victim_order( int $product_id ): \WC_Order {
		$order    = $this->create_order( $product_id );
		$order_id = $order->get_id();

		// 正常建單路徑先把快照寫進去
		\do_action( 'woocommerce_new_order', $order_id, $order );

		$order   = \wc_get_order( $order_id );
		$item_id = $this->get_only_item_id( $order );
		$this->assertNotEmpty(
			$this->reload_item( $item_id )->get_meta( self::ITEM_META_KEY ),
			'前置條件：正常建單應已寫入 _bind_courses_data'
		);

		// 抹掉 **全部** power-course item meta = resume 後的狀態。
		// 真實的 resume 是 remove_order_items() 連 order_itemmeta 一起 DELETE，
		// 只刪 `_bind_courses_data` 會留下 `_pc_item_processed` 戳記，
		// 那等於在模擬一個現實不存在的狀態，並讓 fallback 的閘門誤判。
		//
		// 用 `wc_delete_order_item_meta()` 而非裸 `delete_metadata()`：前者會一併
		// `WC_Cache_Helper::invalidate_cache_group( 'object_{item_id}' )`
		// （`wc-order-item-functions.php:146-153`），否則 `WC_Data::read_meta_data()`
		// 仍會從 `order-items` cache group 讀到已刪除的舊值，測試會假綠。
		foreach ( [ self::ITEM_META_KEY, '_pc_item_processed', '_is_course', '_limit_type', '_limit_value', '_limit_unit' ] as $meta_key ) {
			\wc_delete_order_item_meta( $item_id, $meta_key );
		}

		return \wc_get_order( $order_id );
	}

	/**
	 * 取得訂單唯一的 line item
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WC_Order_Item_Product
	 */
	private function get_only_item( \WC_Order $order ): \WC_Order_Item_Product {
		$items = $order->get_items();
		$this->assertCount( 1, $items, '此 fixture 預期訂單只有一列 line item' );

		$item = reset( $items );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		return $item;
	}

	/**
	 * 取得訂單唯一 line item 的 ID
	 *
	 * @param \WC_Order $order 訂單
	 * @return int
	 */
	private function get_only_item_id( \WC_Order $order ): int {
		$item_id = (int) $this->get_only_item( $order )->get_id();
		$this->assertGreaterThan( 0, $item_id, 'line item 應已落地並取得 order_item_id' );

		return $item_id;
	}

	/**
	 * 從 DB 重新取得 order item（繞開任何手上舊物件的 meta 快照）
	 *
	 * @param int $item_id order item ID
	 * @return \WC_Order_Item_Product
	 */
	private function reload_item( int $item_id ): \WC_Order_Item_Product {
		$item = \WC_Order_Factory::get_order_item( $item_id );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item, "重新讀取 order item {$item_id} 失敗" );

		return $item;
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
