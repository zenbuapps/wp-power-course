<?php
/**
 * MCP REST Controller — AI Tab 共用的 settings endpoints
 *
 * 提供:
 * - GET/POST  power-course/mcp/settings           讀寫 MCP 啟用狀態與 allow_update / allow_delete（給 AI tab 用）
 * - GET       power-course/mcp/tokens             列出目前登入管理員自己的 Token（Issue #230）
 * - POST      power-course/mcp/tokens             建立 Token，回傳一次性明文 + 設定範本所需資訊（Issue #230）
 * - DELETE    power-course/mcp/tokens/(?P<id>\d+) 撤銷自己的 Token（含越權防護，Issue #230）
 *
 * Issue #230：在 AI tab 內直接管理 MCP Bearer Token，取代「跳到使用者個人資料頁產生應用程式密碼」流程。
 * Token 列表只列當前登入管理員自己的（Q6），不開放逐 Token 權限（Q2，capabilities 一律全權限），
 * 撤銷後即從列表消失（Q3）。期限支援 30/90/365 天與「永不過期」（Q1，預設永不過期）。
 *
 * 注意：對外 MCP server endpoint（mcp-adapter 註冊在 'power-course/v2/mcp'）屬於另一條對外契約路由，
 * 與此 namespace 互不衝突。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

use J7\WpUtils\Classes\ApiBase;

/**
 * Class RestController
 * 僅服務 AI tab：讀寫 MCP Settings（含 allow_update / allow_delete 兩個 AI 權限旗標）
 * 所有 callback 強制 current_user_can( 'manage_options' ) 驗證（透過 permission_callback）
 */
