<?php
/**
 * MCP Auth — Bearer Token 驗證與管理
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class Auth
 * 負責 MCP Bearer Token 的建立、驗證、撤銷
 *
 * Token 同時以兩種形式儲存：
 * - token_hash（SHA-256）：驗證時比對用，單向不可逆。
 * - token_encrypted（AES-256-CBC，key 由 wp_salt('auth') 派生）：供管理員後續於後台重看明文（Issue #230）。
 *   採可逆加密而非裸明文，DB 單獨外洩（無 wp-config 的 salt）時無法還原 token，降低 blast radius。
 */
final class Auth {

	/** 加密演算法 */
	private const CIPHER = 'aes-256-cbc';

	/**
	 * 建立新的 MCP Token
	 *
	 * @param int         $user_id      用戶 ID
	 * @param string      $name         Token 名稱（使用者標記）
	 * @param string[]    $capabilities 允許的 tool categories（空陣列代表全部允許）
	 * @param string|null $expires_at   到期時間（UTC `Y-m-d H:i:s`）；null 代表永不過期（Issue #230）
	 * @return string token 明文
	 * @throws \RuntimeException 寫入資料表失敗時拋出（例如資料表不存在），避免回傳一個未被持久化的孤兒 token。
	 */
	public function create_token( int $user_id, string $name, array $capabilities, ?string $expires_at = null ): string {
		global $wpdb;

		// 產生足夠隨機的 token
		$plain      = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $plain );
		$encrypted  = $this->encrypt_token( $plain );
		$table      = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[
				'token_hash'      => $token_hash,
				'token_encrypted' => '' === $encrypted ? null : $encrypted,
				'user_id'         => $user_id,
				'name'            => sanitize_text_field( $name ),
				'capabilities'    => empty( $capabilities ) ? null : wp_json_encode( array_values( $capabilities ) ),
				'created_at'      => current_time( 'mysql', true ),
				'expires_at'      => $expires_at,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to persist MCP token' );
		}

