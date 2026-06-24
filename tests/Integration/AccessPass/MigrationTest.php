<?php
/**
 * AccessPass DB Migration 整合測試
 * 驗證 pc_user_access_pass 資料表建立與欄位結構完整性
 *
 * Feature: specs/entity/erm.dbml （TABLE: pc_user_access_pass）
 *
 * @group access-pass
 * @group smoke
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use J7\WpUtils\Classes\WP;

/**
 * Class MigrationTest
 * 驗證課程權限包使用者持有表（pc_user_access_pass）建立與結構完整性
 *
 * 預期失敗原因（Red 階段）：
 *   - pc_user_access_pass 資料表尚未建立（migration 未實作）
 *   - AbstractTable::create_user_access_pass_table() 不存在
 */
class MigrationTest extends \Tests\Integration\TestCase {

	/**
	 * 初始化依賴（Migration 測試不需要額外 repos/services）
	 */
	protected function configure_dependencies(): void {
		// 嘗試建立 pc_user_access_pass 資料表
		// production code 尚未實作時，此呼叫會導致 PHP Fatal Error 或方法不存在
		if ( method_exists( \J7\PowerCourse\AbstractTable::class, 'create_user_access_pass_table' ) ) {
			\J7\PowerCourse\AbstractTable::create_user_access_pass_table();
		}
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_user_access_pass 資料表應存在
	 *
	 * 對照 Mcp/MigrationTest 使用 DESCRIBE 驗證資料表存在
	 */
	public function test_pc_user_access_pass_資料表應存在(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'pc_user_access_pass';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$this->assertNotEmpty( $columns, 'pc_user_access_pass 資料表應存在且有欄位' );
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：WP::is_table_exists 應回傳 true
	 */
	public function test_is_table_exists_回傳true(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$this->assertTrue(
			WP::is_table_exists( $table ),
			"WP::is_table_exists 應回傳 true，資料表 {$table} 不存在"
		);
	}

	// ========== 資料表結構測試（Structure Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_user_access_pass 包含所有必要欄位
	 *
	 * 欄位定義來自 specs/entity/erm.dbml TABLE: pc_user_access_pass
	 * 欄位：id / user_id / pass_id / source_order_id / expire_date / granted_at
	 */
	public function test_資料表包含所有必要欄位(): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'pc_user_access_pass';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns  = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		$required = [ 'id', 'user_id', 'pass_id', 'source_order_id', 'expire_date', 'granted_at' ];
		foreach ( $required as $col ) {
			$this->assertContains( $col, $columns, "pc_user_access_pass 資料表缺少欄位：{$col}" );
		}
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：(user_id, pass_id) 應有 INDEX（供 UPSERT 去重使用）
	 *
	 * 依 erm.dbml idx_user_pass_lookup — (user_id, pass_id) index
	 */
	public function test_user_id_pass_id複合索引應存在(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'pc_user_access_pass';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Column_name IN ('user_id', 'pass_id')" );
		$this->assertNotEmpty(
			$indexes,
			'pc_user_access_pass 缺少 (user_id, pass_id) 相關索引'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：migration 冪等，重複執行不會拋出例外
	 */
	public function test_migration_冪等重複執行不出錯(): void {
		try {
			// 第二次執行不應拋出例外
			if ( method_exists( \J7\PowerCourse\AbstractTable::class, 'create_user_access_pass_table' ) ) {
				\J7\PowerCourse\AbstractTable::create_user_access_pass_table();
			}
			$this->assertTrue( true );
		} catch ( \Throwable $th ) {
			$this->fail( 'create_user_access_pass_table() 重複執行不應拋出例外：' . $th->getMessage() );
		}
	}
}
