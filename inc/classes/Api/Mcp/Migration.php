<?php
/**
 * MCP DB Migration — 建立兩張 MCP 專用資料表
 * 以及初始化預設的 MCP options
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

use J7\WpUtils\Classes\WP;

/**
 * Class Migration
 * 負責建立 wp_pc_mcp_tokens 與 wp_pc_mcp_activity 兩張資料表，
 * 以及管理 MCP DB 版本控制
 */
final class Migration {

	/** MCP token 表名（不含 prefix） */
	const TOKENS_TABLE_NAME = 'pc_mcp_tokens';

	/** MCP 活動日誌表名（不含 prefix） */
	const ACTIVITY_TABLE_NAME = 'pc_mcp_activity';

	/** MCP DB 版本 option key */
	const DB_VERSION_OPTION = 'pc_mcp_db_version';

	/** 當前 DB schema 版本 */
	const CURRENT_DB_VERSION = '1.0.0';

	/**
	 * 執行 Migration：建立資料表、設定 option
	 * 此方法應在 plugin 啟用 hook 中呼叫，冪等設計，可重複執行
	 *
	 * @return void
	 */
	public static function install(): void {
		self::create_tokens_table();
		self::create_activity_table();
		update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * 建立 MCP Token 資料表
	 *
	 * @return void
	 */
	private static function create_tokens_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . self::TOKENS_TABLE_NAME;

		// 使用 dbDelta 確保冪等性（資料表已存在時不重建）
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token_hash VARCHAR(255) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			capabilities LONGTEXT NULL,
			last_used_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * 建立 MCP 活動日誌資料表
	 *
	 * @return void
	 */
	private static function create_activity_table(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . self::ACTIVITY_TABLE_NAME;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tool_name VARCHAR(100) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			token_id BIGINT UNSIGNED NULL,
			request_payload LONGTEXT NULL,
			response_summary VARCHAR(500) NULL,
			success TINYINT(1) NOT NULL DEFAULT 0,
			duration_ms INT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tool_name (tool_name),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
