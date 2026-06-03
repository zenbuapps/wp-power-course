<?php
/**
 * 「已退費」訂單應從課程「已售出數量」剔除 整合測試
 *
 * 對應修正：Stats::revenue() 的 products-stats 分支臨時把 refunded 併入
 * analytics 排除狀態，讓手動改單為「已退費」(wc-refunded) 的訂單比照「已取消」
 * (wc-cancelled) 從 items_sold 中剔除。
 *
 * ⚠️ 注意：本測試需要 MySQL 資料庫啟動（WP_UnitTestCase + WC analytics lookup 表）。
 * 本機 Local 站 DB 未啟動時無法實跑——此時測試僅作為規格保留，CI 環境會執行。
 *
 * @group report
 */

declare( strict_types=1 );

namespace Tests\Integration\Report;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Report\Service\Stats;
use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStatsDataStore;
use Automattic\WooCommerce\Admin\API\Reports\Products\DataStore as ProductsDataStore;

/**
 * Class RefundedExcludedFromItemsSoldTest
 */
class RefundedExcludedFromItemsSoldTest extends TestCase {

	/**
	 * 初始化依賴（本測試直接使用 WC analytics data store + Stats service）
	 */
	protected function configure_dependencies(): void {
		// 無額外依賴
	}

	/**
	 * 建立一筆含指定商品 item 的 WC 訂單，並同步到 WC Analytics lookup 表
	 *
	 * sync 兩張表：
	 * - wc_order_stats（OrdersStatsDataStore::sync_order）：寫入訂單狀態，供排除清單過濾
	 * - wc_order_product_lookup（ProductsDataStore::sync_order_products）：寫入 product_qty，供 items_sold 加總
	 *
	 * @param int    $product_id 商品 ID
	 * @param int    $qty        數量
	 * @param string $status     訂單狀態（不含 wc- 前綴，例如 completed / refunded）
	 * @return \WC_Order
	 */
	private function create_synced_order( int $product_id, int $qty, string $status ): \WC_Order {
		$order = new \WC_Order();
		$order->set_currency( 'TWD' );

		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $product_id );
		$item->set_name( '測試課程' );
		$item->set_quantity( $qty );
		$item->set_total( 100.0 * $qty );
		$order->add_item( $item );

		$order->set_status( $status );
		$order->save();

		// 同步至 WC Analytics lookup 表（測試環境不會自動跑 scheduler，需手動 sync）
		OrdersStatsDataStore::sync_order( $order->get_id() );
		ProductsDataStore::sync_order_products( $order->get_id() );

		return $order;
	}

	/**
	 * 取得指定商品在區間內的 items_sold（透過 Stats::revenue 的 products-stats 分支）
	 *
	 * @param int $product_id 商品 ID
	 * @return int
	 */
	private function query_items_sold( int $product_id ): int {
		$result = Stats::revenue(
			[
				'product_includes' => [ $product_id ],
				'after'            => '2020-01-01 00:00:00',
				'before'           => '2099-12-31 23:59:59',
				'interval'         => 'year',
			]
		);

		// products-stats 回傳物件，items_sold 落在 ->totals->items_sold
		if ( \is_object( $result ) && isset( $result->totals ) && isset( $result->totals->items_sold ) ) {
			return (int) $result->totals->items_sold;
		}

		// 防呆：若結構為陣列
		if ( \is_array( $result ) && isset( $result['totals']['items_sold'] ) ) {
			return (int) $result['totals']['items_sold'];
		}

		return -1;
	}

	/**
	 * @test
	 * @group happy
	 * 「已退費」訂單不計入 items_sold；同商品的「已完成」訂單仍計入
	 *
	 * 場景：同一課程有兩筆訂單，一筆 completed（qty=1）、一筆 refunded（qty=1）。
	 * 修正後 items_sold 應只算 completed 那筆 = 1，refunded 被排除。
	 */
	public function test_refunded_order_excluded_from_items_sold(): void {
		$course_id = $this->create_course( [ 'price' => '100' ] );

		// 一筆已完成（應計入）
		$this->create_synced_order( $course_id, 1, 'completed' );
		// 一筆已退費（應被剔除）
		$this->create_synced_order( $course_id, 1, 'refunded' );

		$items_sold = $this->query_items_sold( $course_id );

		$this->assertSame(
			1,
			$items_sold,
			'已退費訂單應從 items_sold 剔除，只剩已完成的 1 筆'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 全部訂單皆為「已退費」時，items_sold 應為 0
	 */
	public function test_all_refunded_yields_zero_items_sold(): void {
		$course_id = $this->create_course( [ 'price' => '100' ] );

		$this->create_synced_order( $course_id, 2, 'refunded' );

		$items_sold = $this->query_items_sold( $course_id );

		$this->assertSame(
			0,
			$items_sold,
			'唯一訂單為已退費時，items_sold 應為 0'
		);
	}

	/**
	 * @test
	 * @group edge
	 * filter 生命週期：Stats::revenue 呼叫結束後，
	 * woocommerce_analytics_excluded_order_statuses 不應殘留本修正掛的 callback，
	 * 以免污染後續全域 Analytics 查詢。
	 */
	public function test_filter_is_removed_after_query(): void {
		$course_id = $this->create_course( [ 'price' => '100' ] );
		$this->create_synced_order( $course_id, 1, 'completed' );

		// 呼叫前不應有本 callback
		$this->assertFalse(
			\has_filter(
				'woocommerce_analytics_excluded_order_statuses',
				[ Stats::class, 'append_refunded_to_excluded' ]
			),
			'查詢前不應掛載本 filter callback'
		);

		$this->query_items_sold( $course_id );

		// 呼叫後 callback 必須已被移除
		$this->assertFalse(
			\has_filter(
				'woocommerce_analytics_excluded_order_statuses',
				[ Stats::class, 'append_refunded_to_excluded' ]
			),
			'查詢後本 filter callback 必須被移除，避免污染全域 Analytics'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * append_refunded_to_excluded() 單元行為：
	 * 把 refunded 加入清單，且不帶 wc- 前綴、不重複。
	 */
	public function test_append_refunded_to_excluded_callback(): void {
		$input  = [ 'pending', 'failed', 'cancelled', 'auto-draft', 'trash' ];
		$output = Stats::append_refunded_to_excluded( $input );

		$this->assertContains( 'refunded', $output, '應加入 refunded' );
		$this->assertNotContains( 'wc-refunded', $output, '不應帶 wc- 前綴（WC 後續才 normalize）' );

		// 既有狀態應全數保留
		foreach ( $input as $status ) {
			$this->assertContains( $status, $output, "既有狀態 {$status} 應保留" );
		}

		// 重複呼叫不應產生重複的 refunded
		$twice = Stats::append_refunded_to_excluded( $output );
		$this->assertSame(
			1,
			count( array_keys( $twice, 'refunded', true ) ),
			'refunded 不應重複'
		);
	}
}
