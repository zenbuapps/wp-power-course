<?php
/**
 * MCP Auth — Bearer Token 驗證與管理
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class Auth
 * 負責 MCP Bearer Token 的建立、驗證、撤銷
 * Token 以 SHA-256 hash 儲存，不明文保存
 */
final class Auth {

	/**
	 * 建立新的 MCP Token
	 *
	 * @param int      $user_id      用戶 ID
	 * @param string   $name         Token 名稱（使用者標記）
	 * @param string[] $capabilities 允許的 tool categories（空陣列代表全部允許）
	 * @return string token 明文（呼叫方負責安全傳遞，之後不再可取回）
	 */
	public function create_token( int $user_id, string $name, array $capabilities ): string {
		global $wpdb;

		// 產生足夠隨機的 token
		$plain      = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $plain );
		$table      = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[
				'token_hash'   => $token_hash,
				'user_id'      => $user_id,
				'name'         => sanitize_text_field( $name ),
				'capabilities' => empty( $capabilities ) ? null : wp_json_encode( array_values( $capabilities ) ),
				'created_at'   => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%s', '%s', '%s' ]
		);

		return $plain;
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
	 * @return array<int, array{id: int, name: string, capabilities: array<string>, last_used_at: string|null, created_at: string}> Token 清單
	 */
	public function list_tokens( int $user_id = 0 ): array {
		global $wpdb;
		$table = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;

		if ( $user_id > 0 ) {
			/** @var array<\stdClass>|null $rows */
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT id, name, capabilities, last_used_at, created_at FROM {$table} WHERE user_id = %d AND revoked_at IS NULL ORDER BY created_at DESC",
					$user_id
				)
			);
		} else {
			/** @var array<\stdClass>|null $rows */
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results(
				"SELECT id, user_id, name, capabilities, last_used_at, created_at FROM {$table} WHERE revoked_at IS NULL ORDER BY created_at DESC"
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
			];
		}
		return $result;
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
}