		return $plain;
	}

	/**
	 * 取得指定 Token 的明文（供後台重看，Issue #230）
	 *
	 * 僅回傳「屬於該使用者、且未撤銷」的 token 明文，達成 owner-scoped 還原；
	 * 找不到、已撤銷、或無加密資料（升級前建立的舊 token）一律回 null。
	 *
	 * @param int $token_id Token 資料庫 ID
	 * @param int $user_id  要求查看者的 user_id（用於 owner-scoped 過濾）
	 * @return array{name: string, token: string}|null
	 */
	public function reveal_token( int $token_id, int $user_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		/** @var \stdClass|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT name, token_encrypted FROM {$table} WHERE id = %d AND user_id = %d AND revoked_at IS NULL",
				$token_id,
				$user_id
			)
		);

		if ( null === $row || empty( $row->token_encrypted ) ) {
			return null;
		}

		$plain = $this->decrypt_token( (string) $row->token_encrypted );
		if ( null === $plain ) {
			return null;
		}

		return [
			'name'  => (string) $row->name,
			'token' => $plain,
		];
	}

	/**
	 * 驗證 Bearer Token 並回傳對應的 WP_User
	 *
	 * @param string $token token 明文
	 * @return \WP_User|false 驗證成功回傳 WP_User，失敗回傳 false
	 */
	public function verify_bearer_token( string $token ): \WP_User|false {
		if ( '' === $token ) {
			return false;
		}

		global $wpdb;
		$hash  = hash( 'sha256', $token );
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		/** @var \stdClass|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT id, user_id, revoked_at, expires_at FROM {$table} WHERE token_hash = %s",
				$hash
			)
		);

		if ( null === $row ) {
			return false;
		}

		// 已撤銷
		if ( ! empty( $row->revoked_at ) ) {
			return false;
		}

		// 已過期
		if ( ! empty( $row->expires_at ) ) {
			$expires = strtotime( (string) $row->expires_at );
			if ( false !== $expires && $expires < time() ) {
				return false;
			}
		}

		$user = get_user_by( 'id', (int) $row->user_id );
		if ( false === $user ) {
			return false;
		}

		// 更新最後使用時間
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[ 'last_used_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $row->id ],
			[ '%s' ],
			[ '%d' ]
		);

		return $user;
	}

	/**
	 * 撤銷 Token
	 *
	 * @param string $token_id Token 的資料庫 ID（字串型，避免型別問題）
	 * @return bool 是否成功撤銷
	 */
	public function revoke_token( string $token_id ): bool {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[ 'revoked_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $token_id ],
			[ '%s' ],
			[ '%d' ]
		);

		return false !== $result && $result > 0;
	}

	/**
	 * 取得用戶的 Token 清單（不含已撤銷的）
	 *
	 * @param int $user_id 用戶 ID（0 = 取得所有用戶）
	 * @return array<int, array{id: int, name: string, capabilities: array<string>, last_used_at: string|null, created_at: string, expires_at: string|null}> Token 清單
	 */
	public function list_tokens( int $user_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		if ( $user_id > 0 ) {
			/** @var array<\stdClass>|null $rows */
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id, name, capabilities, last_used_at, created_at, expires_at FROM {$table} WHERE user_id = %d AND revoked_at IS NULL ORDER BY created_at DESC",
					$user_id
				)
			);
		} else {
			/** @var array<\stdClass>|null $rows */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT id, user_id, name, capabilities, last_used_at, created_at, expires_at FROM {$table} WHERE revoked_at IS NULL ORDER BY created_at DESC"
			);
		}

		if ( ! is_array( $rows ) ) {
			return [];
		}

		$result = [];
		foreach ( $rows as $idx => $row ) {
			/** @var array<string>|null $decoded_caps */
			$decoded_caps = ! empty( $row->capabilities ) ? json_decode( (string) $row->capabilities, true ) : null;
			$caps         = is_array( $decoded_caps ) ? $decoded_caps : [];
			$result[ $idx ] = [
				'id'           => (int) $row->id,
				'name'         => (string) $row->name,
				'capabilities' => $caps,
				'last_used_at' => isset( $row->last_used_at ) ? (string) $row->last_used_at : null,
				'created_at'   => (string) $row->created_at,
				'expires_at'   => isset( $row->expires_at ) ? (string) $row->expires_at : null,
			];
		}
		return $result;
	}

	/**
	 * 取得指定 Token 的擁有者 user_id（供撤銷時越權防護，Issue #230）
	 *
	 * 不過濾 revoked_at —— 已撤銷的 token 仍可查到擁有者，
	 * 讓撤銷流程能正確區分「不存在」與「他人的 token」。
	 *
	 * @param int $token_id Token 資料庫 ID
	 * @return int|null 擁有者 user_id；token 不存在時回 null
	 */
	public function find_token_owner( int $token_id ): ?int {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		$owner = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT user_id FROM {$table} WHERE id = %d", $token_id )
		);

		return null === $owner ? null : (int) $owner;
	}

	/**
	 * 判斷 token 是否允許指定的 tool category
	 * 若 capabilities 為空（無限制），則允許所有 category
	 *
	 * @param string $token_plain token 明文
	 * @param string $category    tool category
	 * @return bool
	 */
	public function token_allows_category( string $token_plain, string $category ): bool {
		global $wpdb;
		$hash  = hash( 'sha256', $token_plain );
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		/** @var object|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT capabilities FROM {$table} WHERE token_hash = %s AND revoked_at IS NULL", $hash )
		);

		if ( null === $row ) {
			return false;
		}

		// 無限制 token（空 capabilities）允許所有 category
		if ( empty( $row->capabilities ) ) {
			return true;
		}

		$caps = json_decode( $row->capabilities, true );
		return is_array( $caps ) && in_array( $category, $caps, true );
	}

	/**
	 * 派生對稱加密金鑰（32 bytes raw）
	 *
	 * 以 WordPress 的 auth salt 為來源 hash 出固定長度金鑰；salt 存於 wp-config，
	 * 不在資料庫內，故單純的 DB dump 無法解出 token。
	 *
	 * @return string 32 bytes 原始位元組
	 */
	private function encryption_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * 加密 token 明文（IV 前綴後 base64 編碼）
	 *
	 * @param string $plain token 明文
	 * @return string base64 字串；加密失敗回空字串（呼叫端據此存 NULL）
	 */
	private function encrypt_token( string $plain ): string {
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len || $iv_len < 1 ) {
			return '';
		}
		$iv     = random_bytes( $iv_len );
		$cipher = openssl_encrypt( $plain, self::CIPHER, $this->encryption_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return '';
		}
		return base64_encode( $iv . $cipher );
	}

	/**
	 * 解密 token（與 encrypt_token 對稱）
	 *
	 * @param string $stored base64( IV . ciphertext )
	 * @return string|null 明文；解密失敗或資料毀損回 null
	 */
	private function decrypt_token( string $stored ): ?string {
		$raw = base64_decode( $stored, true );
		if ( false === $raw || '' === $raw ) {
			return null;
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len || strlen( $raw ) <= $iv_len ) {
			return null;
		}
		$iv     = substr( $raw, 0, $iv_len );
		$cipher = substr( $raw, $iv_len );
		$plain  = openssl_decrypt( $cipher, self::CIPHER, $this->encryption_key(), OPENSSL_RAW_DATA, $iv );
		return false === $plain ? null : $plain;
	}
}
