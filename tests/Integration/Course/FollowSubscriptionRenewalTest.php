<?php
/**
 * 跟隨訂閱（follow_subscription）續訂單開通整合測試（Issue #280）
 *
 * 病灶：開通入口 `Order::add_meta_to_avl_course()` 掛在 `woocommerce_order_status_{trigger}`，
 * 續訂單 / 升降級單進入開通狀態時同樣會觸發；但「這張訂單屬於哪一張訂閱」的查詢
 * （`Limit::calc_expire_date()` 與 `AccessPass\Service\Grant::resolve_subscription_expire()`）
 * 只帶 `order_type => 'parent'`。續訂單只有 renewal 關聯，查詢必然回空，兩邊各自 fallback：
 * - 課程：`expire_date` 被覆寫成 `0` = 永久（訂閱取消後學員仍可永久觀看）
 * - 通行證：`expire_date` 被覆寫成 `'0'`，follow_subscription 對 `'0'` 判定失效（付費學員續訂後反而被鎖）
 *
 * 修復：兩處改用單一入口 `Utils\Subscription::get_subscription_id_for_order()`，
 * 依 parent → renewal → switch 的順序解析。
 *
 * 測試環境沒有 WooCommerce Subscriptions：以既有的 `power_course_subscription_available` filter
 * 宣告訂閱功能可用，並以 `power_course_order_subscription_ids` filter 模擬「訂單 ↔ 訂閱」的關聯。
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\AccessPass\Core\CPT as AccessPassCPT;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Order as OrderResource;
use J7\PowerCourse\Utils\Subscription;

/**
 * Class FollowSubscriptionRenewalTest
 * 驗證續訂單 / 升降級單開通時，跟隨訂閱的到期日仍綁定原訂閱
 *
 * `@group` 寫在 class docblock：檔案 docblock（`declare` 之前）PHPUnit 讀不到；
 * class 層必須含白名單 group（happy / edge），否則 CI 的預設執行不會跑到。
 *
 * @group subscription
 * @group issue-280
 * @group happy
 * @group edge
 */
class FollowSubscriptionRenewalTest extends TestCase {

	/** @var int 訂閱 ID（測試環境沒有真的訂閱，只是被關聯的數字） */
	private const SUBSCRIPTION_ID = 7001;

	/** @var int 顧客用戶 ID */
	private int $customer_id;

	/**
	 * 模擬的「訂單 ↔ 訂閱」關聯
	 *
	 * @var array<int, array<string, array<int, int>>> order_id → relation_type → subscription_ids
	 */
	private array $relations = [];

	/**
	 * 初始化依賴（SingletonTrait 不會重複掛 hook）
	 */
	protected function configure_dependencies(): void {
		OrderResource::instance();
	}

