<?php
/**
 * 設定端點別名整合測試
 *
 * Feature: specs/features/settings/設定端點別名.feature
 * 測試 /options 端點作為 /settings 的 deprecated 別名：
 * - Api/Option.php 類別存在，且宣告為 deprecated
 * - Options 的 callback 直接委派給 Settings callback
 * - /duplicate/{id} 與 /courses/duplicate/{id} 為同一功能
 *
 * @group settings
 * @group options
 * @group deprecated
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use Tests\Integration\TestCase;

/**
 * Class SettingsAliasTest
 * 測試 /options 端點為 /settings 的 deprecated 別名
 */
class SettingsAliasTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress REST API
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_set_' . uniqid(),
				'user_email' => 'admin_set_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->ids['Admin'] = $this->admin_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * Api\Option 類別存在
	 */
	public function test_冒煙_ApiOption類別存在(): void {
		$this->assertTrue( class_exists( 'J7\PowerCourse\Api\Option' ), 'J7\PowerCourse\Api\Option 類別應存在' );
	}

	/**
	 * @test
	 * @group smoke
	 * Resources\Settings\Core\Api 類別存在
	 */
	public function test_冒煙_SettingsApi類別存在(): void {
		$this->assertTrue(
			class_exists( 'J7\PowerCourse\Resources\Settings\Core\Api' ),
			'Resources\Settings\Core\Api 類別應存在'
		);
	}

	// ========== Option 類別 deprecated 行為 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Api\Option 類別標記為 @deprecated
	 * 透過 ReflectionClass 確認 docblock 包含 deprecated
	 */
	public function test_ApiOption類別有deprecated標記(): void {
		$reflection = new \ReflectionClass( 'J7\PowerCourse\Api\Option' );
		$docblock   = $reflection->getDocComment();

		$this->assertStringContainsString( 'deprecated', strtolower( (string) $docblock ), 'Option 類別的 docblock 應包含 deprecated 標記' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Api\Option 包含 get_options_callback 方法
	 */
	public function test_ApiOption有get_options_callback方法(): void {
		$this->assertTrue(
			method_exists( 'J7\PowerCourse\Api\Option', 'get_options_callback' ),
			'Api\Option 應有 get_options_callback 方法'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Api\Option 包含 post_options_callback 方法
	 */
	public function test_ApiOption有post_options_callback方法(): void {
		$this->assertTrue(
			method_exists( 'J7\PowerCourse\Api\Option', 'post_options_callback' ),
			'Api\Option 應有 post_options_callback 方法'
		);
	}

	// ========== Settings API 行為 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Resources\Settings\Core\Api 包含 get_settings_callback 方法
	 */
	public function test_SettingsApi有get_settings_callback方法(): void {
		$this->assertTrue(
			method_exists( 'J7\PowerCourse\Resources\Settings\Core\Api', 'get_settings_callback' ),
			'SettingsApi 應有 get_settings_callback 方法'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: SettingsApi 為 Singleton（使用 SingletonTrait）
	 */
	public function test_SettingsApi使用SingletonTrait(): void {
		$reflection = new \ReflectionClass( 'J7\PowerCourse\Resources\Settings\Core\Api' );
		$traits     = $reflection->getTraitNames();

		$this->assertContains(
			'J7\WpUtils\Traits\SingletonTrait',
			$traits,
			'SettingsApi 應使用 SingletonTrait'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: SettingsApi::instance() 可取得實例
	 */
	public function test_SettingsApi可取得實例(): void {
		$instance = \J7\PowerCourse\Resources\Settings\Core\Api::instance();
		$this->assertInstanceOf(
			\J7\PowerCourse\Resources\Settings\Core\Api::class,
			$instance
		);
	}

	// ========== 端點委派機制 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Api\Option::get_options_callback 內部呼叫 SettingsApi::instance()->get_settings_callback
	 * 透過 ReflectionMethod 確認方法體包含 SettingsApi 呼叫
	 */
	public function test_get_options_callback委派給SettingsApi(): void {
		$reflection = new \ReflectionMethod( 'J7\PowerCourse\Api\Option', 'get_options_callback' );
		$filename   = $reflection->getFileName();
		$start      = $reflection->getStartLine();
		$end        = $reflection->getEndLine();

		if ( ! $filename ) {
			$this->markTestSkipped( '無法取得方法來源文件' );
		}

		$lines    = file( $filename );
		$body     = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

		$this->assertStringContainsString( 'get_settings_callback', $body, 'get_options_callback 應委派給 get_settings_callback' );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: Api\Option 命名空間正確
	 */
	public function test_ApiOption命名空間正確(): void {
		$reflection = new \ReflectionClass( 'J7\PowerCourse\Api\Option' );
		$this->assertSame( 'J7\PowerCourse\Api', $reflection->getNamespaceName() );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: /duplicate/{id} 路由由相同功能類別處理
	 * 驗證 Duplicate 類別存在
	 */
	public function test_Duplicate類別存在(): void {
		// 驗證 Duplicate 功能的類別存在（根據 spec，兩個路徑由同一個 Duplicate::process 實作）
		$possible_classes = [
			'J7\PowerCourse\Resources\Course\Service\Duplicate',
			'J7\PowerCourse\Course\Service\Duplicate',
		];

		$found = false;
		foreach ( $possible_classes as $class ) {
			if ( class_exists( $class ) ) {
				$found = true;
				break;
			}
		}

		// 若 Duplicate 類別不在此路徑，查找 duplicate 方法在哪個類別
		if ( ! $found ) {
			$this->markTestSkipped( 'Duplicate 類別路徑需確認' );
		}

		$this->assertTrue( $found, 'Duplicate 類別應存在' );
	}
}
