<?php
/**
 * MCP Bearer Token Authentication — 將 MCP Token 串接到 WordPress 認證流程
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class BearerAuth
 * 透過 determine_current_user filter 讓 MCP Bearer Token 可以認證 REST API 請求
 * 僅在 REST API 請求且攜帶 Authorization: Bearer 標頭時觸發
 */
final class BearerAuth {

	/**
	 * Constructor
	 * 掛載 determine_current_user filter（priority 20，在 WP 預設 cookie auth 之後）
	 */
	public function __construct() {
		add_filter( 'determine_current_user', [ $this, 'authenticate' ], 20 );
	}

	/**
	 * 嘗試以 Bearer Token 認證使用者
	 * 若已有認證使用者或無 Bearer Token 則不介入
	 *
	 * @param int|false $user_id 目前的使用者 ID（0 或 false 表示未認證）
	 * @return int|false 認證後的使用者 ID
	 */
	public function authenticate( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id;
		}

		if ( ! $this->is_rest_request() ) {
			return $user_id;
		}

		$token = $this->extract_bearer_token();
		if ( null === $token ) {
			return $user_id;
		}

		$settings = new Settings();
		if ( ! $settings->is_server_enabled() ) {
			return $user_id;
		}

		$auth = new Auth();
		$user = $auth->verify_bearer_token( $token );

		if ( $user instanceof \WP_User ) {
			return $user->ID;
		}

		return $user_id;
	}

	/**
	 * 從 HTTP 請求標頭提取 Bearer Token
	 *
	 * @return string|null token 明文或 null
	 */
	private function extract_bearer_token(): ?string {
		$header = $this->get_authorization_header();

		if ( null === $header ) {
			return null;
		}

		if ( 0 !== strncasecmp( $header, 'Bearer ', 7 ) ) {
			return null;
		}

		$token = trim( substr( $header, 7 ) );

		return '' !== $token ? $token : null;
	}

	/**
	 * 取得 Authorization 標頭（相容各種伺服器環境）
	 *
	 * @return string|null
	 */
	private function get_authorization_header(): ?string {
		// Apache / most servers
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}

		// Nginx proxy / FastCGI
		if ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return sanitize_text_field( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		// Apache mod_rewrite fallback
		if ( function_exists( 'apache_request_headers' ) ) {
			$headers = apache_request_headers();
			// 標頭名稱可能大小寫不一致
			foreach ( $headers as $key => $value ) {
				if ( 0 === strcasecmp( (string) $key, 'Authorization' ) ) {
					return sanitize_text_field( (string) $value );
				}
			}
		}

		return null;
	}

	/**
	 * 判斷目前是否為 REST API 請求
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return true;
		}

		$rest_prefix = rest_get_url_prefix();
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		return false !== strpos( $request_uri, '/' . $rest_prefix . '/' );
	}
}
