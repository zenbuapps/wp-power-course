<?php
/**
 * MCP DB Migration 整合測試
 *
 * @group smoke
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\Migration;

/**
 * Class MigrationTest
 * 驗證 MCP 資料表建立與結構完整性
 */
class MigrationTest extends IntegrationTestCase {

	/**
	 * 測試：install() 建立 tokens 資料表
	 * 用 DESCRIBE 驗證資料表存在（WP 測試環境的 DDL 在 information_schema 有延遲）
	 *
	 * @group smoke
	 */
	public function test_install_creates_tokens_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$this->assertNotEmpty( $columns, "wp_pc_mcp_tokens 資料表應存在且有欄位" );
	}

	/**
	 * 測試：install() 建立 activity 資料表
	 *
	 * @group smoke
	 */
	public function test_install_creates_activity_table(): void {
		global $wpdb;
		$table   = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$this->assertNotEmpty( $columns, "wp_pc_mcp_activity 資料表應存在且有欄位" );
	}

	/**
	 * 測試：tokens 資料表包含 capabilities 欄位
	 *
	 * @group smoke
	 */
	public function test_tokens_table_has_capabilities_column(): void {
		global $wpdb;
		$table   = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$this->assertContains( 'capabilities', $columns, "tokens 資料表應有 capabilities 欄位" );
	}

	/**
	 * 測試：tokens 資料表包含所有必要欄位
	 *
	 * @group smoke
	 */
	public function test_tokens_table_has_required_columns(): void {
		global $wpdb;
		$table    = $wpdb->prefix . Migration::TOKENS_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns  = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$required = [ 'id', 'token_hash', 'user_id', 'name', 'capabilities', 'last_used_at', 'created_at', 'expires_at', 'revoked_at' ];
		foreach ( $required as $col ) {
			$this->assertContains( $col, $columns, "tokens 資料表缺少欄位：{$col}" );
		}
	}

	/**
	 * 測試：activity 資料表包含所有必要欄位
	 *
	 * @group smoke
	 */
	public function test_activity_table_has_required_columns(): void {
		global $wpdb;
		$table    = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns  = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$required = [ 'id', 'tool_name', 'user_id', 'token_id', 'request_payload', 'response_summary', 'success', 'duration_ms', 'created_at' ];
		foreach ( $required as $col ) {
			$this->assertContains( $col, $columns, "activity 資料表缺少欄位：{$col}" );
		}
	}

	/**
	 * 測試：install() 冪等，重複執行不會出錯
	 *
	 * @group smoke
	 */
	public function test_install_is_idempotent(): void {
		// 第二次執行不應拋出例外
		try {
			Migration::install();
			$this->assertTrue( true );
		} catch ( \Throwable $th ) {
			$this->fail( "Migration::install() 重複執行不應拋出例外：" . $th->getMessage() );
		}
	}
}
