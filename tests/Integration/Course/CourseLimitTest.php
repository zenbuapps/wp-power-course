<?php
/**
 * 課程到期限制計算整合測試
 *
 * Feature: specs/features/course/課程到期限制計算.feature
 * 測試 Limit::calc_expire_date() 的 4 種 limit_type 行為、
 * ExpireDate 的標籤與是否過期判斷，以及邊緣案例。
 *
 * @group course
 * @group limit
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Course\ExpireDate;

/**
 * Class CourseLimitTest
 * 測試課程觀看期限的計算邏輯
 */
class CourseLimitTest extends TestCase {

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// Limit / ExpireDate 為純值物件，不需要額外依賴
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 建立 Limit 物件不拋例外
	 */
	public function test_建立Limit物件不拋例外(): void {
		$limit = new Limit( 'unlimited', null, null );
		$this->assertSame( 'unlimited', $limit->limit_type );
	}

	// ========== unlimited ==========

	/**
	 * @test
	 * @group happy
	 * Rule: limit_type=unlimited 時，expire_date 為 0（無期限）
	 */
	public function test_unlimited_calc_expire_date_回傳0(): void {
		$limit       = new Limit( 'unlimited', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date, 'unlimited 應回傳 0' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: ExpireDate is_expired=false 且 label=無期限
	 */
	public function test_unlimited_ExpireDate_不過期且標籤為無期限(): void {
		$expire = new ExpireDate( 0 );

		$this->assertFalse( $expire->is_expired, 'expire_date=0 應為不過期' );
		$this->assertSame( '無期限', $expire->expire_date_label );
	}

	// ========== fixed ==========

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 30 天，到期日為開通日+30天的 15:59:00
	 */
	public function test_fixed_30天_expire_date計算正確(): void {
		$limit = new Limit( 'fixed', 30, 'day' );
		// 以目前時間計算
		$expire_date = (int) $limit->calc_expire_date( null );

		// 應為今天 +30 天的 15:59:00
		$expected_date_str = date( 'Y-m-d', strtotime( '+30 days' ) ) . ' 15:59:00';
		$expected          = (int) strtotime( $expected_date_str );

		$this->assertSame( $expected, $expire_date, 'fixed 30天應計算到正確 timestamp' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 6 個月，到期日為開通日+6月的 15:59:00
	 */
	public function test_fixed_6個月_expire_date計算正確(): void {
		$limit       = new Limit( 'fixed', 6, 'month' );
		$expire_date = (int) $limit->calc_expire_date( null );

		$expected_date_str = date( 'Y-m-d', strtotime( '+6 months' ) ) . ' 15:59:00';
		$expected          = (int) strtotime( $expected_date_str );

		$this->assertSame( $expected, $expire_date, 'fixed 6個月應計算到正確 timestamp' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 1 年，到期日為開通日+1年的 15:59:00
	 */
	public function test_fixed_1年_expire_date計算正確(): void {
		$limit       = new Limit( 'fixed', 1, 'year' );
		$expire_date = (int) $limit->calc_expire_date( null );

		$expected_date_str = date( 'Y-m-d', strtotime( '+1 year' ) ) . ' 15:59:00';
		$expected          = (int) strtotime( $expected_date_str );

		$this->assertSame( $expected, $expire_date, 'fixed 1年應計算到正確 timestamp' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: fixed 模式到期日時間固定截至 15:59:00
	 */
	public function test_fixed_到期時間固定為15時59分(): void {
		$limit       = new Limit( 'fixed', 7, 'day' );
		$expire_date = (int) $limit->calc_expire_date( null );

		// 轉換回時間字串，確認時間部分為 15:59:00
		$time_part = date( 'H:i:s', $expire_date );
		$this->assertSame( '15:59:00', $time_part, 'fixed 到期時間應固定為 15:59:00' );
	}

	// ========== fixed limit_value 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: limit_value 為 0 時，set_limit_value 設為 null
	 */
	public function test_fixed_limit_value為0時設為null(): void {
		$limit = new Limit( 'fixed', 0, 'day' );
		$this->assertNull( $limit->limit_value, 'limit_value=0 應被轉換為 null' );
	}

	// ========== assigned ==========

	/**
	 * @test
	 * @group happy
	 * Rule: limit_type=assigned 時，expire_date 為 limit_value（timestamp 原值）
	 */
	public function test_assigned_回傳limit_value原值(): void {
		$target_timestamp = 1767225599; // 2026-12-31 15:59:59 UTC
		$limit            = new Limit( 'assigned', $target_timestamp, null );
		$expire_date      = $limit->calc_expire_date( null );

		$this->assertSame( $target_timestamp, $expire_date, 'assigned 應回傳 limit_value 原值' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: ExpireDate 對 assigned timestamp 顯示正確標籤
	 * 使用距今 1 年後的 timestamp，確保測試時間點在有效期內
	 */
	public function test_assigned_ExpireDate標籤顯示正確(): void {
		// 使用未來 1 年的 timestamp（確保測試不因日期過期而失敗）
		$target_timestamp = (int) strtotime( '+1 year' );
		$expire           = new ExpireDate( $target_timestamp );

		// 非 0，非訂閱，應顯示日期字串
		$this->assertNotEmpty( $expire->expire_date_label );
		$this->assertNotSame( '無期限', $expire->expire_date_label );
		$this->assertFalse( $expire->is_expired, '未來日期不應過期' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 過去 timestamp 的 ExpireDate.is_expired 為 true
	 */
	public function test_assigned_過去timestamp已過期(): void {
		$past_timestamp = strtotime( '-1 day' );
		$expire         = new ExpireDate( (int) $past_timestamp );

		$this->assertTrue( $expire->is_expired, '過去時間應標記為已過期' );
	}

	// ========== follow_subscription ==========

	/**
	 * @test
	 * @group edge
	 * Rule: WC_Subscription 類別不存在時，follow_subscription 降級為 0
	 */
	public function test_follow_subscription_WC_Subscription不存在時回傳0(): void {
		// 在測試環境中 WC_Subscription 類別通常不存在
		if ( class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 存在，跳過 fallback 測試' );
		}

		$limit       = new Limit( 'follow_subscription', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date, 'WC_Subscription 不存在時應回傳 0' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: follow_subscription 沒有訂單時回傳 0
	 */
	public function test_follow_subscription_無訂單時回傳0(): void {
		if ( class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 存在，此測試行為不同' );
		}

		$limit       = new Limit( 'follow_subscription', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date );
	}

	// ========== 非法 limit_type ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 非法 limit_type 字串會被 fallback 為 "unlimited"
	 */
	public function test_非法limit_type_fallback為unlimited(): void {
		$limit = new Limit( 'unknown_type', null, null );

		$this->assertSame( 'unlimited', $limit->limit_type, '非法 limit_type 應 fallback 為 unlimited' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 非法 limit_type 計算 expire_date 同樣回傳 0
	 */
	public function test_非法limit_type_calc_expire_date回傳0(): void {
		$limit       = new Limit( 'invalid', null, null );
		$expire_date = $limit->calc_expire_date( null );

		$this->assertSame( 0, $expire_date );
	}

	// ========== 非法 limit_unit ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 非法 limit_unit 不會拋錯，limit_unit 保留原值
	 */
	public function test_非法limit_unit_保留原值不拋錯(): void {
		// 非法單位 "hour" 應不拋出例外
		$limit = new Limit( 'fixed', 5, 'hour' );

		// 依 spec：limit_unit 仍保留為 "hour"（不 fallback）
		$this->assertSame( 'hour', $limit->limit_unit, '非法 limit_unit 應保留原值' );
	}

	// ========== ExpireDate — 空字串 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: expire_date 為空字串時視為過期
	 */
	public function test_expire_date空字串時視為過期(): void {
		$expire = new ExpireDate( '' );

		$this->assertTrue( $expire->is_expired, 'expire_date="" 應視為過期' );
		$this->assertSame( '無期限', $expire->expire_date_label );
	}
}
