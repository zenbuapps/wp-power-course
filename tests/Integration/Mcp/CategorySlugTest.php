<?php
/**
 * MCP category slug 合法性與 ability 註冊迴歸測試（Issue #259）
 *
 * Bug: `Server::CATEGORIES` 中 `contact_remark` / `student_log` 兩個 slug 帶底線，
 *      不符 WP Abilities API 的 category slug regex `/^[a-z0-9]+(?:-[a-z0-9]+)*$/`。
 *      category 註冊失敗 → 掛在其下的 5 支 ability 註冊全部中止
 *      （`WP_Abilities_Registry::register()` 檢查 `wp_has_ability_category()` 不過即 return null）
 *      → mcp-adapter 每次 WP 載入逐支 `wp_get_ability()` 查不到，
 *      每次載入寫 5 行 ERROR log（回報者 fleet 上 8 天累積 543 萬行 / 1.4 GB）。
 *
 * 修復：
 * 1. CATEGORIES 的 slug 改 dash，5 支 tool 的 get_category() 同步。
 * 2. AbstractTool::normalize_category_slug() 作為單一收斂點，註冊與比對兩側共用。
 * 3. Server::bootstrap() 加 is_server_enabled() 把關（設定關閉就不建 server）。
 * 4. Server::filter_registered_abilities() 作為防線，未註冊成功的 ability 不丟給 mcp-adapter。
 *
 * @group mcp
 * @group issue-259
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Server;
use J7\PowerCourse\Api\Mcp\Settings;
use J7\PowerCourse\Api\Mcp\AbstractTool;
use WP\MCP\Core\McpAdapter;

/**
 * Class CategorySlugTest
 */
class CategorySlugTest extends IntegrationTestCase {

	/**
	 * WP Abilities API 對 category slug 的規則
	 * 見 WP_Ability_Categories_Registry::register()
	 */
	private const CATEGORY_SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

	/**
	 * Issue #259 中註冊失敗的 5 支 ability
	 *
	 * @var array<string>
	 */
	private const REGRESSION_ABILITIES = [
		'power-course/contact-remark-list',
		'power-course/contact-remark-create',
		'power-course/contact-remark-delete',
		'power-course/student-log-list',
		'power-course/student-log-count',
	];

