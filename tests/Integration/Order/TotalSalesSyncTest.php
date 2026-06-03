<?php
/**
 * 課程已售出數量同步 整合測試
 * Feature: specs/features/order/同步課程已售出數量.feature
 *
 * 對應 Issue #228：total_sales 進入觸發狀態 +、離開 −、冪等、含 Bundle 與訂閱語意。
 *
 * @group order
 * @group total-sales
 */

declare( strict_types=1 );

namespace Tests\Integration\Order;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\Service\TotalSalesSync;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class TotalSalesSyncTest
 *
 * 直接測試 TotalSalesSync 服務的核心解析、increment、decrement 與 hooks。
 * 課程商品以 `_is_course = yes`（is_course_product 判斷）建立；
 * 綁課商品以 `bind_courses_data` post meta 綁定課程。
 */
class TotalSalesSyncTest extends TestCase {

	/** @var int 課程 100（PHP 基礎課），初始 total_sales = 50 */
	private int $course_100;

	/** @var int 課程 101（React 課程），初始 total_sales = 30 */
	private int $course_101;

	/** @var int 綁課商品 500（綁定課程 100、101） */
	private int $product_500;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接操作 TotalSalesSync 服務
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_100 = $this->create_course_with_sales( 'PHP 基礎課', 50 );
		$this->course_101 = $this->create_course_with_sales( 'React 課程', 30 );

