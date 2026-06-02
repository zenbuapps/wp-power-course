<?php
/**
 * MCP RestController Token 端點整合測試（Issue #230）
 *
 * 直接實例化 RestController 並呼叫 callback，
 * 透過 wp_set_current_user() 模擬不同登入者，
 * 驗證 GET / POST / DELETE 三個 token 端點的：
 *   - 列表只列當前使用者、排除已撤銷、不洩漏 hash / 明文
 *   - 建立期限（expires_days）與全權限（capabilities NULL）
 *   - 撤銷 ownership 越權防護
 *   - 權限守門（manage_options）
 *
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Auth;
use J7\PowerCourse\Api\Mcp\Migration;
use J7\PowerCourse\Api\Mcp\RestController;

/**
 * Class RestControllerTokensTest
 */
class RestControllerTokensTest extends IntegrationTestCase {

	/** @var int 主要 admin 用戶 ID */
	private int $admin_id;

	/** @var int 第二個 admin 用戶 ID（驗證跨使用者隔離與越權） */
	private int $admin2_id;

	/** @var RestController */
	private RestController $controller;

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id   = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->admin2_id  = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->controller = RestController::instance();
		\wp_set_current_user( $this->admin_id );
	}

	// ========== GET /mcp/tokens ==========

	/**
	 * 測試：GET 只列當前使用者的 token，排除他人與已撤銷
	 *
	 * @group happy
	 */
	public function test_get_lists_only_current_user_and_excludes_revoked(): void {
		$auth = new Auth();
		$auth->create_token( $this->admin_id, 'Admin A', [] );
		$revoked = $auth->create_token( $this->admin_id, 'Admin Revoked', [] );
		$auth->create_token( $this->admin2_id, 'Admin2 Token', [] );

		$auth->revoke_token( (string) $this->get_token_id( $revoked ) );

		$list = $this->call_get();

		$this->assertCount( 1, $list, '應只回傳當前使用者未撤銷的 token' );
		$this->assertSame( 'Admin A', $list[0]['name'] );
	}

	/**
	 * 測試：GET 回傳項目不含 token_hash / 明文，含必要欄位
	 *
	 * @group security
	 */
	public function test_get_payload_excludes_hash_and_plaintext(): void {
		$auth = new Auth();
		$auth->create_token( $this->admin_id, 'Visible Token', [], '2099-01-01 00:00:00' );

		$list = $this->call_get();
		$this->assertCount( 1, $list );
		$item = $list[0];

		foreach ( [ 'id', 'name', 'created_at', 'last_used_at', 'expires_at' ] as $key ) {
			$this->assertArrayHasKey( $key, $item, "列表項目應含 {$key}" );
		}
		$this->assertArrayNotHasKey( 'token_hash', $item, '列表不應洩漏 token_hash' );
		$this->assertArrayNotHasKey( 'token', $item, '列表不應洩漏明文 token' );
		$this->assertSame( '2099-01-01 00:00:00', $item['expires_at'] );
	}

	/**
	 * 測試：GET 依目前登入者過濾（Admin2 只看到自己的）
	 *
	 * @group happy
	 */
	public function test_get_scoped_to_logged_in_admin2(): void {
		$auth = new Auth();
		$auth->create_token( $this->admin_id, 'Admin A', [] );
		$auth->create_token( $this->admin2_id, 'Admin2 Only', [] );

		\wp_set_current_user( $this->admin2_id );
		$list = $this->call_get();

		$this->assertCount( 1, $list );
		$this->assertSame( 'Admin2 Only', $list[0]['name'] );
	}

	// ========== POST /mcp/tokens ==========

	/**
	 * 測試：POST 空名稱回傳 400 invalid_name，不建立 token
	 *
	 * @group error
	 */
	public function test_post_empty_name_returns_400(): void {
		$result = $this->call_post( [ 'name' => '' ] );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_name', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );

		$this->assertSame( 0, $this->count_tokens(), '空名稱不應建立任何 token' );
	}

	/**
	 * 測試：POST 成功回傳明文 token 與 warning，DB 歸屬當前使用者、capabilities 為 NULL（全權限）
	 *
	 * @group happy
	 */
	public function test_post_creates_token_with_plaintext_and_full_capabilities(): void {
		$result = $this->call_post( [ 'name' => 'My AI' ] );

		$this->assertNotWPError( $result );
		$data = $result->get_data()['data'];

		$this->assertArrayHasKey( 'token', $data, '回應應含明文 token' );
		$this->assertNotEmpty( $data['token'] );
		$this->assertArrayHasKey( 'warning', $data, '回應應含僅顯示一次的 warning' );

		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT user_id, capabilities, name FROM {$table} WHERE id = %d", (int) $data['id'] )
		);
		$this->assertNotNull( $row );
		$this->assertSame( $this->admin_id, (int) $row->user_id, 'token 應歸屬當前登入管理員' );
		$this->assertSame( 'My AI', $row->name );
		$this->assertNull( $row->capabilities, 'capabilities 應為 NULL（全權限，沿用全域開關）' );
	}

	/**
	 * 測試：POST expires_days=90 時 expires_at 約為 now + 90 天
	 *
	 * @group happy
	 */
	public function test_post_with_expires_days_sets_expires_at(): void {
		$result = $this->call_post(
			[
				'name'         => 'Ninety Days',
				'expires_days' => 90,
			]
		);
		$this->assertNotWPError( $result );
		$id = (int) $result->get_data()['data']['id'];

		$row = $this->get_token_row( $id );
		$this->assertNotNull( $row->expires_at, 'expires_days=90 應寫入 expires_at' );

		$expected = time() + ( 90 * DAY_IN_SECONDS );
		$actual   = strtotime( (string) $row->expires_at );
		$this->assertEqualsWithDelta( $expected, $actual, 300, 'expires_at 應約為 now + 90 天（容許 5 分鐘誤差）' );
	}

	/**
	 * 測試：POST 不帶 expires_days（永不過期）→ expires_at 為 NULL
	 *
	 * @group happy
	 */
	public function test_post_never_expires_when_no_expires_days(): void {
		$result = $this->call_post( [ 'name' => 'Forever' ] );
		$this->assertNotWPError( $result );
		$id = (int) $result->get_data()['data']['id'];

		$row = $this->get_token_row( $id );
		$this->assertNull( $row->expires_at, '未帶 expires_days 應為永不過期（NULL）' );
	}

	/**
	 * 測試：POST 非法 expires_days（如 7）視為永不過期 NULL
	 *
	 * @group error
	 */
	public function test_post_invalid_expires_days_falls_back_to_null(): void {
		$result = $this->call_post(
			[
				'name'         => 'Bad Days',
				'expires_days' => 7,
			]
		);
		$this->assertNotWPError( $result );
		$id  = (int) $result->get_data()['data']['id'];
		$row = $this->get_token_row( $id );
		$this->assertNull( $row->expires_at, '非白名單 expires_days 應退回 NULL' );
	}

	// ========== DELETE /mcp/tokens/{id} ==========

	/**
	 * 測試：撤銷自己的 token 成功，revoked_at 寫入且列表不再出現
	 *
	 * @group happy
	 */
	public function test_delete_own_token_revokes_and_disappears(): void {
		$auth     = new Auth();
		$plain    = $auth->create_token( $this->admin_id, 'To Revoke', [] );
		$token_id = $this->get_token_id( $plain );

		$result = $this->call_delete( $token_id );
		$this->assertNotWPError( $result );

		$row = $this->get_token_row( $token_id );
		$this->assertNotNull( $row->revoked_at, 'revoked_at 應被寫入' );

		$this->assertCount( 0, $this->call_get(), '撤銷後列表不應再出現該 token' );
	}

	/**
	 * 測試：不可撤銷他人的 token（越權）→ 403 且目標 revoked_at 維持 NULL
	 *
	 * @group security
	 */
	public function test_delete_others_token_is_forbidden(): void {
		$auth     = new Auth();
		$plain    = $auth->create_token( $this->admin2_id, 'Admin2 Secret', [] );
		$token_id = $this->get_token_id( $plain );

		// 當前為 admin（非擁有者）嘗試撤銷 admin2 的 token
		$result = $this->call_delete( $token_id );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'], '越權撤銷應回 403' );

		$row = $this->get_token_row( $token_id );
		$this->assertNull( $row->revoked_at, '他人 token 的 revoked_at 應維持 NULL' );
	}

	/**
	 * 測試：DELETE id 為 0（無效）→ 400 invalid_id
	 *
	 * @group error
	 */
	public function test_delete_invalid_id_returns_400(): void {
		$result = $this->call_delete( 0 );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_id', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ========== 權限守門 ==========

	/**
	 * 測試：非 administrator（subscriber）呼叫 GET → 403 forbidden
	 *
	 * @group security
	 */
	public function test_non_admin_get_is_forbidden(): void {
		$sub = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\wp_set_current_user( $sub );

		$result = $this->controller->get_mcp_tokens_callback( $this->make_request( 'GET' ) );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * 測試：非 administrator 呼叫 POST → 403 且不建立 token
	 *
	 * @group security
	 */
	public function test_non_admin_post_is_forbidden(): void {
		$sub = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		\wp_set_current_user( $sub );

		$req = $this->make_request( 'POST' );
		$req->set_param( 'name', 'Should Not Create' );
		$result = $this->controller->post_mcp_tokens_callback( $req );

		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 0, $this->count_tokens(), '非管理員不應建立 token' );
	}

	// ========== Helpers ==========

	/**
	 * 建立指定 method 的 REST 請求物件
	 *
	 * @param string $method HTTP 方法
	 * @return \WP_REST_Request<array<string, mixed>>
	 */
	private function make_request( string $method ): \WP_REST_Request {
		return new \WP_REST_Request( $method, '/power-course/mcp/tokens' );
	}

	/**
	 * 呼叫 GET callback 並回傳 data 陣列
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function call_get(): array {
		$result = $this->controller->get_mcp_tokens_callback( $this->make_request( 'GET' ) );
		$this->assertNotWPError( $result );
		/** @var array<int, array<string, mixed>> $data */
		$data = $result->get_data()['data'];
		return $data;
	}

	/**
	 * 呼叫 POST callback
	 *
	 * @param array<string, mixed> $params 參數
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_post( array $params ) {
		$req = $this->make_request( 'POST' );
		foreach ( $params as $key => $value ) {
			$req->set_param( $key, $value );
		}
		return $this->controller->post_mcp_tokens_callback( $req );
	}

	/**
	 * 呼叫 DELETE callback
	 *
	 * @param int $id token id
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_delete( int $id ) {
		$req = $this->make_request( 'DELETE' );
		$req->set_param( 'id', $id );
		return $this->controller->delete_mcp_tokens_with_id_callback( $req );
	}

	/**
	 * 由明文取得 token id
	 *
	 * @param string $plain 明文
	 * @return int
	 */
	private function get_token_id( string $plain ): int {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$id    = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", hash( 'sha256', $plain ) )
		);
		return (int) $id;
	}

	/**
	 * 取得 token 資料列
	 *
	 * @param int $id token id
	 * @return \stdClass|null
	 */
	private function get_token_row( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);
	}

	/**
	 * 計算 token 總數
	 *
	 * @return int
	 */
	private function count_tokens(): int {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
