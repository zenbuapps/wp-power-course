<?php
/**
 * MCP Auth 整合測試
 *
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Auth;
use J7\PowerCourse\Api\Mcp\Migration;

/**
 * Class AuthTest
 * 驗證 Bearer Token 驗證、建立、撤銷流程
 */
class AuthTest extends IntegrationTestCase {

	/** @var int 測試用 admin 用戶 ID */
	private int $admin_id;

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		$this->admin_id = $this->create_admin_user();
	}

	/**
	 * 測試：create_token() 回傳非空字串
	 *
	 * @group happy
	 */
	public function test_create_token_returns_non_empty_string(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Test Token', [] );
		$this->assertIsString( $token );
		$this->assertNotEmpty( $token );
	}

	/**
	 * 測試：token 以 hash 儲存於資料庫，不明文儲存
	 *
	 * @group security
	 */
	public function test_token_stored_as_hash_not_plaintext(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Hash Test', [] );

		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT token_hash FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $this->admin_id )
		);

		$this->assertNotNull( $row, '應能在 DB 找到 token 記錄' );
		$this->assertNotSame( $token, $row->token_hash, 'DB 不應儲存明文 token' );
		$this->assertSame( hash( 'sha256', $token ), $row->token_hash, 'DB 應儲存 SHA256 hash' );
	}

	/**
	 * 測試：verify_bearer_token() 以有效 token 回傳 WP_User
	 *
	 * @group happy
	 */
	public function test_verify_valid_token_returns_wp_user(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Valid Token', [] );
		$user  = $auth->verify_bearer_token( $token );

		$this->assertInstanceOf( \WP_User::class, $user, '有效 token 應回傳 WP_User' );
		$this->assertSame( $this->admin_id, (int) $user->ID );
	}

	/**
	 * 測試：verify_bearer_token() 以無效 token 回傳 false
	 *
	 * @group error
	 */
	public function test_verify_invalid_token_returns_false(): void {
		$auth   = new Auth();
		$result = $auth->verify_bearer_token( 'invalid-token-xyz' );
		$this->assertFalse( $result, '無效 token 應回傳 false' );
	}

	/**
	 * 測試：verify_bearer_token() 以空字串回傳 false
	 *
	 * @group error
	 */
	public function test_verify_empty_token_returns_false(): void {
		$auth   = new Auth();
		$result = $auth->verify_bearer_token( '' );
		$this->assertFalse( $result, '空 token 應回傳 false' );
	}

	/**
	 * 測試：revoke_token() 後 token 失效
	 *
	 * @group happy
	 */
	public function test_revoke_token_invalidates_it(): void {
		$auth     = new Auth();
		$token    = $auth->create_token( $this->admin_id, 'Revokable Token', [] );
		$token_id = $this->get_token_id_by_plain( $token );

		$result = $auth->revoke_token( (string) $token_id );
		$this->assertTrue( $result, 'revoke_token() 應回傳 true' );

		$user = $auth->verify_bearer_token( $token );
		$this->assertFalse( $user, '已撤銷的 token 不應通過驗證' );
	}

	/**
	 * 測試：list_tokens() 回傳指定用戶的 tokens
	 *
	 * @group happy
	 */
	public function test_list_tokens_returns_user_tokens(): void {
		$auth = new Auth();
		$auth->create_token( $this->admin_id, 'Token A', [] );
		$auth->create_token( $this->admin_id, 'Token B', [ 'course' ] );

		$tokens = $auth->list_tokens( $this->admin_id );
		$this->assertCount( 2, $tokens, '應回傳 2 個 tokens' );
	}

	/**
	 * 測試：list_tokens() 不包含其他用戶的 tokens
	 *
	 * @group happy
	 */
	public function test_list_tokens_filters_by_user(): void {
		$auth      = new Auth();
		$other_id  = $this->factory()->user->create( [ 'role' => 'administrator' ] );

		$auth->create_token( $this->admin_id, 'My Token', [] );
		$auth->create_token( $other_id, 'Other Token', [] );

		$tokens = $auth->list_tokens( $this->admin_id );
		$this->assertCount( 1, $tokens, '應只回傳指定用戶的 tokens' );
	}

	/**
	 * 測試：create_token() 儲存 capabilities
	 *
	 * @group happy
	 */
	public function test_create_token_stores_capabilities(): void {
		$auth  = new Auth();
		$caps  = [ 'course', 'chapter' ];
		$token = $auth->create_token( $this->admin_id, 'Cap Token', $caps );

		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT capabilities FROM {$table} WHERE token_hash = %s",
				hash( 'sha256', $token )
			)
		);

		$stored_caps = $row ? json_decode( $row->capabilities, true ) : [];
		$this->assertSame( $caps, $stored_caps, '儲存的 capabilities 應與建立時一致' );
	}

	/**
	 * 測試：token capability 不涵蓋指定 category 時，deny
	 *
	 * @group security
	 */
	public function test_token_capability_check_denies_unlisted_category(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Limited Token', [ 'course' ] );

		// 驗證 token 有效
		$user = $auth->verify_bearer_token( $token );
		$this->assertInstanceOf( \WP_User::class, $user );

		// 確認 token 不允許 'student' category
		$this->assert_token_denies_category( $token, 'student' );
	}

	/**
	 * 測試：create_token() 帶 expires_at 時，DB 寫入該到期時間（Issue #230）
	 *
	 * @group happy
	 */
	public function test_create_token_with_expires_at_persists_it(): void {
		$auth       = new Auth();
		$expires_at = '2099-12-31 00:00:00';
		$token      = $auth->create_token( $this->admin_id, 'Expiring Token', [], $expires_at );

		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT expires_at FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) )
		);

		$this->assertNotNull( $row, '應能在 DB 找到 token 記錄' );
		$this->assertSame( $expires_at, $row->expires_at, 'expires_at 應寫入指定值' );
	}

	/**
	 * 測試：create_token() 不帶 expires_at 時，DB expires_at 為 NULL（永不過期）（Issue #230）
	 *
	 * @group happy
	 */
	public function test_create_token_without_expires_at_is_null(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Never Expire Token', [] );

		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT expires_at FROM {$table} WHERE token_hash = %s", hash( 'sha256', $token ) )
		);

		$this->assertNotNull( $row, '應能在 DB 找到 token 記錄' );
		$this->assertNull( $row->expires_at, '未指定 expires_at 應為 NULL（永不過期）' );
	}

	/**
	 * 測試：list_tokens() 回傳項目包含 expires_at 鍵（Issue #230）
	 *
	 * @group happy
	 */
	public function test_list_tokens_includes_expires_at_key(): void {
		$auth = new Auth();
		$auth->create_token( $this->admin_id, 'Token With Expiry', [], '2099-01-01 00:00:00' );

		$tokens = $auth->list_tokens( $this->admin_id );
		$this->assertNotEmpty( $tokens, 'list_tokens 應回傳至少一筆' );
		$this->assertArrayHasKey( 'expires_at', $tokens[0], 'list_tokens 項目應含 expires_at 鍵' );
		$this->assertSame( '2099-01-01 00:00:00', $tokens[0]['expires_at'], 'expires_at 值應正確回傳' );
	}

	/**
	 * 測試：過期的 Token verify_bearer_token() 回傳 false（Issue #230）
	 *
	 * @group security
	 */
	public function test_verify_expired_token_returns_false(): void {
		$auth  = new Auth();
		$past  = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$token = $auth->create_token( $this->admin_id, 'Expired Token', [], $past );

		$result = $auth->verify_bearer_token( $token );
		$this->assertFalse( $result, '過期 token 不應通過驗證' );
	}

	/**
	 * 測試：永不過期（expires_at NULL）的 Token verify 成功（Issue #230）
	 *
	 * @group happy
	 */
	public function test_verify_never_expire_token_succeeds(): void {
		$auth  = new Auth();
		$token = $auth->create_token( $this->admin_id, 'Forever Token', [] );

		$user = $auth->verify_bearer_token( $token );
		$this->assertInstanceOf( \WP_User::class, $user, '永不過期 token 應通過驗證' );
	}

	/**
	 * 測試：find_token_owner() 回傳建立者的 user_id（Issue #230 越權防護）
	 *
	 * @group happy
	 */
	public function test_find_token_owner_returns_creator_id(): void {
		$auth     = new Auth();
		$token    = $auth->create_token( $this->admin_id, 'Owned Token', [] );
		$token_id = $this->get_token_id_by_plain( $token );

		$this->assertSame( $this->admin_id, $auth->find_token_owner( $token_id ), 'find_token_owner 應回傳建立者 ID' );
	}

	/**
	 * 測試：find_token_owner() 對不存在的 token 回傳 null（Issue #230）
	 *
	 * @group error
	 */
	public function test_find_token_owner_returns_null_for_missing(): void {
		$auth = new Auth();
		$this->assertNull( $auth->find_token_owner( 999999 ), '不存在的 token 應回傳 null' );
	}

	/**
	 * 取得 token ID
	 *
	 * @param string $plain token 明文
	 * @return int token ID
	 */
	private function get_token_id_by_plain( string $plain ): int {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$hash  = hash( 'sha256', $plain );
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", $hash )
		);
		return $row ? (int) $row->id : 0;
	}
}