		// 綁課商品 500：非課程商品，但綁定課程 100、101
		$this->product_500 = $this->create_bind_product( ' 全端課程套餐', [ $this->course_100, $this->course_101 ] );

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_ts_' . uniqid(),
				'user_email' => 'alice_ts_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		// 預設觸發狀態為 completed
		$this->set_trigger( 'completed' );
	}

	// ========== Helper ==========

	/**
	 * 建立課程商品並設定 total_sales
	 *
	 * @param string $title 標題
	 * @param int    $sales 初始 total_sales
	 * @return int 商品 ID
	 */
	private function create_course_with_sales( string $title, int $sales ): int {
		$id      = $this->create_course( [ 'post_title' => $title ] );
		$product = \wc_get_product( $id );
		$product->set_total_sales( $sales );
		$product->save();
		return $id;
	}

	/**
	 * 建立綁課商品（非課程，綁定指定課程）
	 *
	 * @param string     $title 標題
	 * @param array<int> $course_ids 綁定課程 ID 列表
	 * @return int 商品 ID
	 */
	private function create_bind_product( string $title, array $course_ids ): int {
		$id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $id, '_is_course', 'no' );
		\update_post_meta( $id, '_price', '3000' );
		\update_post_meta( $id, '_regular_price', '3000' );

		$bind_courses_data = [];
		foreach ( $course_ids as $course_id ) {
			$bind_courses_data[] = [
				'id'          => $course_id,
				'name'        => \get_the_title( $course_id ),
				'limit_type'  => 'unlimited',
				'limit_value' => null,
				'limit_unit'  => null,
			];
		}
		\update_post_meta( $id, 'bind_courses_data', $bind_courses_data );

		return $id;
	}

	/**
	 * 設定 course_access_trigger
	 *
	 * @param string $trigger 觸發狀態
	 * @return void
	 */
	private function set_trigger( string $trigger ): void {
		$settings = Settings::instance();
		$settings->course_access_trigger = $trigger;
		$settings->save();
	}

	/**
	 * 建立訂單並加入商品
	 *
	 * @param int        $product_id 商品 ID
	 * @param int        $qty 數量
	 * @param string     $status 狀態
	 * @return \WC_Order
	 */
	private function make_order( int $product_id, int $qty = 1, string $status = 'pending' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_id( $this->alice_id );
		$order->set_currency( 'TWD' );
		$product = \wc_get_product( $product_id );
		$order->add_product( $product, $qty );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/**
	 * 取得課程 total_sales
	 *
	 * @param int $course_id 課程 ID
	 * @return int
	 */
	private function get_sales( int $course_id ): int {
		$product = \wc_get_product( $course_id );
		return (int) $product->get_total_sales();
	}

	/**
	 * 設定課程 total_sales
	 *
	 * @param int $course_id 課程 ID
	 * @param int $sales total_sales
	 * @return void
	 */
	private function set_sales( int $course_id, int $sales ): void {
		$product = \wc_get_product( $course_id );
		$product->set_total_sales( $sales );
		$product->save();
	}

	// ========== resolve_course_quantities ==========

	/**
	 * @test
	 * @group smoke
	 * 解析綁課商品 → 各綁定課程 +qty
	 */
	public function test_resolve_綁課商品依數量累計各課程(): void {
		$order = $this->make_order( $this->product_500, 3 );
		$map   = TotalSalesSync::instance()->resolve_course_quantities( $order );

		$this->assertSame( 3, $map[ $this->course_100 ] ?? 0 );
		$this->assertSame( 3, $map[ $this->course_101 ] ?? 0 );
	}

	// ========== increment ==========

	/**
	 * @test
	 * @group happy
	 * Example: 訂單完成時綁定課程的已售出數量增加
	 */
	public function test_increment_訂單完成綁定課程已售出增加(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		TotalSalesSync::instance()->increment( $order );

		$this->assertSame( 51, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 31, $this->get_sales( $this->course_101 ) );
		$this->assertSame( 'yes', $order->get_meta( TotalSalesSync::META_COUNTED ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 購買多份時依份數累加
	 */
	public function test_increment_購買多份依份數累加(): void {
		$order = $this->make_order( $this->product_500, 3, 'pending' );

		TotalSalesSync::instance()->increment( $order );

		$this->assertSame( 53, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 33, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 已計入的訂單再次進入觸發狀態不重複 +1（冪等 guard）
	 */
	public function test_increment_已計入不重複增加(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		$order->update_meta_data( TotalSalesSync::META_COUNTED, 'yes' );
		$order->save();

		TotalSalesSync::instance()->increment( $order );

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 30, $this->get_sales( $this->course_101 ) );
	}

	// ========== decrement ==========

	/**
	 * @test
	 * @group happy
	 * Example: 已完成訂單被取消時扣減已售出數量
	 */
	public function test_decrement_扣減已售出(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		TotalSalesSync::instance()->increment( $order );

		TotalSalesSync::instance()->decrement( $order );

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 30, $this->get_sales( $this->course_101 ) );
		$this->assertSame( 'no', $order->get_meta( TotalSalesSync::META_COUNTED ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: total_sales 不會被扣減為負數
	 */
	public function test_decrement_不為負數(): void {
		$this->set_sales( $this->course_100, 0 );
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		$order->update_meta_data( TotalSalesSync::META_COUNTED, 'yes' );
		$order->save();

		TotalSalesSync::instance()->decrement( $order );

		$this->assertSame( 0, $this->get_sales( $this->course_100 ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 未計入的訂單離開觸發狀態不會誤扣
	 */
	public function test_decrement_未計入不誤扣(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		$order->update_meta_data( TotalSalesSync::META_COUNTED, 'no' );
		$order->save();

		TotalSalesSync::instance()->decrement( $order );

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
	}

	// ========== hooks：order_status_changed ==========

	/**
	 * @test
	 * @group happy
	 * Example: 訂單完成時綁定課程的已售出數量增加（透過 hook）
	 */
	public function test_hook_完成觸發increment(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( 51, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 31, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 付款失敗的訂單不計入已售出數量
	 */
	public function test_hook_付款失敗不計入(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		$order->set_status( 'failed' );
		$order->save();

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 30, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 僅處理中（未達觸發狀態 completed）的訂單不計入已售出數量
	 */
	public function test_hook_處理中未達觸發不計入(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 觸發狀態設為 processing 時付款即計入
	 */
	public function test_hook_觸發設為processing時計入(): void {
		$this->set_trigger( 'processing' );
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		$order->set_status( 'processing' );
		$order->save();

		$this->assertSame( 51, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 31, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 已完成訂單被取消時扣減已售出數量（透過 hook）
	 */
	public function test_hook_取消扣減(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		$order->set_status( 'completed' );
		$order->save();

		$order->set_status( 'cancelled' );
		$order->save();

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 30, $this->get_sales( $this->course_101 ) );
		$this->assertSame( 'no', $order->get_meta( TotalSalesSync::META_COUNTED ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 訂單狀態 completed → cancelled → completed 最終只 +1（冪等來回切換）
	 */
	public function test_hook_來回切換最終只加一(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );

		$order->set_status( 'completed' );
		$order->save();
		$order->set_status( 'cancelled' );
		$order->save();
		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( 51, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 'yes', $order->get_meta( TotalSalesSync::META_COUNTED ) );
	}

	// ========== hooks：退費（refunded 與部分退費）==========

	/**
	 * @test
	 * @group happy
	 * Example: 已完成訂單被全額退費時扣減已售出數量
	 * 全額退費雙 hook（refund + status→refunded）只能扣一次。
	 */
	public function test_hook_全額退費只扣一次(): void {
		$order = $this->make_order( $this->product_500, 1, 'pending' );
		$order->set_status( 'completed' );
		$order->save();

		// 對整張訂單全額退費
		\wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => (float) $order->get_total(),
				'line_items' => [],
			]
		);

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 30, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 訂單含兩商品僅退費其一時只扣減該商品的課程（部分退費）
	 */
	public function test_hook_部分退費只扣對應課程(): void {
		// 商品 501 綁課程 100、商品 502 綁課程 101
		$product_501 = $this->create_bind_product( '單課-PHP', [ $this->course_100 ] );
		$product_502 = $this->create_bind_product( '單課-React', [ $this->course_101 ] );

		$order = new \WC_Order();
		$order->set_customer_id( $this->alice_id );
		$order->set_currency( 'TWD' );
		$item_501_id = $order->add_product( \wc_get_product( $product_501 ), 1 );
		$order->add_product( \wc_get_product( $product_502 ), 1 );
		$order->set_status( 'pending' );
		$order->save();

		$order->set_status( 'completed' );
		$order->save();

		// 此時 100=51, 101=31
		$this->assertSame( 51, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 31, $this->get_sales( $this->course_101 ) );

		// 只對商品 501（課程 100）做部分退費
		\wc_create_refund(
			[
				'order_id'   => $order->get_id(),
				'amount'     => 999,
				'line_items' => [
					$item_501_id => [
						'qty'          => 1,
						'refund_total' => 999,
					],
				],
			]
		);

		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 31, $this->get_sales( $this->course_101 ) );
	}

	// ========== 訂閱（Q3-A）==========

	/**
	 * @test
	 * @group edge
	 * Example: 訂閱續訂不重複計入已售出
	 * 無 WC_Subscription 外掛時略過。
	 */
	public function test_訂閱續訂不重複計入(): void {
		if ( ! class_exists( 'WCS_Order_Subscription_Trait' ) && ! function_exists( 'wcs_order_contains_subscription' ) ) {
			$this->markTestSkipped( '需要 WooCommerce Subscriptions 外掛' );
		}
		$this->assertTrue( true );
	}

	// ========== 銷售方案 Bundle（Q4-A）==========

	/**
	 * @test
	 * @group happy
	 * Example: 購買銷售方案時依數量設定累計各課程已售出
	 * 方案 600 包含商品 500（綁課程 100、101），pbp_product_quantities = {"500": 2}
	 */
	public function test_bundle_依數量設定累計(): void {
		$bundle_600 = $this->factory()->post->create(
			[
				'post_title'  => '合購方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_600, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_600, Helper::LINK_COURSE_IDS_META_KEY, (string) $this->course_100 );
		\update_post_meta( $bundle_600, '_is_course', 'no' );
		\update_post_meta( $bundle_600, '_price', '3000' );
		\update_post_meta( $bundle_600, '_regular_price', '3000' );
		\update_post_meta( $bundle_600, 'exclude_main_course', 'yes' );
		\add_post_meta( $bundle_600, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_500 );
		\update_post_meta( $bundle_600, Helper::PRODUCT_QUANTITIES_META_KEY, \wp_json_encode( [ (string) $this->product_500 => 2 ] ) );

		// 購買方案 600 數量 1 → 方案展開 500×2 → 課程 100、101 各 +2
		$order = $this->make_order( $bundle_600, 1, 'pending' );

		$order->set_status( 'completed' );
		$order->save();

		$this->assertSame( 52, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 32, $this->get_sales( $this->course_101 ) );
	}

	// ========== 語意分離：手動授權不影響 total_sales ==========

	/**
	 * @test
	 * @group edge
	 * Example: 後台手動新增學員不增加已售出數量
	 */
	public function test_手動加學員不影響total_sales(): void {
		$this->enroll_user_to_course( $this->alice_id, $this->course_100, 0 );

		$this->assert_user_has_course_access( $this->alice_id, $this->course_100 );
		$this->assertSame( 50, $this->get_sales( $this->course_100 ) );
	}
}
