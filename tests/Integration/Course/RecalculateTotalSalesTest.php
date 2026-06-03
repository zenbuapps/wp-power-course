<?php
/**
 * 重新計算課程已售出數量 整合測試
 * Feature: specs/features/report/重新計算課程已售出數量.feature
 *
 * Issue #228 歷史資料修復：以有效訂單重建各課程 total_sales。
 *
 * @group course
 * @group total-sales
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\Service\RecalculateTotalSales;
use J7\PowerCourse\Resources\Course\Service\TotalSalesSync;
use J7\PowerCourse\Resources\Settings\Model\Settings;

/**
 * Class RecalculateTotalSalesTest
 */
class RecalculateTotalSalesTest extends TestCase {

	/** @var int 課程 100（PHP 基礎課），初始虛高 total_sales = 200 */
	private int $course_100;

	/** @var int 課程 101（React 課程），初始 total_sales = 0 */
	private int $course_101;

	/** @var int 綁課商品 500（綁定課程 100） */
	private int $product_500;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接操作 RecalculateTotalSales 服務
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_100 = $this->create_course_with_sales( 'PHP 基礎課', 200 );
		$this->course_101 = $this->create_course_with_sales( 'React 課程', 0 );
		$this->product_500 = $this->create_bind_product( 'PHP 課程', [ $this->course_100 ] );

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_recalc_' . uniqid(),
				'user_email' => 'alice_recalc_' . uniqid() . '@test.com',
			]
		);

		$settings = Settings::instance();
		$settings->course_access_trigger = 'completed';
		$settings->save();
	}

	// ========== Helper ==========

	/**
	 * @param string $title 標題
	 * @param int    $sales 初始 total_sales
	 * @return int
	 */
	private function create_course_with_sales( string $title, int $sales ): int {
		$id      = $this->create_course( [ 'post_title' => $title ] );
		$product = \wc_get_product( $id );
		$product->set_total_sales( $sales );
		$product->save();
		return $id;
	}

	/**
	 * @param string     $title 標題
	 * @param array<int> $course_ids 綁定課程
	 * @return int
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
		\update_post_meta( $id, '_price', '999' );
		\update_post_meta( $id, '_regular_price', '999' );

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
	 * 建立訂單
	 *
	 * @param string $status 狀態
	 * @param int    $qty 數量
	 * @return \WC_Order
	 */
	private function make_order( string $status, int $qty = 1 ): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_id( $this->alice_id );
		$order->set_currency( 'TWD' );
		$order->add_product( \wc_get_product( $this->product_500 ), $qty );
		$order->set_status( $status );
		$order->save();
		return $order;
	}

	/**
	 * @param int $course_id 課程 ID
	 * @return int
	 */
	private function get_sales( int $course_id ): int {
		$product = \wc_get_product( $course_id );
		return (int) $product->get_total_sales();
	}

	// ========== 後置（狀態）：手動重新計算 ==========

	/**
	 * @test
	 * @group happy
	 * Example: 重新計算後虛高數據被修正為有效訂單數
	 */
	public function test_重新計算修正虛高數據(): void {
		$this->make_order( 'completed' );
		$this->make_order( 'refunded' );
		$this->make_order( 'cancelled' );
		$this->make_order( 'failed' );

		RecalculateTotalSales::instance()->schedule();
		RecalculateTotalSales::instance()->process_batch( 0 );

		$this->assertSame( 1, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 0, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 重新計算為冪等操作，重複執行結果一致
	 */
	public function test_重新計算冪等(): void {
		$this->make_order( 'completed' );

		RecalculateTotalSales::instance()->schedule();
		RecalculateTotalSales::instance()->process_batch( 0 );

		RecalculateTotalSales::instance()->schedule();
		RecalculateTotalSales::instance()->process_batch( 0 );

		$this->assertSame( 1, $this->get_sales( $this->course_100 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 重新計算依購買份數累加
	 */
	public function test_重新計算依份數累加(): void {
		$this->make_order( 'completed', 3 );

		RecalculateTotalSales::instance()->schedule();
		RecalculateTotalSales::instance()->process_batch( 0 );

		$this->assertSame( 3, $this->get_sales( $this->course_100 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 重新計算同步標記每筆有效訂單的計入旗標
	 */
	public function test_重新計算標記旗標(): void {
		$order = $this->make_order( 'completed' );

		RecalculateTotalSales::instance()->schedule();
		RecalculateTotalSales::instance()->process_batch( 0 );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'yes', $fresh->get_meta( TotalSalesSync::META_COUNTED ) );
	}

	/**
	 * @test
	 * @group happy
	 * schedule() 回傳已排程與課程數量
	 */
	public function test_schedule_回傳結構(): void {
		$result = RecalculateTotalSales::instance()->schedule();

		$this->assertArrayHasKey( 'scheduled', $result );
		$this->assertArrayHasKey( 'course_count', $result );
		$this->assertTrue( (bool) $result['scheduled'] );
		$this->assertGreaterThanOrEqual( 2, (int) $result['course_count'] );
	}

	/**
	 * @test
	 * @group happy
	 * schedule() 立即把所有課程 total_sales 歸零
	 */
	public function test_schedule_歸零課程(): void {
		RecalculateTotalSales::instance()->schedule();

		$this->assertSame( 0, $this->get_sales( $this->course_100 ) );
		$this->assertSame( 0, $this->get_sales( $this->course_101 ) );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 課程數量龐大時排入 Action Scheduler 背景任務
	 */
	public function test_排入action_scheduler(): void {
		RecalculateTotalSales::instance()->schedule();

		$next = \as_next_scheduled_action( RecalculateTotalSales::AS_HOOK, null, RecalculateTotalSales::AS_GROUP );
		$this->assertNotFalse( $next, '應排入 Action Scheduler 背景任務' );
	}
}
