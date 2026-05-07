<?php
/**
 * MCP REST Controller — AI Tab 共用的 settings endpoints
 *
 * 提供:
 * - GET/POST  power-course/mcp/settings           讀寫 MCP 啟用狀態與 allow_update / allow_delete（給 AI tab 用）
 *
 * MCP 後台管理面板（Settings > MCP tab）已下架，原本的 tokens / activity 管理 endpoint 一併移除；
 * 此 controller 現在只剩 settings 兩條路由，由 AI tab 共用 useMcpSettings() hook 讀寫
 * allow_update / allow_delete 等 AI 權限旗標。
 *
 * 注意：對外 MCP server endpoint（mcp-adapter 註冊在 'power-course/v2/mcp'）屬於另一條對外契約路由，
 * 與此 namespace 互不衝突，且未在此次下架範圍內。
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
