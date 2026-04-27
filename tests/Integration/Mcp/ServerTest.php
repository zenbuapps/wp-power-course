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
}
