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
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Core\Loader;

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

	// ========== 期限 meta 遷移測試（limit_mode → limit_type）==========

	/**
	 * 建立一個帶舊 limit_mode meta 的 pc_access_pass post（模擬 1.0.0 既有資料）
	 *
	 * @param string $old_limit_mode 舊期限模式值（permanent | limited | follow_subscription）
	 * @return int pass post ID
	 */
	private function create_legacy_pass( string $old_limit_mode ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '舊版權限包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $pass_id, 'scope_type', 'all' );
		\update_post_meta( $pass_id, 'limit_mode', $old_limit_mode );
		\update_post_meta( $pass_id, 'access_pass_status', 'active' );

		return $pass_id;
	}

	/**
	 * 每個遷移測試後清理 CPT
	 */
	public function tear_down(): void {
		$passes = \get_posts(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $passes as $pass_id ) {
			\wp_force_delete_post( $pass_id );
		}
		parent::tear_down();
	}

	/**
	 * @test
	 * @group migration
	 * 測試：遷移方法存在
	 */
	public function test_遷移方法存在(): void {
		$this->assertTrue(
			\method_exists( Loader::class, 'migrate_limit_mode_to_limit_type' ),
			'Loader::migrate_limit_mode_to_limit_type 方法不存在'
		);
	}

	/**
	 * @test
	 * @group migration
	 * 測試：permanent → unlimited 映射，且舊 meta 被刪除
	 */
	public function test_遷移permanent映射為unlimited(): void {
		$pass_id = $this->create_legacy_pass( 'permanent' );

		Loader::migrate_limit_mode_to_limit_type();

		$this->assertSame( 'unlimited', \get_post_meta( $pass_id, 'limit_type', true ), 'permanent 應遷移為 unlimited' );
		$this->assertSame( '', \get_post_meta( $pass_id, 'limit_mode', true ), '舊 limit_mode meta 應被刪除' );
	}

	/**
	 * @test
	 * @group migration
	 * 測試：limited → fixed 映射
	 */
	public function test_遷移limited映射為fixed(): void {
		$pass_id = $this->create_legacy_pass( 'limited' );

		Loader::migrate_limit_mode_to_limit_type();

		$this->assertSame( 'fixed', \get_post_meta( $pass_id, 'limit_type', true ), 'limited 應遷移為 fixed' );
		$this->assertSame( '', \get_post_meta( $pass_id, 'limit_mode', true ), '舊 limit_mode meta 應被刪除' );
	}

	/**
	 * @test
	 * @group migration
	 * 測試：follow_subscription → follow_subscription（不變）
	 */
	public function test_遷移follow_subscription維持不變(): void {
		$pass_id = $this->create_legacy_pass( 'follow_subscription' );

		Loader::migrate_limit_mode_to_limit_type();

		$this->assertSame( 'follow_subscription', \get_post_meta( $pass_id, 'limit_type', true ), 'follow_subscription 應維持不變' );
		$this->assertSame( '', \get_post_meta( $pass_id, 'limit_mode', true ), '舊 limit_mode meta 應被刪除' );
	}

	/**
	 * @test
	 * @group migration
	 * 測試：遷移冪等 — 重複執行不改變已遷移結果、不拋例外
	 */
	public function test_遷移冪等重複執行(): void {
		$pass_id = $this->create_legacy_pass( 'limited' );

		// 第一次遷移
		Loader::migrate_limit_mode_to_limit_type();
		$first = \get_post_meta( $pass_id, 'limit_type', true );

		// 第二次遷移（此時已無 limit_mode meta，應 no-op）
		Loader::migrate_limit_mode_to_limit_type();
		$second = \get_post_meta( $pass_id, 'limit_type', true );

		$this->assertSame( 'fixed', $first, '第一次遷移後應為 fixed' );
		$this->assertSame( $first, $second, '重複遷移結果應一致（冪等）' );
		$this->assertSame( '', \get_post_meta( $pass_id, 'limit_mode', true ), '重複遷移後仍無舊 meta' );
	}

	/**
	 * @test
	 * @group migration
	 * 測試：已是新契約（無舊 limit_mode meta，已有 limit_type）的 post，遷移不覆蓋
	 */
	public function test_遷移不覆蓋已是新契約的值(): void {
		// 模擬以新契約建立的 post：只有 limit_type，沒有 limit_mode
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '新契約權限包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $pass_id, 'limit_type', 'assigned' );

		Loader::migrate_limit_mode_to_limit_type();

		$this->assertSame( 'assigned', \get_post_meta( $pass_id, 'limit_type', true ), '已是新契約的 limit_type 不應被遷移覆蓋' );
	}
}
