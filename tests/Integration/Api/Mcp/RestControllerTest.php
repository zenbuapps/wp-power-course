<?php
/**
 * MCP RestController 整合測試
 *
 * 涵蓋 6 條路由 + 權限 + Token 明文只回一次 + 分頁過濾。
 *
 * @group mcp
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp;

use J7\PowerCourse\Api\Mcp\Auth;
use J7\PowerCourse\Api\Mcp\Migration;
use J7\PowerCourse\Api\Mcp\RestController;
use J7\PowerCourse\Api\Mcp\Settings;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class RestControllerTest
 * 驗證 GET/POST mcp/settings、GET/POST mcp/tokens、DELETE mcp/tokens/{id}、GET mcp/activity
 */
class RestControllerTest extends IntegrationTestCase {

	/** REST namespace */
	private const NS = 'power-course/v2';

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		// 確保 RestController 已 instantiate，讓 rest_api_init 有綁到路由
		RestController::instance();
		// 觸發 rest_api_init（測試環境下要手動觸發才會真正註冊）
		do_action( 'rest_api_init' );
	}

	/**
	 * 清理 MCP options，避免跨測試污染
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	// ========== GET /mcp/settings ==========

	/**
	 * GET mcp/settings 未登入 → 403
	 *
	 * @group security
	 */
	public function test_get_settings_without_login_returns_403(): void {
		$this->set_guest_user();
		$request  = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/settings' );
		$response = \rest_do_request( $request );

		$this->assertSame( 403, $response->get_status(), '未登入應回 403' );
	}

	/**
	 * GET mcp/settings admin → 200 + 當前設定
	 *
	 * @group happy
	 */
	public function test_get_settings_as_admin_returns_current_settings(): void {
		$this->create_admin_user();

		$request  = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/settings' );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'enabled', $data['data'] );
		$this->assertArrayHasKey( 'enabled_categories', $data['data'] );
		$this->assertArrayHasKey( 'rate_limit', $data['data'] );
	}

	// ========== POST /mcp/settings ==========

	/**
	 * POST mcp/settings → 更新 enabled_categories 生效
	 *
	 * @group happy
	 */
	public function test_post_settings_updates_enabled_categories(): void {
		$this->create_admin_user();

		$request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				[
					'enabled'            => true,
					'enabled_categories' => [ 'course', 'chapter' ],
				]
			)
		);
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['data']['enabled'] );
		$this->assertSame( [ 'course', 'chapter' ], $data['data']['enabled_categories'] );

		// 再從 DB 讀一次確認持久化
		$settings = new Settings();
		$this->assertTrue( $settings->is_server_enabled() );
		$this->assertSame( [ 'course', 'chapter' ], $settings->get_enabled_categories() );
	}

	/**
	 * GET mcp/settings 回傳 payload 必含 allow_update / allow_delete（Issue #217）
	 *
	 * @group smoke
	 */
	public function test_get_settings_payload_includes_allow_update_delete(): void {
		$this->create_admin_user();

		$request  = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/settings' );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'allow_update', $data['data'] );
		$this->assertArrayHasKey( 'allow_delete', $data['data'] );
		// 預設應為 false（唯讀）
		$this->assertFalse( $data['data']['allow_update'] );
		$this->assertFalse( $data['data']['allow_delete'] );
	}

	/**
	 * POST mcp/settings → 更新 allow_update 生效並持久化（Issue #217）
	 *
	 * @group happy
	 */
	public function test_post_settings_persists_allow_update(): void {
		$this->create_admin_user();

		$request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'allow_update' => true ] ) );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['data']['allow_update'] );
		$this->assertFalse( $data['data']['allow_delete'] );

		// 重新讀 DB 確認持久化
		$settings = new Settings();
		$this->assertTrue( $settings->is_update_allowed() );
		$this->assertFalse( $settings->is_delete_allowed() );
	}

	/**
	 * POST mcp/settings → 更新 allow_delete 生效並持久化（Issue #217）
	 *
	 * @group happy
	 */
	public function test_post_settings_persists_allow_delete(): void {
		$this->create_admin_user();

		$request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'allow_delete' => true ] ) );
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['data']['allow_delete'] );
		$this->assertFalse( $data['data']['allow_update'] );

		// 重新讀 DB 確認持久化
		$settings = new Settings();
		$this->assertTrue( $settings->is_delete_allowed() );
		$this->assertFalse( $settings->is_update_allowed() );
	}

	// ========== GET /mcp/tokens ==========

	/**
	 * POST mcp/tokens 建立 → 回明文；GET mcp/tokens 看不到明文
	 *
	 * @group security
	 */
	public function test_create_token_returns_plaintext_but_list_does_not(): void {
		$admin_id = $this->create_admin_user();

		// 建立
		$create_request = new \WP_REST_Request( 'POST', '/' . self::NS . '/mcp/tokens' );
		$create_request->set_header( 'Content-Type', 'application/json' );
		$create_request->set_body(
			(string) wp_json_encode(
				[
					'name'         => 'Test Token',
					'capabilities' => [ 'course' ],
				]
			)
		);
		$create_response = \rest_do_request( $create_request );
		$create_data     = $create_response->get_data();

		$this->assertSame( 200, $create_response->get_status() );
		$this->assertArrayHasKey( 'token', $create_data['data'], '建立回應應包含明文 token' );
		$this->assertNotEmpty( $create_data['data']['token'] );
		$this->assertArrayHasKey( 'id', $create_data['data'] );
		$plain_token = $create_data['data']['token'];

		// 列表
		$list_request  = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/tokens' );
		$list_response = \rest_do_request( $list_request );
		$list_data     = $list_response->get_data();

		$this->assertSame( 200, $list_response->get_status() );
		$this->assertIsArray( $list_data['data'] );
		$this->assertGreaterThanOrEqual( 1, count( $list_data['data'] ) );

		foreach ( $list_data['data'] as $row ) {
			$this->assertArrayNotHasKey( 'token', $row, '列表不應包含明文 token' );
			$this->assertArrayNotHasKey( 'token_hash', $row, '列表不應包含 token hash' );
			$this->assertNotSame( $plain_token, $row['name'] ?? '' );
		}
	}

	// ========== DELETE /mcp/tokens/(?P<id>\d+) ==========

	/**
	 * DELETE mcp/tokens/(?P<id>) → 撤銷後 verify_token 回 false
	 *
	 * @group happy
	 */
	public function test_delete_token_revokes_it(): void {
		$admin_id = $this->create_admin_user();

		$auth  = new Auth();
		$plain = $auth->create_token( $admin_id, 'To Be Revoked', [] );

		global $wpdb;
		$table    = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$token_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", hash( 'sha256', $plain ) )
		);
		$this->assertGreaterThan( 0, $token_id );

		$request  = new \WP_REST_Request( 'DELETE', '/' . self::NS . '/mcp/tokens/' . $token_id );
		$response = \rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );

		$this->assertFalse( $auth->verify_bearer_token( $plain ), '撤銷後應無法通過驗證' );
	}

	// ========== GET /mcp/activity ==========

	/**
	 * GET mcp/activity 分頁參數生效
	 *
	 * @group happy
	 */
	public function test_get_activity_pagination_works(): void {
		$admin_id = $this->create_admin_user();

		// 塞 5 筆 activity
		$logger = new \J7\PowerCourse\Api\Mcp\ActivityLogger();
		for ( $i = 0; $i < 5; $i++ ) {
			$logger->log( "tool_{$i}", $admin_id, [ 'seq' => $i ], [ 'ok' => true ], true, null, 10 );
		}

		// per_page = 2, page = 1
		$request = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/activity' );
		$request->set_query_params(
			[
				'per_page' => 2,
				'page'     => 1,
			]
		);
		$response = \rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $data['data'], '第一頁應有 2 筆' );
		$this->assertSame( 5, $data['meta']['total'], '總筆數應為 5' );
		$this->assertSame( 1, $data['meta']['page'] );
		$this->assertSame( 2, $data['meta']['per_page'] );

		// 過濾 tool_name
		$filter_request = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/activity' );
		$filter_request->set_query_params( [ 'tool_name' => 'tool_2' ] );
		$filter_response = \rest_do_request( $filter_request );
		$filter_data     = $filter_response->get_data();

		$this->assertSame( 200, $filter_response->get_status() );
		$this->assertCount( 1, $filter_data['data'] );
		$this->assertSame( 'tool_2', $filter_data['data'][0]['tool_name'] );
	}
}
