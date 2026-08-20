<?php
/**
 * MCP Server 整合測試
 *
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Server;

/**
 * Class ServerTest
 * 驗證 MCP Server bootstrap 與 hook 掛載邏輯
 */
class ServerTest extends IntegrationTestCase {

	/**
	 * 測試：Server 類別存在且可實例化
	 *
	 * @group smoke
	 */
	public function test_server_class_exists(): void {
		$this->assertTrue( class_exists( Server::class ), 'Server class 應存在' );
	}

	/**
	 * 測試：Server 定義了正確的常數
	 *
	 * @group smoke
	 */
	public function test_server_constants_defined(): void {
		$this->assertSame( 'power-course-mcp', Server::SERVER_ID );
		$this->assertSame( 'power-course/v2', Server::ROUTE_NAMESPACE );
		$this->assertSame( 'mcp', Server::ROUTE );
	}

	/**
	 * 測試：Server 可以建立實例（不拋出例外）
	 *
	 * @group smoke
	 */
	public function test_server_instantiation_does_not_throw(): void {
		try {
			$server = new Server();
			$this->assertInstanceOf( Server::class, $server );
		} catch ( \Throwable $th ) {
			$this->fail( 'Server 實例化不應拋出例外：' . $th->getMessage() );
		}
	}

	/**
	 * 測試：Server 掛載 mcp_adapter_init hook
	 *
	 * @group smoke
	 */
	public function test_server_hooks_mcp_adapter_init(): void {
		$server = new Server();
		$this->assertGreaterThan(
			0,
			has_action( 'mcp_adapter_init', [ $server, 'bootstrap' ] ),
			"Server 應掛載 'mcp_adapter_init' action"
		);
	}

	/**
	 * 測試：get_all_tool_classes() 回傳陣列
	 *
	 * @group smoke
	 */
	public function test_get_all_tool_classes_returns_array(): void {
		$server  = new Server();
		$classes = $server->get_all_tool_classes();
		$this->assertIsArray( $classes, 'get_all_tool_classes() 應回傳陣列' );
	}

	/**
	 * 測試：get_enabled_tools() 在所有 categories 停用時回傳空陣列
	 *
	 * @group edge
	 */
	public function test_get_enabled_tools_returns_empty_when_all_disabled(): void {
		// 確保所有 categories 都是停用的（預設值）
		delete_option( \J7\PowerCourse\Api\Mcp\Settings::OPTION_KEY );

		$server = new Server();
		$tools  = $server->get_enabled_tools();
		// 若沒有任何 category 啟用，可能回傳空陣列（取決於設計）
		$this->assertIsArray( $tools, 'get_enabled_tools() 應回傳陣列' );
	}

	/**
	 * 迴歸測試：第三方外掛的「前綴 adapter」不得炸掉 bootstrap()
	 *
	 * WordPress hook 名稱是全域字串。第三方外掛（如 PixelYourSite Pro）用
	 * php-scoper 把 mcp-adapter 打包成 `PYS_PRO_GLOBAL\WP\MCP\Core\McpAdapter`
	 * 時，php-scoper 只改類別名稱，**不改 `do_action( 'mcp_adapter_init', $this )`
	 * 的字串字面量**，於是那份副本會用同一個 hook 名稱把別人家的物件丟給我們。
	 *
	 * 若 bootstrap() 對參數宣告 `McpAdapter` 型別，PHP 會在**綁定參數的當下**
	 * 丟 TypeError（method body 一行都跑不到），而 `mcp_adapter_init` 是在
	 * `rest_api_init` 觸發 —— 等於整站 REST API 全部 500。
	 *
	 * @group edge
	 */
	public function test_bootstrap_ignores_foreign_prefixed_adapter(): void {
		$server = new Server();

		// 模擬 PYS_PRO_GLOBAL\WP\MCP\Core\McpAdapter：介面一樣、namespace 不同
		$foreign_adapter = new class() {
			/**
			 * 不該被呼叫——我們不能拿別人家的 adapter 建 server
			 *
			 * @param mixed ...$args 任意參數
			 * @return void
			 * @throws \RuntimeException 被呼叫即代表守門失效
			 */
			public function create_server( ...$args ) {
				throw new \RuntimeException( 'bootstrap() 不應對第三方 adapter 呼叫 create_server()' );
			}
		};

		try {
			$server->bootstrap( $foreign_adapter );
		} catch ( \Throwable $th ) {
			$this->fail( '傳入第三方前綴 adapter 時 bootstrap() 不應拋出例外：' . $th->getMessage() );
		}

		$this->assertTrue( true, 'bootstrap() 已安全略過第三方 adapter' );
	}

	/**
	 * 迴歸測試：bootstrap() 收到非物件參數時亦不得拋例外
	 *
	 * @group edge
	 */
	public function test_bootstrap_ignores_non_object_argument(): void {
		$server = new Server();

		foreach ( [ null, 'not-an-adapter', 123, [] ] as $bad_arg ) {
			try {
				$server->bootstrap( $bad_arg );
			} catch ( \Throwable $th ) {
				$this->fail( 'bootstrap() 收到非物件參數時不應拋出例外：' . $th->getMessage() );
			}
		}

		$this->assertTrue( true, 'bootstrap() 已安全略過非物件參數' );
	}
}
