<?php
/**
 * MCP Settings 整合測試
 *
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Settings;

/**
 * Class SettingsTest
 * 驗證 MCP Settings 讀寫邏輯
 */
class SettingsTest extends IntegrationTestCase {

	/**
	 * 設定（每個測試前清除 options）
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_KEY );
	}

	/**
	 * 測試：is_server_enabled() 預設為 false
	 *
	 * @group smoke
	 */
	public function test_server_disabled_by_default(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->is_server_enabled() );
	}

	/**
	 * 測試：get_enabled_categories() 預設為空陣列
	 *
	 * @group smoke
	 */
	public function test_enabled_categories_default_empty(): void {
		$settings = new Settings();
		$this->assertSame( [], $settings->get_enabled_categories() );
	}

	/**
	 * 測試：set_enabled_categories() 寫入後可以讀取
	 *
	 * @group happy
	 */
	public function test_set_and_get_enabled_categories(): void {
		$settings = new Settings();
		$cats     = [ 'course', 'chapter', 'student' ];
		$settings->set_enabled_categories( $cats );
		$this->assertSame( $cats, $settings->get_enabled_categories() );
	}

	/**
	 * 測試：is_category_enabled() 正確判斷
	 *
	 * @group happy
	 */
	public function test_is_category_enabled(): void {
		$settings = new Settings();
		$settings->set_enabled_categories( [ 'course', 'chapter' ] );

		$this->assertTrue( $settings->is_category_enabled( 'course' ) );
		$this->assertTrue( $settings->is_category_enabled( 'chapter' ) );
		$this->assertFalse( $settings->is_category_enabled( 'student' ) );
		$this->assertFalse( $settings->is_category_enabled( 'teacher' ) );
	}

	/**
	 * 測試：get_rate_limit() 回傳正整數
	 *
	 * @group smoke
	 */
	public function test_get_rate_limit_returns_positive_int(): void {
		$settings = new Settings();
		$this->assertGreaterThan( 0, $settings->get_rate_limit() );
	}

	/**
	 * 測試：Options 儲存於 WordPress options 表
	 *
	 * @group happy
	 */
	public function test_settings_persisted_in_options(): void {
		$settings = new Settings();
		$settings->set_enabled_categories( [ 'order' ] );

		// 重新實例化模擬不同請求讀取
		$settings2 = new Settings();
		$this->assertSame( [ 'order' ], $settings2->get_enabled_categories() );
	}

	/**
	 * 測試：empty categories 時，is_category_enabled 不論什麼 category 都是 false
	 *
	 * @group edge
	 */
	public function test_empty_categories_returns_false_for_any(): void {
		$settings = new Settings();
		$settings->set_enabled_categories( [] );

		$this->assertFalse( $settings->is_category_enabled( 'course' ) );
		$this->assertFalse( $settings->is_category_enabled( 'student' ) );
	}

	/**
	 * 測試：is_update_allowed() 預設為 false（Issue #217 預設唯讀）
	 *
	 * @group smoke
	 */
	public function test_allow_update_default_false(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->is_update_allowed(), '預設應為唯讀（allow_update = false）' );
	}

	/**
	 * 測試：is_delete_allowed() 預設為 false（Issue #217 預設唯讀）
	 *
	 * @group smoke
	 */
	public function test_allow_delete_default_false(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->is_delete_allowed(), '預設應為唯讀（allow_delete = false）' );
	}

	/**
	 * 測試：set_update_allowed / set_delete_allowed 可寫入並讀回
	 *
	 * @group happy
	 */
	public function test_set_and_get_allow_update_delete(): void {
		$settings = new Settings();

		$settings->set_update_allowed( true );
		$this->assertTrue( $settings->is_update_allowed() );

		$settings->set_delete_allowed( true );
		$this->assertTrue( $settings->is_delete_allowed() );

		// 兩個欄位互不影響
		$settings->set_update_allowed( false );
		$this->assertFalse( $settings->is_update_allowed() );
		$this->assertTrue( $settings->is_delete_allowed() );
	}

	/**
	 * 測試：兩個權限欄位獨立持久化（Issue #217 站長可獨立切換）
	 *
	 * @group happy
	 */
	public function test_allow_flags_persisted_independently(): void {
		$settings = new Settings();
		$settings->set_update_allowed( true );
		$settings->set_delete_allowed( false );

		// 模擬不同請求重新讀取
		$settings2 = new Settings();
		$this->assertTrue( $settings2->is_update_allowed() );
		$this->assertFalse( $settings2->is_delete_allowed() );
	}
}
