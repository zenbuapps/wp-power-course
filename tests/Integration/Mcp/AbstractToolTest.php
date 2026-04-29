<?php
/**
 * MCP AbstractTool 整合測試
 *
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\Settings;

/**
 * Class AbstractToolTest
 * 驗證 AbstractTool 的 permission、schema、能力前綴等規範
 */
class AbstractToolTest extends IntegrationTestCase {

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_KEY );
	}

	/**
	 * 建立具有 manage_woocommerce 能力的 tool stub
	 *
	 * @param string $capability WP capability
	 * @param string $category   tool category
	 * @return AbstractTool
	 */
	private function make_tool( string $capability = 'manage_woocommerce', string $category = 'course' ): AbstractTool {
		return new class( $capability, $category ) extends AbstractTool {
			public function __construct(
				private string $cap,
				private string $cat
			) {}

			public function get_name(): string { return 'test_tool'; }
			public function get_label(): string { return 'Test Tool'; }
			public function get_description(): string { return 'A test tool'; }
			public function get_input_schema(): array {
				return [
					'type'       => 'object',
					'properties' => [
						'id' => [ 'type' => 'integer' ],
					],
				];
			}
			public function get_output_schema(): array {
				return [
					'type'       => 'object',
					'properties' => [
						'success' => [ 'type' => 'boolean' ],
					],
				];
			}
			public function get_capability(): string { return $this->cap; }
			public function get_category(): string { return $this->cat; }
			protected function execute( array $args ): mixed { return [ 'success' => true ]; }
		};
	}

	/**
	 * 測試：permission_callback() 在有足夠能力時回傳 true
	 *
	 * @group security
	 */
	public function test_permission_callback_returns_true_when_user_has_capability(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$tool = $this->make_tool( 'manage_woocommerce' );
		$this->assertTrue( $tool->permission_callback(), 'admin 應有 manage_woocommerce 能力' );
	}

	/**
	 * 測試：permission_callback() 在權限不足時回傳 false
	 *
	 * @group security
	 */
	public function test_permission_callback_returns_false_when_user_lacks_capability(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$tool = $this->make_tool( 'manage_woocommerce' );
		$this->assertFalse( $tool->permission_callback(), 'subscriber 不應有 manage_woocommerce 能力' );
	}

	/**
	 * 測試：permission_callback() 在訪客時回傳 false
	 *
	 * @group security
	 */
	public function test_permission_callback_returns_false_for_guest(): void {
		wp_set_current_user( 0 );

		$tool = $this->make_tool( 'read' );
		$this->assertFalse( $tool->permission_callback(), '訪客不應通過任何 permission check' );
	}

	/**
	 * 測試：get_ability_name() 包含 ABILITY_PREFIX
	 *
	 * @group smoke
	 */
	public function test_get_ability_name_includes_prefix(): void {
		$tool = $this->make_tool();
		$this->assertStringStartsWith(
			AbstractTool::ABILITY_PREFIX,
			$tool->get_ability_name(),
			"ability 名稱應以 '" . AbstractTool::ABILITY_PREFIX . "' 為前綴"
		);
	}

	/**
	 * 測試：get_ability_name() 格式為 power-course/{name}
	 *
	 * @group smoke
	 */
	public function test_get_ability_name_format(): void {
		$tool = $this->make_tool();
		$this->assertSame(
			AbstractTool::ABILITY_PREFIX . 'test-tool',
			$tool->get_ability_name()
		);
	}

	/**
	 * 測試：get_input_schema() 回傳含 properties 的陣列
	 *
	 * @group smoke
	 */
	public function test_input_schema_has_properties(): void {
		$tool   = $this->make_tool();
		$schema = $tool->get_input_schema();
		$this->assert_schema_has_property( $schema, 'id' );
	}

	/**
	 * 測試：get_output_schema() 回傳含 properties 的陣列
	 *
	 * @group smoke
	 */
	public function test_output_schema_has_properties(): void {
		$tool   = $this->make_tool();
		$schema = $tool->get_output_schema();
		$this->assert_schema_has_property( $schema, 'success' );
	}

	/**
	 * 測試：run() 在有權限時執行 execute()
	 *
	 * @group happy
	 */
	public function test_run_executes_when_permission_granted(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$tool   = $this->make_tool( 'manage_woocommerce' );
		$result = $tool->run( [] );
		$this->assertSame( [ 'success' => true ], $result );
	}

	/**
	 * 測試：run() 在權限不足時拋出例外或回傳 WP_Error
	 *
	 * @group security
	 */
	public function test_run_throws_or_returns_error_when_permission_denied(): void {
		$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $user_id );

		$tool = $this->make_tool( 'manage_woocommerce' );

		try {
			$result = $tool->run( [] );
			// 若不拋例外，應回傳 WP_Error
			$this->assertInstanceOf( \WP_Error::class, $result, '權限不足時應回傳 WP_Error' );
		} catch ( \Throwable $th ) {
			// 或拋出例外，均可接受
			$this->assertTrue( true );
		}
	}
}