final class RestController extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * REST namespace
	 * 與本專案其他 ApiBase 子類別一致採用 'power-course'（不含 /v2）；
	 * MCP Server 對外 endpoint（mcp-adapter 註冊在 'power-course/v2/mcp'）屬於另一條對外契約路由，
	 * 與此後台管理 REST namespace 互不衝突
	 *
	 * @var string
	 */
	protected $namespace = 'power-course';

	/**
	 * 註冊的 APIs 清單
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null}>
	 */
	protected $apis = [
		[
			'endpoint' => 'mcp/settings',
			'method' => 'get',
		],
		[
			'endpoint' => 'mcp/settings',
			'method' => 'post',
		],
		[
			'endpoint' => 'mcp/tokens',
			'method' => 'get',
		],
		[
			'endpoint' => 'mcp/tokens',
			'method' => 'post',
		],
		[
			'endpoint' => 'mcp/tokens/(?P<id>\d+)',
			'method' => 'delete',
		],
	];

	/**
	 * 建立 Token 時可選的有效期限（天）白名單；其餘值（含 never / 空 / 非法）一律視為永不過期（Issue #230 Q1）
	 *
	 * @var array<int>
	 */
	private const ALLOWED_EXPIRES_DAYS = [ 30, 90, 365 ];

	/**
	 * 覆寫 ApiBase 預設 permission，所有 MCP REST endpoints 限制 manage_options
	 *
	 * @return bool
	 */
	public function permission_callback(): bool {
		return \current_user_can( 'manage_options' );
	}

	// ========== Settings ==========

	/**
	 * GET /mcp/settings — 取得 MCP 設定
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_mcp_settings_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		return \rest_ensure_response(
			[
				'data'    => $this->get_settings_payload(),
				'message' => \__( '成功取得 MCP 設定', 'power-course' ),
			]
		);
	}

	/**
	 * POST /mcp/settings — 更新 MCP 設定
	 *
	 * 可接受欄位:
	 * - enabled            bool
	 * - enabled_categories string[]
	 * - allow_update       bool   (Issue #217)
	 * - allow_delete       bool   (Issue #217)
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_mcp_settings_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$settings = new Settings();
		$params   = $this->extract_params( $request );

		// enabled (bool)
		if ( array_key_exists( 'enabled', $params ) ) {
			$settings->set_server_enabled( (bool) $params['enabled'] );
		}

		// enabled_categories (string[])
		if ( array_key_exists( 'enabled_categories', $params ) && is_array( $params['enabled_categories'] ) ) {
			$raw_categories = $params['enabled_categories'];
			/** @var array<string> $categories 只保留字串成員 */
			$categories = array_values(
				array_filter(
					array_map(
						static fn( $c ): string => \sanitize_text_field( (string) $c ),
						$raw_categories
					),
					static fn( string $c ): bool => '' !== $c
				)
			);
			$settings->set_enabled_categories( $categories );
		}

		// allow_update (bool) — Issue #217 AI 修改權限
		if ( array_key_exists( 'allow_update', $params ) ) {
			$settings->set_update_allowed( (bool) $params['allow_update'] );
		}

		// allow_delete (bool) — Issue #217 AI 刪除權限
		if ( array_key_exists( 'allow_delete', $params ) ) {
			$settings->set_delete_allowed( (bool) $params['allow_delete'] );
		}

		return \rest_ensure_response(
			[
				'data'    => $this->get_settings_payload(),
				'message' => \__( 'MCP 設定已更新', 'power-course' ),
			]
		);
	}

	// ========== Tokens (Issue #230) ==========

	/**
	 * GET /mcp/tokens — 取得目前登入管理員自己的 Token 列表
	 *
	 * 只列當前使用者（Q6），由 list_tokens 自動排除已撤銷者（Q3）；
	 * 回傳不含 token_hash 與明文，避免洩漏。
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_mcp_tokens_callback( \WP_REST_Request $request ) {
		\nocache_headers();

		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$auth   = new Auth();
		$tokens = $auth->list_tokens( \get_current_user_id() );

		/** @var array<int, array{id: int, name: string, created_at: string, last_used_at: string|null, expires_at: string|null}> $payload */
		$payload = array_map(
			static fn( array $row ): array => [
				'id'           => (int) $row['id'],
				'name'         => (string) $row['name'],
				'created_at'   => (string) $row['created_at'],
				'last_used_at' => $row['last_used_at'] ?? null,
				'expires_at'   => $row['expires_at'] ?? null,
			],
			$tokens
		);

		return \rest_ensure_response(
			[
				'data'    => $payload,
				'message' => \__( 'MCP tokens retrieved', 'power-course' ),
			]
		);
	}

	/**
	 * POST /mcp/tokens — 建立 Token 並回傳一次性明文
	 *
	 * 可接受欄位:
	 * - name          string （必填）
	 * - expires_days  int    （選填；30 / 90 / 365；其餘值或不帶＝永不過期，Issue #230 Q1）
	 *
	 * capabilities 一律全權限（NULL），不開放逐 Token 權限（Q2），統一由 AI tab 全域開關控制。
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_mcp_tokens_callback( \WP_REST_Request $request ) {
		\nocache_headers();

		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$params = $this->extract_params( $request );

		$name = \sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error(
				'invalid_name',
				\__( 'Token name is required', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		// 解析有效期限（Issue #230 Q1）：白名單天數 → UTC 到期時間；其餘（含 never / 空 / 非法）→ null（永不過期）
		$expires_at   = null;
		$expires_days = isset( $params['expires_days'] ) ? (int) $params['expires_days'] : 0;
		if ( in_array( $expires_days, self::ALLOWED_EXPIRES_DAYS, true ) ) {
			$expires_at = \gmdate( 'Y-m-d H:i:s', time() + ( $expires_days * DAY_IN_SECONDS ) );
		}

		$user_id = \get_current_user_id();
		$auth    = new Auth();
		$plain   = $auth->create_token( $user_id, $name, [], $expires_at );

		// 取得剛建立 token 的 id
		global $wpdb;
		$table    = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$token_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", hash( 'sha256', $plain ) )
		);

		return \rest_ensure_response(
			[
				'data'    => [
					'id'      => $token_id,
					'name'    => $name,
					'token'   => $plain, // 僅此一次回傳明文
					'warning' => \__( 'This token is shown only once. Copy it now and store it securely.', 'power-course' ),
				],
				'message' => \__( 'Token created. Please copy the plaintext now.', 'power-course' ),
			]
		);
	}

	/**
	 * DELETE /mcp/tokens/(?P<id>\d+) — 撤銷自己的 Token（含越權防護）
	 *
	 * 越權防護（安全不變式）：僅能撤銷自己建立的 Token，
	 * 撤銷他人 Token 回 403 且不執行撤銷（Issue #230）。
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_mcp_tokens_with_id_callback( \WP_REST_Request $request ) {
		\nocache_headers();

		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$token_id = \absint( $request['id'] ?? 0 );
		if ( $token_id <= 0 ) {
			return new \WP_Error(
				'invalid_id',
				\__( 'Invalid token ID', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$auth = new Auth();

		// 越權防護：只能撤銷自己建立的 Token（Q6 / 安全不變式）
		if ( $auth->find_token_owner( $token_id ) !== \get_current_user_id() ) {
			return new \WP_Error(
				'forbidden',
				\__( 'You can only revoke your own tokens', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		$result = $auth->revoke_token( (string) $token_id );
		if ( ! $result ) {
			return new \WP_Error(
				'revoke_failed',
				\__( 'Failed to revoke token', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		return \rest_ensure_response(
			[
				'data'    => [ 'id' => $token_id ],
				'message' => \__( 'Token revoked', 'power-course' ),
			]
		);
	}

	// ========== Helpers ==========

	/**
	 * 從 REST 請求取得參數陣列（優先 JSON body，fallback 到 query / form params）
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return array<string, mixed>
	 */
	private function extract_params( \WP_REST_Request $request ): array {
		$json = $request->get_json_params();
		if ( is_array( $json ) && [] !== $json ) {
			return $json;
		}
		$params = $request->get_params();
		return is_array( $params ) ? $params : [];
	}

	/**
	 * 檢查權限，失敗回傳 WP_Error，通過回傳 null
	 *
	 * @return \WP_Error|null
	 */
	private function check_permission(): ?\WP_Error {
		if ( \current_user_can( 'manage_options' ) ) {
			return null;
		}
		return new \WP_Error(
			'forbidden',
			\__( '您沒有權限存取 MCP 管理功能', 'power-course' ),
			[ 'status' => 403 ]
		);
	}

	/**
	 * 產生目前 MCP Settings 的回傳 payload
	 *
	 * @return array{enabled: bool, enabled_categories: array<string>, rate_limit: int, allow_update: bool, allow_delete: bool}
	 */
	private function get_settings_payload(): array {
		$settings = new Settings();
		return [
			'enabled'            => $settings->is_server_enabled(),
			'enabled_categories' => $settings->get_enabled_categories(),
			'rate_limit'         => $settings->get_rate_limit(),
			'allow_update'       => $settings->is_update_allowed(),
			'allow_delete'       => $settings->is_delete_allowed(),
		];
	}
}
