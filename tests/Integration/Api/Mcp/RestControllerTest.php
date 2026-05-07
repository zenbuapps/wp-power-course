<?php
/**
 * MCP RestController 整合測試
 *
 * MCP 後台管理面板（Settings > MCP tab）已下架，原本的 tokens / activity endpoint 一併移除；
 * 此測試檔現在只覆蓋 settings 兩條路由（GET/POST），這是 AI tab 仍在使用的 endpoint。
 *
 * @group mcp
 * @group rest
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp;

use J7\PowerCourse\Api\Mcp\RestController;
use J7\PowerCourse\Api\Mcp\Settings;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class RestControllerTest
 * 驗證 GET/POST mcp/settings（AI tab 共用的 endpoint）
 */
class RestControllerTest extends IntegrationTestCase {

	/** REST namespace（AI tab 前端 hook 已對齊此 namespace，不可改回 'power-course/v2'） */
	private const NS = 'power-course';

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
	 * GET mcp/settings 未登入 → 401
	 *
	 * @group security
	 */
	public function test_get_settings_without_login_returns_401(): void {
		$this->set_guest_user();
		$request  = new \WP_REST_Request( 'GET', '/' . self::NS . '/mcp/settings' );
		$response = \rest_do_request( $request );

		$this->assertContains( $response->get_status(), [ 401, 403 ], '未登入應回 401 或 403' );
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
}