	// ========== 核心迴歸：slug 合法性 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: CATEGORIES 的每個 slug 都必須符合 Abilities API 的 category regex
	 */
	public function test_迴歸_所有category_slug皆符合abilities_api規則(): void {
		foreach ( array_keys( Server::CATEGORIES ) as $slug ) {
			$this->assertMatchesRegularExpression(
				self::CATEGORY_SLUG_PATTERN,
				(string) $slug,
				"category slug '{$slug}' 不符合 Abilities API 規則（不得含底線），會導致該 category 下的 ability 全數註冊失敗"
			);
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Issue #259 的兩個問題 slug 已改為 dash 版本
	 */
	public function test_迴歸_contact_remark與student_log已改為dash(): void {
		$slugs = array_keys( Server::CATEGORIES );

		$this->assertContains( 'contact-remark', $slugs );
		$this->assertContains( 'student-log', $slugs );
		$this->assertNotContains( 'contact_remark', $slugs, '底線版本必須完全消失，否則 category 註冊會失敗' );
		$this->assertNotContains( 'student_log', $slugs, '底線版本必須完全消失，否則 category 註冊會失敗' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 每支 tool 宣告的 category 都必須存在於 CATEGORIES（正規化後比對）
	 */
	public function test_每支tool的category都在CATEGORIES清單內(): void {
		$server         = new Server();
		$known_slugs    = array_map(
			[ AbstractTool::class, 'normalize_category_slug' ],
			array_map( 'strval', array_keys( Server::CATEGORIES ) )
		);

		foreach ( $server->get_all_tool_classes() as $tool_class ) {
			if ( ! class_exists( $tool_class ) ) {
				continue;
			}

			/** @var AbstractTool $tool */
			$tool = new $tool_class();

			$this->assertContains(
				$tool->get_category_slug(),
				$known_slugs,
				"{$tool_class} 的 category '{$tool->get_category()}' 不在 Server::CATEGORIES 中，ability 會註冊失敗"
			);
		}
	}

	// ========== 核心迴歸：實際註冊結果 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: CATEGORIES 中的每個 category 都必須真的註冊成功
	 */
	public function test_迴歸_所有category都註冊成功(): void {
		if ( ! function_exists( 'wp_has_ability_category' ) ) {
			$this->markTestSkipped( 'Abilities API 未載入' );
		}

		foreach ( array_keys( Server::CATEGORIES ) as $slug ) {
			$this->assertTrue(
				wp_has_ability_category( (string) $slug ),
				"category '{$slug}' 未註冊成功（slug 不合法時 wp_register_ability_category 會靜默失敗）"
			);
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Issue #259 的 5 支 ability 必須全部註冊成功
	 *       （這 5 支正是「每次 WP 載入噴 ERROR log」的來源）
	 */
	public function test_迴歸_五支問題ability全部註冊成功(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API 未載入' );
		}

		foreach ( self::REGRESSION_ABILITIES as $ability_name ) {
			$this->assertNotNull(
				wp_get_ability( $ability_name ),
				"ability '{$ability_name}' 未註冊成功——這正是 Issue #259 每次載入寫一行 ERROR log 的成因"
			);
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: get_enabled_tools() 列出的每一支 ability 都必須真的存在
	 *       （「設定上啟用」與「實際註冊成功」不得脫節）
	 */
	public function test_迴歸_啟用清單中的ability都真的存在(): void {
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$this->markTestSkipped( 'Abilities API 未載入' );
		}

		delete_option( Settings::OPTION_KEY ); // 空 enabled_categories = 全部啟用

		$server  = new Server();
		$missing = [];

		foreach ( $server->get_enabled_tools() as $ability_name ) {
			if ( null === wp_get_ability( $ability_name ) ) {
				$missing[] = $ability_name;
			}
		}

		$this->assertSame(
			[],
			$missing,
			'以下 ability 出現在啟用清單卻未註冊成功，會讓 mcp-adapter 每次載入寫一行 ERROR log：' . implode( ', ', $missing )
		);
	}

	// ========== Settings 向下相容 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 舊站 option 內存的底線版 slug，改名後仍要能對應到 dash 版 category
	 */
	public function test_邊緣_舊底線slug設定仍可啟用對應category(): void {
		$settings = new Settings();
		$settings->set_enabled_categories( [ 'contact_remark', 'student_log' ] );

		$this->assertTrue(
			$settings->is_category_enabled( 'contact-remark' ),
			'Issue #259 之前存進 option 的 contact_remark 必須仍能啟用 contact-remark，否則升級後 tool 會憑空消失'
		);
		$this->assertTrue(
			$settings->is_category_enabled( 'student-log' ),
			'Issue #259 之前存進 option 的 student_log 必須仍能啟用 student-log'
		);
		$this->assertFalse(
			$settings->is_category_enabled( 'course' ),
			'未列入 enabled_categories 的 category 仍應為停用'
		);

		delete_option( Settings::OPTION_KEY );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: normalize_category_slug() 只動底線，其餘原樣保留
	 */
	public function test_邊緣_normalize只把底線轉dash(): void {
		$this->assertSame( 'contact-remark', AbstractTool::normalize_category_slug( 'contact_remark' ) );
		$this->assertSame( 'contact-remark', AbstractTool::normalize_category_slug( 'contact-remark' ) );
		$this->assertSame( 'course', AbstractTool::normalize_category_slug( 'course' ) );
		$this->assertSame( '', AbstractTool::normalize_category_slug( '' ) );
	}

	// ========== bootstrap 的 server 開關把關 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: MCP Server 停用時，bootstrap() 不得建立 server
	 *
	 * 驗證手法：create_server() 若在 mcp_adapter_init 以外被呼叫，會觸發
	 * _doing_it_wrong( 'create_server', ... )。WP 測試框架預設把非預期的
	 * _doing_it_wrong 視為失敗——所以「這個測試沒有 fail」本身就證明
	 * bootstrap() 提早 return、沒碰 create_server。
	 */
	public function test_邊緣_server停用時bootstrap不建立server(): void {
		$settings = new Settings();
		$settings->set_server_enabled( false );

		$server = new Server();
		$server->bootstrap( McpAdapter::instance() );

		$this->assertNull(
			McpAdapter::instance()->get_server( Server::SERVER_ID ),
			'MCP Server 設定為停用時不應建立 server——否則等於為一個沒開的功能持續註冊 tool 並寫 log'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: MCP Server 啟用時，bootstrap() 會走到 create_server（不再提早 return）
	 *
	 * 這裡刻意在 mcp_adapter_init 之外呼叫，因此預期會收到 mcp-adapter 的
	 * _doing_it_wrong；收到它就代表流程確實走到了建立 server 這一步。
	 */
	public function test_快樂_server啟用時bootstrap會走到create_server(): void {
		$this->setExpectedIncorrectUsage( 'create_server' );

		$settings = new Settings();
		$settings->set_server_enabled( true );

		$server = new Server();
		$server->bootstrap( McpAdapter::instance() );

		$settings->set_server_enabled( false );
	}
}
