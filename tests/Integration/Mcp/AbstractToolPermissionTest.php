<?php
/**
 * MCP AbstractTool 權限控制（Issue #217）整合測試
 *
 * 覆蓋三條決策路徑：
 * - OP_READ 始終允許
 * - OP_UPDATE 受 Settings::allow_update 控制
 * - OP_DELETE 受 Settings::allow_delete 控制
 * 並驗證環境變數 ALLOW_UPDATE / ALLOW_DELETE 已不再生效
 *
 * @group security
 * @group mcp
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\AbstractTool;
use J7\PowerCourse\Api\Mcp\Settings;

/**
 * Class AbstractToolPermissionTest
 */
class AbstractToolPermissionTest extends IntegrationTestCase {

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_KEY );
		// 給 admin 通過 capability，讓 permission_callback 不會擋
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
	}

	/**
	 * 建立指定操作類型的 tool stub
	 *
	 * @param string $op_type 操作類型 OP_READ | OP_UPDATE | OP_DELETE
	 * @return AbstractTool
	 */
	private function make_tool( string $op_type ): AbstractTool {
		return new class( $op_type ) extends AbstractTool {
			public function __construct( private string $forced_op ) {}

			public function get_name(): string { return 'permission_test_tool'; }
			public function get_label(): string { return 'Permission Test Tool'; }
			public function get_description(): string { return 'Stub for permission test'; }
			public function get_input_schema(): array {
				return [ 'type' => 'object', 'properties' => [] ];
			}
			public function get_output_schema(): array {
				return [ 'type' => 'object', 'properties' => [ 'ok' => [ 'type' => 'boolean' ] ] ];
			}
			public function get_capability(): string { return 'manage_woocommerce'; }
			public function get_category(): string { return 'course'; }
			public function get_operation_type(): string { return $this->forced_op; }
			protected function execute( array $args ): mixed { return [ 'ok' => true ]; }
		};
	}

	/**
	 * OP_READ 不論 settings 為何都應通過
	 *
	 * @group smoke
	 */
	public function test_read_op_always_allowed(): void {
		$tool = $this->make_tool( AbstractTool::OP_READ );

		// 預設 settings 全 false
		$result = $tool->run( [] );
		$this->assertSame( [ 'ok' => true ], $result, 'OP_READ 應始終被允許' );
	}

	/**
	 * 預設 allow_update=false 時，OP_UPDATE 應被擋
	 *
	 * @group security
	 */
	public function test_update_op_blocked_when_setting_false(): void {
		$tool   = $this->make_tool( AbstractTool::OP_UPDATE );
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_operation_not_allowed', $result->get_error_code() );
	}

	/**
	 * allow_update=true 時，OP_UPDATE 應通過
	 *
	 * @group happy
	 */
	public function test_update_op_allowed_when_setting_true(): void {
		( new Settings() )->set_update_allowed( true );

		$tool   = $this->make_tool( AbstractTool::OP_UPDATE );
		$result = $tool->run( [] );

		$this->assertSame( [ 'ok' => true ], $result );
	}

	/**
	 * 預設 allow_delete=false 時，OP_DELETE 應被擋
	 *
	 * @group security
	 */
	public function test_delete_op_blocked_when_setting_false(): void {
		$tool   = $this->make_tool( AbstractTool::OP_DELETE );
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_operation_not_allowed', $result->get_error_code() );
	}

	/**
	 * allow_delete=true 時，OP_DELETE 應通過
	 *
	 * @group happy
	 */
	public function test_delete_op_allowed_when_setting_true(): void {
		( new Settings() )->set_delete_allowed( true );

		$tool   = $this->make_tool( AbstractTool::OP_DELETE );
		$result = $tool->run( [] );

		$this->assertSame( [ 'ok' => true ], $result );
	}

	/**
	 * 兩個欄位互不影響：allow_update=true 時，OP_DELETE 仍應被擋
	 *
	 * @group security
	 */
	public function test_update_setting_does_not_affect_delete_op(): void {
		( new Settings() )->set_update_allowed( true );

		$tool   = $this->make_tool( AbstractTool::OP_DELETE );
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_operation_not_allowed', $result->get_error_code() );
	}

	/**
	 * 拒絕時 error code 必須是 mcp_operation_not_allowed
	 * 外部呼叫者依賴此 code 做錯誤分支
	 *
	 * @group security
	 */
	public function test_error_code_is_mcp_operation_not_allowed(): void {
		$tool   = $this->make_tool( AbstractTool::OP_UPDATE );
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_operation_not_allowed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 403, $data['status'] ?? null );
	}

	/**
	 * 錯誤訊息必須提供具體後台路徑指引（讓站長知道去哪裡開啟）
	 *
	 * @group security
	 */
	public function test_error_message_mentions_settings_path(): void {
		$tool   = $this->make_tool( AbstractTool::OP_UPDATE );
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$message = $result->get_error_message();
		$this->assertStringContainsString( 'Settings', $message );
		$this->assertStringContainsString( 'AI', $message );
	}

	/**
	 * regression 防護：環境變數 ALLOW_UPDATE / ALLOW_DELETE 已不再生效
	 * 即使設成 1，沒開 settings 仍應被擋
	 *
	 * @group security
	 */
	public function test_environment_variables_are_ignored(): void {
		putenv( 'ALLOW_UPDATE=1' );
		putenv( 'ALLOW_DELETE=1' );

		try {
			$update_tool   = $this->make_tool( AbstractTool::OP_UPDATE );
			$update_result = $update_tool->run( [] );
			$this->assertInstanceOf( \WP_Error::class, $update_result, 'env var ALLOW_UPDATE 應被忽略' );

			$delete_tool   = $this->make_tool( AbstractTool::OP_DELETE );
			$delete_result = $delete_tool->run( [] );
			$this->assertInstanceOf( \WP_Error::class, $delete_result, 'env var ALLOW_DELETE 應被忽略' );
		} finally {
			putenv( 'ALLOW_UPDATE' );
			putenv( 'ALLOW_DELETE' );
		}
	}
}