	/**
	 * 每個測試前清訂單項目表、建立顧客、掛上訂閱模擬 filter
	 */
	public function set_up(): void {
		parent::set_up();

		$this->truncate_wc_order_item_tables();
		$this->relations = [];

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_login' => 'renewal_buyer_' . uniqid(),
				'user_email' => 'renewal_buyer_' . uniqid() . '@test.com',
			]
		);

		\add_filter( 'power_course_subscription_available', '__return_true' );
		\add_filter( 'power_course_order_subscription_ids', [ $this, 'filter_order_subscription_ids' ], 10, 3 );
	}

	/**
	 * 每個測試後移除 filter、清 `pc_user_access_pass`（`TestCase::clean_custom_tables()` 不含此表）
	 */
	public function tear_down(): void {
		\remove_filter( 'power_course_subscription_available', '__return_true' );
		\remove_filter( 'power_course_order_subscription_ids', [ $this, 'filter_order_subscription_ids' ], 10 );

		global $wpdb;
		$table = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	/**
	 * `power_course_order_subscription_ids` 的模擬實作：回傳 `$this->relations` 設定的關聯
	 *
	 * @param array<int, int> $subscription_ids WCS 查到的訂閱 ID（測試環境恆為空）
	 * @param \WC_Order       $order            訂單
	 * @param string          $relation_type    關聯類型
	 * @return array<int, int>
	 */
	public function filter_order_subscription_ids( $subscription_ids, $order, $relation_type ): array {
		return [
			...(array) $subscription_ids,
			...( $this->relations[ (int) $order->get_id() ][ $relation_type ] ?? [] ),
		];
	}

	// ========== 端到端：課程 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 續訂單進入開通狀態後，課程到期日仍綁定原訂閱，不被改成永久。
	 *
	 * 這是 issue 回報的主症狀。續訂單的 line item 由 WCS 從訂閱複製，
	 * 身上沒有 power-course 的 `_limit_*` 快照，`handle_single_course()` 會 fallback
	 * 讀商品現況（follow_subscription），再以續訂單查訂閱 —— 只查 parent 必然查無 → 0。
	 */
	public function test_續訂單開通後課程到期日仍綁定訂閱(): void {
		$course_id = $this->make_course_product( 'follow_subscription' );

		// Given：首期已開通，學員到期日綁定訂閱
		$this->enroll_user_to_course( $this->customer_id, $course_id, 'subscription_' . self::SUBSCRIPTION_ID );
		$this->assertSame(
			'subscription_' . self::SUBSCRIPTION_ID,
			(string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' ),
			'前置條件：首期開通後到期日應綁定訂閱'
		);

		// Given：續訂單（只有 renewal 關聯，沒有 `_limit_*` 快照）
		$renewal_order = $this->make_order( $course_id );
		$this->relate( $renewal_order, 'renewal', [ self::SUBSCRIPTION_ID ] );
		$this->assertNull(
			Limit::from_order_item( $this->get_only_item( $renewal_order ) ),
			'前置條件：續訂單 item 不應有 _limit_* 快照（對齊 WCS 從訂閱複製 item 的實況）'
		);

		// When：續訂單進入開通狀態
		$renewal_order->update_status( 'completed' );

		// Then
		$this->assertSame(
			'subscription_' . self::SUBSCRIPTION_ID,
			(string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' ),
			'續訂後課程到期日應仍綁定原訂閱，不得被改成 0（永久）'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 首期訂單（parent）開通時，課程到期日綁定訂閱 —— 修復不得破壞既有主線。
	 */
	public function test_首期訂單開通時課程到期日綁定訂閱(): void {
		$course_id = $this->make_course_product( 'follow_subscription' );

		$parent_order = $this->make_order( $course_id );
		$this->relate( $parent_order, 'parent', [ self::SUBSCRIPTION_ID ] );

		$parent_order->update_status( 'completed' );

		$this->assert_user_has_course_access( $this->customer_id, $course_id );
		$this->assertSame(
			'subscription_' . self::SUBSCRIPTION_ID,
			(string) $this->get_course_meta( $course_id, $this->customer_id, 'expire_date' ),
			'首期訂單開通後到期日應綁定訂閱'
		);
	}

	// ========== 端到端：通行證 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 續訂單進入開通狀態後，跟隨訂閱的通行證到期日仍綁定原訂閱。
	 *
	 * 與課程同一個病灶、相反的症狀：`Grant::resolve_subscription_expire()` 查無訂閱回 `'0'`，
	 * `Gate::is_expire_valid()` 對 follow_subscription 的 `'0'` 判定失效 ——
	 * 付費學員每次續訂後反而失去觀看權。
	 */
	public function test_續訂單開通後通行證到期日仍綁定訂閱(): void {
		$pass_id    = $this->make_follow_subscription_pass();
		$product_id = $this->make_product_with_pass( $pass_id );

		// Given：首期付款已開通（Grant::on_subscription_payment_complete 的結果）
		Repository::insert_or_update( $this->customer_id, $pass_id, null, 'subscription_' . self::SUBSCRIPTION_ID );
		$this->assertSame(
			'subscription_' . self::SUBSCRIPTION_ID,
			$this->get_pass_expire_date( $this->customer_id, $pass_id ),
			'前置條件：首期開通後通行證到期日應綁定訂閱'
		);

		// Given：續訂單
		$renewal_order = $this->make_order( $product_id );
		$this->relate( $renewal_order, 'renewal', [ self::SUBSCRIPTION_ID ] );

		// When
		$renewal_order->update_status( 'completed' );

		// Then
		$this->assertSame(
			'subscription_' . self::SUBSCRIPTION_ID,
			$this->get_pass_expire_date( $this->customer_id, $pass_id ),
			'續訂後通行證到期日應仍綁定原訂閱，不得被改成 0（follow_subscription 視為失效）'
		);
	}

	// ========== 契約：Subscription::get_subscription_id_for_order() ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 續訂單（只有 renewal 關聯）→ 回傳該訂閱 ID。
	 */
	public function test_續訂單解析出所屬訂閱(): void {
		$order = $this->make_order( $this->make_course_product( 'follow_subscription' ) );
		$this->relate( $order, 'renewal', [ self::SUBSCRIPTION_ID ] );

		$this->assertSame( self::SUBSCRIPTION_ID, Subscription::get_subscription_id_for_order( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 原訂閱內升降級（只有 switch 關聯）→ 回傳該訂閱 ID。
	 *
	 * 升降級單進入開通狀態一樣會走 `add_meta_to_avl_course()`，與續訂單同一條路徑。
	 */
	public function test_原訂閱內升降級解析出該訂閱(): void {
		$order = $this->make_order( $this->make_course_product( 'follow_subscription' ) );
		$this->relate( $order, 'switch', [ 9001 ] );

		$this->assertSame( 9001, Subscription::get_subscription_id_for_order( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 升降級另開新訂閱：訂單是新訂閱的 parent、同時是舊訂閱的 switch → 綁新訂閱。
	 *
	 * 這條鎖住「parent 優先」：若改成把所有類型合併查詢，會查到兩張而回 null。
	 */
	public function test_parent關聯優先於switch(): void {
		$order = $this->make_order( $this->make_course_product( 'follow_subscription' ) );
		$this->relate( $order, 'parent', [ 9002 ] );
		$this->relate( $order, 'switch', [ 9001 ] );

		$this->assertSame( 9002, Subscription::get_subscription_id_for_order( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 第一個查得到的關聯類型有多張訂閱 → 無法判斷，回 null，且不往下一個類型找。
	 *
	 * 對齊修復前 parent 查到多張時的行為（一張訂單含多個不同週期的訂閱商品）。
	 */
	public function test_同一關聯類型有多張訂閱時回傳null(): void {
		$order = $this->make_order( $this->make_course_product( 'follow_subscription' ) );
		$this->relate( $order, 'parent', [ 9001, 9002 ] );
		$this->relate( $order, 'renewal', [ 9003 ] );

		$this->assertNull( Subscription::get_subscription_id_for_order( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 沒有任何訂閱關聯 / 沒有訂單 → null。
	 */
	public function test_無訂閱關聯時回傳null(): void {
		$order = $this->make_order( $this->make_course_product( 'follow_subscription' ) );

		$this->assertNull( Subscription::get_subscription_id_for_order( $order ), '一般訂單沒有訂閱關聯' );
		$this->assertNull( Subscription::get_subscription_id_for_order( null ), '沒有訂單' );
	}

	// ========== fixture ==========

	/**
	 * 設定訂單在指定關聯類型下的訂閱
	 *
	 * @param \WC_Order       $order            訂單
	 * @param string          $relation_type    關聯類型 'parent' | 'renewal' | 'switch'
	 * @param array<int, int> $subscription_ids 訂閱 ID
	 * @return void
	 */
	private function relate( \WC_Order $order, string $relation_type, array $subscription_ids ): void {
		$this->relations[ (int) $order->get_id() ][ $relation_type ] = $subscription_ids;
	}

	/**
	 * 建立課程商品（`_is_course = yes`）
	 *
	 * @param string $limit_type 限制類型
	 * @return int 商品 ID
	 */
	private function make_course_product( string $limit_type ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( '訂閱課程_' . uniqid() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '299' );
		$product->update_meta_data( '_is_course', 'yes' );
		$product->update_meta_data( 'limit_type', $limit_type );
		$product->save();

		return (int) $product->get_id();
	}

	/**
	 * 建立跟隨訂閱的通行證（全站範圍）
	 *
	 * @return int 通行證 post ID
	 */
	private function make_follow_subscription_pass(): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => AccessPassCPT::POST_TYPE,
				'post_title'  => '訂閱全站權限_' . uniqid(),
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $pass_id, 'scope_type', 'all' );
		\update_post_meta( $pass_id, 'limit_type', 'follow_subscription' );
		\update_post_meta( $pass_id, 'access_pass_status', 'active' );

		return $pass_id;
	}

	/**
	 * 建立掛載通行證的商品
	 *
	 * @param int $pass_id 通行證 post ID
	 * @return int 商品 ID
	 */
	private function make_product_with_pass( int $pass_id ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( '月費暢看_' . uniqid() );
		$product->set_status( 'publish' );
		$product->set_regular_price( '299' );
		$product->update_meta_data( Grant::PRODUCT_META_KEY, $pass_id );
		$product->save();

		return (int) $product->get_id();
	}

	/**
	 * 建立一張含指定商品的訂單
	 *
	 * 刻意**不**觸發 `woocommerce_checkout_order_processed`：續訂單由 WCS 從訂閱複製 line item，
	 * 不經結帳流程，身上不會有 power-course 寫入的 `_limit_*` / `_bind_courses_data` 快照。
	 *
	 * @param int $product_id 商品 ID
	 * @return \WC_Order 重新從 DB 取回的訂單
	 */
	private function make_order( int $product_id ): \WC_Order {
		$order = \wc_create_order( [ 'customer_id' => $this->customer_id ] );
		$this->assertNotWPError( $order, 'wc_create_order 失敗' );

		$product = \wc_get_product( $product_id );
		$this->assertInstanceOf( \WC_Product::class, $product, "找不到商品 {$product_id}" );

		$order->add_product( $product, 1 );
		$order->save();

		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );

		return $order;
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

		$item = \reset( $items );
		$this->assertInstanceOf( \WC_Order_Item_Product::class, $item );

		return $item;
	}

	/**
	 * 讀取持有關係的到期表達式
	 *
	 * @param int $user_id 學員 ID
	 * @param int $pass_id 通行證 ID
	 * @return string|null
	 */
	private function get_pass_expire_date( int $user_id, int $pass_id ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;

		$expire_date = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT expire_date FROM `{$table}` WHERE user_id = %d AND pass_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$pass_id
			)
		);

		return null === $expire_date ? null : (string) $expire_date;
	}

	/**
	 * 清空 WooCommerce 訂單項目表（測試隔離）
	 *
	 * 殘留列會被「重複使用相同 order_id」的新訂單撿走，讓「訂單只有一列 line item」的前置條件飄移。
	 */
	private function truncate_wc_order_item_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_itemmeta" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_order_items" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
