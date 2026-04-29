<?php
/**
 * MCP REST Controller — 前端 Settings Tab 所需的 REST endpoints
 *
 * 提供:
 * - GET/POST  power-course/v2/mcp/settings           讀寫 MCP 啟用狀態與 enabled_categories
 * - GET/POST  power-course/v2/mcp/tokens             列表 / 建立 Token
 * - DELETE    power-course/v2/mcp/tokens/(?P<id>\d+) 撤銷 Token
 * - GET       power-course/v2/mcp/activity           最近活動日誌（支援分頁 / tool_name 過濾）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

use J7\WpUtils\Classes\ApiBase;

/**
 * Class RestController
 * 單一檔案統一管理 MCP Settings / Tokens / Activity 的 REST endpoints
 * 所有 callback 強制 current_user_can( 'manage_options' ) 驗證（透過 permission_callback）
 */
final class RestController extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/**
	 * REST namespace
	 * 刻意採用 v2，與 MCP Server 的 REST 路徑保持一致
	 *
	 * @var string
	 */
	protected $namespace = 'power-course/v2';

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
		[
			'endpoint' => 'mcp/activity',
			'method' => 'get',
		],
	];

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

	// ========== Tokens ==========

	/**
	 * GET /mcp/tokens — 取得 Token 列表（不回明文）
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_mcp_tokens_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$auth   = new Auth();
		$tokens = $auth->list_tokens( 0 );

		/** @var array<int, array{id: int, name: string, capabilities: array<string>, last_used_at: ?string, created_at: string}> $payload */
		$payload = array_map(
			static fn( array $row ): array => [
				'id'           => (int) $row['id'],
				'name'         => (string) $row['name'],
				'capabilities' => (array) $row['capabilities'],
				'last_used_at' => $row['last_used_at'] ?? null,
				'created_at'   => (string) $row['created_at'],
			],
			$tokens
		);

		return \rest_ensure_response(
			[
				'data'    => $payload,
				'message' => \__( '成功取得 Token 列表', 'power-course' ),
			]
		);
	}

	/**
	 * POST /mcp/tokens — 建立新的 Token，回傳明文（僅此一次）
	 *
	 * 可接受欄位:
	 * - name          string  （必填）
	 * - capabilities  string[]（選填，空陣列代表全部允許）
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_mcp_tokens_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$params = $this->extract_params( $request );

		$name = \sanitize_text_field( (string) ( $params['name'] ?? '' ) );
		if ( '' === $name ) {
			return new \WP_Error(
				'invalid_name',
				\__( 'Token 名稱為必填', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		/** @var array<string> $capabilities */
		$capabilities = [];
		if ( isset( $params['capabilities'] ) && is_array( $params['capabilities'] ) ) {
			$capabilities = array_values(
				array_filter(
					array_map(
						static fn( $c ): string => \sanitize_text_field( (string) $c ),
						$params['capabilities']
					),
					static fn( string $c ): bool => '' !== $c
				)
			);
		}

		$user_id = \get_current_user_id();
		$auth    = new Auth();
		$plain   = $auth->create_token( $user_id, $name, $capabilities );

		// 取得剛建立 token 的 id
		global $wpdb;
		$table    = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		$hash     = hash( 'sha256', $plain );
		$token_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare( "SELECT id FROM {$table} WHERE token_hash = %s", $hash )
		);

		return \rest_ensure_response(
			[
				'data'    => [
					'id'           => $token_id,
					'name'         => $name,
					'token'        => $plain, // 只回一次明文
					'capabilities' => $capabilities,
					'warning'      => \__( '此 Token 明文僅顯示一次，請妥善保存', 'power-course' ),
				],
				'message' => \__( 'Token 已建立，請立即複製明文', 'power-course' ),
			]
		);
	}

	/**
	 * DELETE /mcp/tokens/(?P<id>\d+) — 撤銷指定 Token
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_mcp_tokens_with_id_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$token_id = \absint( $request['id'] ?? 0 );
		if ( $token_id <= 0 ) {
			return new \WP_Error(
				'invalid_id',
				\__( '無效的 Token ID', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$auth   = new Auth();
		$result = $auth->revoke_token( (string) $token_id );
		if ( ! $result ) {
			return new \WP_Error(
				'revoke_failed',
				\__( '撤銷 Token 失敗（可能不存在或已撤銷）', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		return \rest_ensure_response(
			[
				'data'    => [ 'id' => $token_id ],
				'message' => \__( 'Token 已撤銷', 'power-course' ),
			]
		);
	}

	// ========== Activity ==========

	/**
	 * GET /mcp/activity — 取得最近的活動日誌
	 *
	 * 支援 query 參數:
	 * - per_page  int     （預設 20，上限 100）
	 * - page      int     （預設 1）
	 * - tool_name string  （選填，精準比對）
	 *
	 * @param \WP_REST_Request<array<string, mixed>> $request REST 請求物件
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_mcp_activity_callback( \WP_REST_Request $request ) {
		$permission_error = $this->check_permission();
		if ( null !== $permission_error ) {
			return $permission_error;
		}

		$per_page  = min( 100, max( 1, \absint( $request->get_param( 'per_page' ) ?? 20 ) ) );
		$page      = max( 1, \absint( $request->get_param( 'page' ) ?? 1 ) );
		$tool_name = \sanitize_key( (string) ( $request->get_param( 'tool_name' ) ?? '' ) );
		$offset    = ( $page - 1 ) * $per_page;

		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;

		if ( '' !== $tool_name ) {
			/** @var array<\stdClass>|null $rows */
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE tool_name = %s ORDER BY id DESC LIMIT %d OFFSET %d",
					$tool_name,
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool_name = %s", $tool_name )
			);
		} else {
			/** @var array<\stdClass>|null $rows */
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$rows    = is_array( $rows ) ? $rows : [];
		$payload = [];
		foreach ( $rows as $row ) {
			$payload[] = [
				'id'               => (int) $row->id,
				'tool_name'        => (string) $row->tool_name,
				'user_id'          => (int) $row->user_id,
				'token_id'         => isset( $row->token_id ) ? (int) $row->token_id : null,
				'request_payload'  => isset( $row->request_payload ) ? (string) $row->request_payload : null,
				'response_summary' => isset( $row->response_summary ) ? (string) $row->response_summary : null,
				'success'          => (bool) (int) $row->success,
				'duration_ms'      => isset( $row->duration_ms ) ? (int) $row->duration_ms : null,
				'created_at'       => (string) $row->created_at,
			];
		}

		return \rest_ensure_response(
			[
				'data'    => $payload,
				'meta'    => [
					'total'       => $total,
					'page'        => $page,
					'per_page'    => $per_page,
					'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
				],
				'message' => \__( '成功取得 MCP 活動日誌', 'power-course' ),
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
