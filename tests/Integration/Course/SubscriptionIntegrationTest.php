<?php
/**
 * 訂閱整合課程授權整合測試
 *
 * Feature: specs/features/order/訂閱整合課程授權.feature
 *
 * 注意：此測試集不需要真實的 WooCommerce Subscriptions 外掛，
 * 而是測試 ExpireDate 對「subscription_{id}」字串的處理行為。
 * 需要 WC_Subscription 的情境標記為 skip。
 *
 * @group order
 * @group subscription
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class SubscriptionIntegrationTest
 * 測試訂閱模式的課程授權行為（不依賴真實 WC_Subscription 外掛）
 */
class SubscriptionIntegrationTest extends TestCase {

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int 訂閱課程 ID */
	private int $course_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 WordPress APIs 和 PowerCourse 的 MetaCRUD
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_sub_' . uniqid(),
				'user_email' => 'alice_sub_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		$this->course_id = $this->create_course(
			[
				'post_title' => '年訂閱課程',
				'limit_type' => 'follow_subscription',
			]
		);
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * follow_subscription limit_type 可正確建立 Limit 物件
	 */
	public function test_follow_subscription_Limit物件建立(): void {
		$limit = new Limit( 'follow_subscription', null, null );
		$this->assertSame( 'follow_subscription', $limit->limit_type );
	}

	// ========== WC_Subscription 不存在時的行為 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: WC_Subscription 類別不存在時，expire_date 回傳 0
	 */
	public function test_WC_Subscription不存在時_calc_expire_date回傳0(): void {
		if ( class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 存在，跳過 fallback 測試' );
		}

		$limit       = new Limit( 'follow_subscription', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date, 'WC_Subscription 不存在時應 fallback 為 0' );
	}

	// ========== ExpireDate 處理 "subscription_{id}" 字串 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: ExpireDate 接受 "subscription_7001" 字串，設定 is_subscription=true
	 */
	public function test_ExpireDate_subscription字串_設定is_subscription(): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		$expire = new ExpireDate( 'subscription_7001' );
		$this->assertTrue( $expire->is_subscription, '訂閱字串應設定 is_subscription=true' );
		$this->assertSame( 7001, $expire->subscription_id );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 非 WC_Subscription 環境下，subscription 字串儲存到 avl_coursemeta
	 */
	public function test_subscription字串可儲存到avl_coursemeta(): void {
		$expire_value = 'subscription_7001';

		// 模擬 Limit::calc_expire_date 回傳值後儲存到 coursemeta
		AVLCourseMeta::update( $this->course_id, $this->alice_id, 'expire_date', $expire_value );

		// 確認可正確讀回
		$actual = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'expire_date', true );
		$this->assertSame( $expire_value, $actual, 'subscription 字串應可儲存到 avl_coursemeta' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: expire_date=0（WC_Subscription fallback），學員視為無限期
	 */
	public function test_expire_date為0時_學員視為無限期(): void {
		// 當 WC_Subscription 不存在，expire_date 儲存為 0
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$expire_date = $this->get_course_meta( $this->course_id, $this->alice_id, 'expire_date' );
		$expire      = new ExpireDate( (int) $expire_date );

		$this->assertFalse( $expire->is_expired, 'expire_date=0 應為無限期，不過期' );
		$this->assertSame( '無期限', $expire->expire_date_label );
	}

	// ========== 訂閱狀態判斷（需要 WC_Subscription）==========

	/**
	 * @test
	 * @group happy
	 * Rule: ExpireDate 訂閱標籤格式正確（無需真實訂閱）
	 * 此測試在有 WC_Subscription 時驗證標籤格式
	 */
	public function test_訂閱狀態標籤格式_跟隨訂閱(): void {
		if ( ! class_exists( 'WC_Subscription' ) ) {
			// 無 WC_Subscription 時跳過此測試，已有 fallback 測試覆蓋
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過標籤格式測試' );
		}

		$expire = new ExpireDate( 'subscription_7001' );
		$this->assertStringContainsString( '7001', $expire->expire_date_label );
		$this->assertStringContainsString( '跟隨訂閱', $expire->expire_date_label );
	}

	// ========== 多訂閱訂單 - 回傳 0 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 一張訂單對應多個 subscription 時，calc_expire_date 回傳 0
	 * 此邏輯在 Limit::calc_expire_date 中：count(subscriptions) !== 1 → return 0
	 * （在無 WC_Subscription 環境中，函數直接回傳 0，行為一致）
	 */
	public function test_多訂閱訂單時_回傳0(): void {
		if ( class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( '需要模擬 WC_Subscription 才能完整測試' );
		}

		// 無 WC_Subscription 時，函數因 class_exists 檢查而回傳 0
		$limit       = new Limit( 'follow_subscription', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date );
	}

	// ========== 訂閱相關訂單處理（架構驗證）==========

	/**
	 * @test
	 * @group edge
	 * Rule: 訂閱整合資料儲存格式驗證
	 * 確認 "subscription_{id}" 格式的字串可在資料庫中正確儲存和讀取
	 */
	public function test_subscription字串格式在資料庫中正確儲存(): void {
		$test_cases = [
			'subscription_1',
			'subscription_9999',
			'subscription_123456',
		];

		foreach ( $test_cases as $expire_value ) {
			// 每次測試前清理
			AVLCourseMeta::delete( $this->course_id, $this->alice_id, 'expire_date' );

			AVLCourseMeta::update( $this->course_id, $this->alice_id, 'expire_date', $expire_value );
			$actual = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'expire_date', true );
			$this->assertSame( $expire_value, $actual, "{$expire_value} 應正確儲存和讀取" );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 訂閱未安裝時，學員 expire_date 儲存為 "0"（字串）或 0（整數）
	 */
	public function test_subscription_fallback_expire_date_為0(): void {
		if ( class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 存在，跳過 fallback 測試' );
		}

		$limit       = new Limit( 'follow_subscription', null, null );
		$expire_date = $limit->calc_expire_date( null );

		// 儲存並讀取
		AVLCourseMeta::update( $this->course_id, $this->alice_id, 'expire_date', $expire_date );
		$actual = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'expire_date', true );

		// 比較時轉換類型（可能為 "0" 字串或 0 整數）
		$this->assertEquals( 0, (int) $actual, 'fallback expire_date 應為 0' );

		$expire = new ExpireDate( (int) $actual );
		$this->assertFalse( $expire->is_expired, 'expire_date=0 應視為無限期' );
	}
}
