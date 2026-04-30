<?php
/**
 * MCP Settings — 讀寫 MCP 全域設定
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class Settings
 * 管理 MCP 功能的開關、啟用的 tool categories、速率限制、AI 修改/刪除權限等設定
 */
final class Settings {

	/** WordPress option key */
	const OPTION_KEY = 'pc_mcp_settings';

	/** 預設速率限制（每分鐘請求數） */
	const DEFAULT_RATE_LIMIT = 60;

	/**
	 * 取得完整設定陣列
	 *
	 * @return array{enabled: bool, enabled_categories: array<string>, rate_limit_per_min: int, allow_update: bool, allow_delete: bool}
	 */
	private function get_all(): array {
		/** @var mixed $raw */
		$raw = get_option( self::OPTION_KEY, [] );

		$defaults = [
			'enabled'            => false,
			'enabled_categories' => [],
			'rate_limit_per_min' => self::DEFAULT_RATE_LIMIT,
			'allow_update'       => false,
			'allow_delete'       => false,
		];

		if ( ! is_array( $raw ) ) {
			return $defaults;
		}

		/** @var array{enabled: bool, enabled_categories: array<string>, rate_limit_per_min: int, allow_update: bool, allow_delete: bool} $merged */
		$merged = wp_parse_args( $raw, $defaults );
		return $merged;
	}

	/**
	 * 取得已啟用的 tool categories 清單
	 *
	 * @return array<string>
	 */
	public function get_enabled_categories(): array {
		$settings = $this->get_all();
		return $settings['enabled_categories'];
	}

	/**
	 * 設定啟用的 tool categories
	 *
	 * @param string[] $categories category 識別符清單
	 * @return bool
	 */
	public function set_enabled_categories( array $categories ): bool {
		$settings                       = $this->get_all();
		$settings['enabled_categories'] = array_values( array_map( 'sanitize_key', $categories ) );
		return (bool) update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * 判斷指定 category 是否啟用
	 * 空 enabled_categories = 全部啟用（安裝即可用）
	 *
	 * @param string $category category 識別符
	 * @return bool
	 */
	public function is_category_enabled( string $category ): bool {
		$cats = $this->get_enabled_categories();
		if ( empty( $cats ) ) {
			return true; // 空 = 全部啟用，安裝即可用
		}
		return in_array( $category, $cats, true );
	}

	/**
	 * MCP Server 是否整體啟用
	 *
	 * @return bool
	 */
	public function is_server_enabled(): bool {
		$settings = $this->get_all();
		return (bool) $settings['enabled'];
	}

	/**
	 * 設定 MCP Server 整體啟用狀態
	 *
	 * @param bool $enabled 是否啟用
	 * @return bool
	 */
	public function set_server_enabled( bool $enabled ): bool {
		$settings            = $this->get_all();
		$settings['enabled'] = $enabled;
		return (bool) update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * 取得速率限制（每分鐘請求數）
	 *
	 * @return int
	 */
	public function get_rate_limit(): int {
		$settings = $this->get_all();
		$limit    = (int) $settings['rate_limit_per_min'];
		return max( 1, $limit );
	}

	/**
	 * AI 是否被允許執行修改類操作（OP_UPDATE）
	 *
	 * 預設為 false（唯讀模式），站長必須在後台「設定 → AI」開啟才允許。
	 * 此設定取代舊版的伺服器環境變數 ALLOW_UPDATE。
	 *
	 * @return bool
	 */
	public function is_update_allowed(): bool {
		$settings = $this->get_all();
		return (bool) $settings['allow_update'];
	}

	/**
	 * AI 是否被允許執行刪除類操作（OP_DELETE）
	 *
	 * 預設為 false（唯讀模式），站長必須在後台「設定 → AI」開啟才允許。
	 * 此設定取代舊版的伺服器環境變數 ALLOW_DELETE。
	 *
	 * @return bool
	 */
	public function is_delete_allowed(): bool {
		$settings = $this->get_all();
		return (bool) $settings['allow_delete'];
	}

	/**
	 * 設定是否允許 AI 修改類操作
	 *
	 * @param bool $allowed 是否允許
	 * @return bool
	 */
	public function set_update_allowed( bool $allowed ): bool {
		$settings                 = $this->get_all();
		$settings['allow_update'] = $allowed;
		return (bool) update_option( self::OPTION_KEY, $settings );
	}

	/**
	 * 設定是否允許 AI 刪除類操作
	 *
	 * @param bool $allowed 是否允許
	 * @return bool
	 */
	public function set_delete_allowed( bool $allowed ): bool {
		$settings                 = $this->get_all();
		$settings['allow_delete'] = $allowed;
		return (bool) update_option( self::OPTION_KEY, $settings );
	}
}
