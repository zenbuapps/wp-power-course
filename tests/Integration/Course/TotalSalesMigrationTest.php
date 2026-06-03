<?php
/**
 * Issue #228 升級自動重算遷移 整合測試
 * Feature: specs/features/report/重新計算課程已售出數量.feature
 *
 * Rule: 後置（狀態）- plugin 升級到含此修正的版本時自動執行一次重新計算
 *
 * @group course
 * @group total-sales
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Compatibility\Compatibility;
use J7\PowerCourse\Resources\Course\Service\RecalculateTotalSales;

/**
 * Class TotalSalesMigrationTest
 */
class TotalSalesMigrationTest extends TestCase {

	/** @var string 遷移旗標 option key */
	private const MIGRATED_OPTION = 'pc_issue228_total_sales_migrated';

	protected function configure_dependencies(): void {
		// 確保 AS hook callback 已綁定
		RecalculateTotalSales::instance();
	}

	public function set_up(): void {
		parent::set_up();
		\delete_option( self::MIGRATED_OPTION );
		// 清掉既有排程，確保斷言乾淨
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( RecalculateTotalSales::AS_HOOK, [], RecalculateTotalSales::AS_GROUP );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Example: 升級後自動排程一次性遷移
	 */
	public function test_首次升級自動排程遷移(): void {
		$this->assertFalse( (bool) \get_option( self::MIGRATED_OPTION ) );

		Compatibility::compatibility();

		$this->assertSame( 'yes', \get_option( self::MIGRATED_OPTION ), '遷移後應設定旗標' );
	}

	/**
	 * @test
	 * @group edge
	 * Example: 已執行過遷移的站台升級不重複自動執行
	 */
	public function test_已遷移不重複排程(): void {
		// Given 已執行過遷移
		\update_option( self::MIGRATED_OPTION, 'yes' );

		// 清掉排程後再執行 compatibility
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( RecalculateTotalSales::AS_HOOK, [], RecalculateTotalSales::AS_GROUP );
		}

		Compatibility::compatibility();

		// Then 不應再次排入一次性重算
		$next = \as_next_scheduled_action( RecalculateTotalSales::AS_HOOK, null, RecalculateTotalSales::AS_GROUP );
		$this->assertFalse( $next, '已遷移過不應再次自動排程' );
	}
}
