<?php
/**
 * 作業資料表建立與 DB 版本升級 整合測試（Issue #131 第一波 P1）
 *
 * 無直接對應的 .feature（本階段支撐後續所有作業功能），依據：
 *   - specs/entity/erm.dbml TABLE: pc_assignment_submissions / pc_assignment_attachments / pc_assignment_messages
 *   - specs/plan/issue-131-assignment-grading/implementation-plan.md §9 P1（DDL 要點、DEC-04）
 *
 * ─── 本層「能驗什麼、不能驗什麼」───────────────────────────────────────
 * wp-phpunit 會把測試進行中的 CREATE TABLE / DROP TABLE 改寫成 TEMPORARY 版本，
 * WP::is_table_exists()（查 information_schema）看不到 temporary table，
 * 所以本檔一律以 DESCRIBE / SHOW INDEX FROM 驗證表 / 欄位 / 索引是否可用，
 * 不使用 WP::is_table_exists()（參考 .claude/rules/wordpress.rule.md、AccessPass/MigrationTest.php 的教訓）。
 *
 * 表名一律透過 Plugin::ASSIGNMENT_*_TABLE_NAME 常數組出（計劃 §5.1「修改」表 plugin.php 一列），
 * 常數在 Green 之前不存在，於方法內參照會拋 Error: Undefined constant ——
 * 這是本檔預期的紅燈型態，不在 class 宣告層級引用，才不會讓整個檔案載入就 fatal。
 * ────────────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\AbstractTable;

/**
 * Class AssignmentTablesMigrationTest
 * 驗證 pc_assignment_submissions / pc_assignment_attachments / pc_assignment_messages
 * 三張自訂表的建立、DDL 結構、DB 版本升級（1.1.0 → 1.2.0）與冪等性
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME 等三個常數尚未定義
 *   - AbstractTable::create_assignment_*_table() 尚未實作
 *   - AbstractTable::CURRENT_DB_VERSION 仍為 1.1.0（計劃 DEC-04 要求推進到 1.2.0）
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentTablesMigrationTest extends TestCase {

	/**
	 * 初始化依賴（本檔不需要 repos/services，只需確保三張作業表存在）
	 *
	 * production code 尚未實作 create_assignment_*_table() 時，method_exists() 守衛讓
	 * configure_dependencies() 本身不會 fatal——真正的紅燈由各測試方法內參照
	 * Plugin::ASSIGNMENT_*_TABLE_NAME 常數時拋出，錯誤原因才會單一、乾淨。
	 */
	protected function configure_dependencies(): void {
		if ( \method_exists( AbstractTable::class, 'create_assignment_submissions_table' ) ) {
			AbstractTable::create_assignment_submissions_table();
		}
		if ( \method_exists( AbstractTable::class, 'create_assignment_attachments_table' ) ) {
			AbstractTable::create_assignment_attachments_table();
		}
		if ( \method_exists( AbstractTable::class, 'create_assignment_messages_table' ) ) {
			AbstractTable::create_assignment_messages_table();
		}
	}

	/**
	 * 清理本檔自己寫入 pc_assignment_submissions 的測試列（唯一索引測試）
	 * 常數 / 表尚不存在時安全跳過，不讓 tear_down 本身 fatal
	 */
	public function tear_down(): void {
		global $wpdb;

		if ( \defined( Plugin::class . '::ASSIGNMENT_SUBMISSIONS_TABLE_NAME' ) ) {
			$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;
			if ( ! empty( $this->describe_columns( $table ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "DELETE FROM `{$table}`" );
			}
		}

		parent::tear_down();
	}

	// ========== 表名 Helper（一律經 Plugin 常數，未定義時在此拋出 Undefined constant）==========

	/**
	 * @return string pc_assignment_submissions 完整表名（含 prefix）
	 */
	private function submissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;
	}

	/**
	 * @return string pc_assignment_attachments 完整表名（含 prefix）
	 */
	private function attachments_table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_ATTACHMENTS_TABLE_NAME;
	}

	/**
	 * @return string pc_assignment_messages 完整表名（含 prefix）
	 */
	private function messages_table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;
	}

	// ========== DDL 檢查 Helper ==========

	/**
	 * 取得資料表所有欄位的 DESCRIBE 結果，以欄位名為 key
	 *
	 * @param string $table 完整資料表名稱（含 prefix）
	 * @return array<string, object{Field:string,Type:string,Null:string,Key:string,Default:?string,Extra:string}>
	 */
	private function describe_columns( string $table ): array {
		global $wpdb;

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "DESCRIBE `{$table}`" );
		$wpdb->suppress_errors( false );

		$columns = [];
		foreach ( (array) $rows as $row ) {
			$columns[ $row->Field ] = $row;
		}
		return $columns;
	}

	/**
	 * 斷言欄位存在，且型別（子字串比對）與 NULL 允許性符合 DDL 要點
	 *
	 * @param array<string, object> $columns        describe_columns() 的回傳
	 * @param string                $field          欄位名
	 * @param string                $type_substring  期望型別包含的子字串（如 bigint / varchar(20) / longtext）
	 * @param bool                  $nullable       是否允許 NULL
	 * @param string                $table_label     錯誤訊息用的表名
	 */
	private function assert_column( array $columns, string $field, string $type_substring, bool $nullable, string $table_label ): void {
		$this->assertArrayHasKey( $field, $columns, "{$table_label} 缺少欄位 {$field}" );
		$this->assertStringContainsStringIgnoringCase(
			$type_substring,
			$columns[ $field ]->Type,
			"{$table_label}.{$field} 型別應包含「{$type_substring}」，實際為「{$columns[ $field ]->Type}」"
		);
		$this->assertSame(
			$nullable ? 'YES' : 'NO',
			$columns[ $field ]->Null,
			"{$table_label}.{$field} 的 NULL 允許性不符（計劃 §9 P1 DDL 要點）"
		);
	}

	/**
	 * 斷言索引存在，且欄位組成 / 順序 / 唯一性符合 DDL 要點
	 *
	 * @param string        $table             完整資料表名稱（含 prefix）
	 * @param string        $key_name          索引名稱
	 * @param array<string> $expected_columns  依 Seq_in_index 排序後應有的欄位順序
	 * @param bool          $unique            是否應為 UNIQUE 索引
	 * @param string        $table_label       錯誤訊息用的表名
	 */
	private function assert_index( string $table, string $key_name, array $expected_columns, bool $unique, string $table_label ): void {
		global $wpdb;

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$key_name}'" );
		$wpdb->suppress_errors( false );

		$rows = (array) $rows;
		$this->assertNotEmpty( $rows, "{$table_label} 缺少索引 {$key_name}" );

		\usort( $rows, static fn( $a, $b ) => ( (int) $a->Seq_in_index ) <=> ( (int) $b->Seq_in_index ) );
		$actual_columns = \array_map( static fn( $r ) => $r->Column_name, $rows );

		$this->assertSame( $expected_columns, $actual_columns, "{$table_label} 索引 {$key_name} 欄位組成 / 順序不符" );
		$this->assertSame(
			$unique ? '0' : '1',
			(string) $rows[0]->Non_unique,
			"{$table_label} 索引 {$key_name} 的唯一性設定不符（期望 " . ( $unique ? 'UNIQUE' : '非 UNIQUE' ) . '）'
		);
	}

	// ========== 三張表的結構測試（DDL 要點：計劃 §9 P1）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_assignment_submissions 表存在，欄位型別與索引符合計劃 §9 P1 DDL 要點
	 */
	public function test_pc_assignment_submissions資料表結構符合DDL要點(): void {
		$table   = $this->submissions_table();
		$columns = $this->describe_columns( $table );
		$this->assertNotEmpty( $columns, 'pc_assignment_submissions 資料表應存在且有欄位' );

		$label = 'pc_assignment_submissions';
		$this->assert_column( $columns, 'id', 'bigint', false, $label );
		$this->assert_column( $columns, 'assignment_id', 'bigint', false, $label );
		$this->assert_column( $columns, 'user_id', 'bigint', false, $label );
		$this->assert_column( $columns, 'text_body', 'longtext', true, $label );
		$this->assert_column( $columns, 'attachments', 'longtext', false, $label );
		$this->assert_column( $columns, 'submitted_at', 'datetime', false, $label );
		$this->assert_column( $columns, 'status', 'varchar(20)', false, $label );
		$this->assertSame( 'pending', $columns['status']->Default, "{$label}.status 預設值應為 pending" );
		$this->assert_column( $columns, 'feedback', 'text', true, $label );
		$this->assert_column( $columns, 'graded_by', 'bigint', true, $label );
		$this->assert_column( $columns, 'graded_at', 'datetime', true, $label );
		$this->assert_column( $columns, 'created_at', 'datetime', false, $label );
		$this->assert_column( $columns, 'updated_at', 'datetime', false, $label );

		$this->assert_index( $table, 'uq_assignment_submissions_owner', [ 'assignment_id', 'user_id' ], true, $label );
		$this->assert_index( $table, 'idx_assignment_submissions_assignment', [ 'assignment_id', 'submitted_at' ], false, $label );
		$this->assert_index( $table, 'idx_assignment_submissions_user', [ 'user_id', 'submitted_at' ], false, $label );
		$this->assert_index( $table, 'idx_assignment_submissions_status', [ 'status', 'submitted_at' ], false, $label );
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_assignment_attachments 表存在，欄位型別與索引符合計劃 §9 P1 DDL 要點
	 */
	public function test_pc_assignment_attachments資料表結構符合DDL要點(): void {
		$table   = $this->attachments_table();
		$columns = $this->describe_columns( $table );
		$this->assertNotEmpty( $columns, 'pc_assignment_attachments 資料表應存在且有欄位' );

		$label = 'pc_assignment_attachments';
		$this->assert_column( $columns, 'id', 'bigint', false, $label );
		$this->assert_column( $columns, 'user_id', 'bigint', false, $label );
		$this->assert_column( $columns, 'original_name', 'varchar(255)', false, $label );
		$this->assert_column( $columns, 'stored_path', 'varchar(500)', false, $label );
		$this->assert_column( $columns, 'mime_type', 'varchar(100)', false, $label );
		$this->assert_column( $columns, 'file_size', 'bigint', false, $label );
		$this->assert_column( $columns, 'created_at', 'datetime', false, $label );

		$this->assert_index( $table, 'idx_assignment_attachments_user', [ 'user_id' ], false, $label );
	}

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_assignment_messages 表存在，欄位型別與索引符合計劃 §9 P1 DDL 要點
	 *
	 * Issue #131 第二波才會有讀寫 API，但表在第一波就必須建好（計劃 §2：
	 * 「pc_assignment_messages 表在第一波一起建，讓 DB 版本只升一次」）
	 */
	public function test_pc_assignment_messages資料表結構符合DDL要點(): void {
		$table   = $this->messages_table();
		$columns = $this->describe_columns( $table );
		$this->assertNotEmpty( $columns, 'pc_assignment_messages 資料表應存在且有欄位' );

		$label = 'pc_assignment_messages';
		$this->assert_column( $columns, 'id', 'bigint', false, $label );
		$this->assert_column( $columns, 'submission_id', 'bigint', false, $label );
		$this->assert_column( $columns, 'sender_type', 'varchar(20)', false, $label );
		$this->assert_column( $columns, 'sender_id', 'bigint', true, $label );
		$this->assert_column( $columns, 'sender_email', 'varchar(100)', true, $label );
		$this->assert_column( $columns, 'body', 'text', false, $label );
		$this->assert_column( $columns, 'read_at', 'datetime', true, $label );
		$this->assert_column( $columns, 'created_at', 'datetime', false, $label );
		$this->assert_column( $columns, 'deleted_at', 'datetime', true, $label );

		$this->assert_index( $table, 'idx_assignment_messages_submission', [ 'submission_id', 'created_at' ], false, $label );
	}

	// ========== 唯一索引真的擋重複（計劃 §9 P1：「uq_assignment_submissions_owner 真的擋重複」）==========

	/**
	 * @test
	 * @group edge
	 * 測試：同一 (assignment_id, user_id) 第二筆 insert 應被 uq_assignment_submissions_owner 擋下，
	 * 資料表內該組合仍只有 1 筆（對照 erm.dbml Submit Note 第 5 點：UPSERT on (assignment_id, user_id)）
	 */
	public function test_同一作業同一學員第二次insert被唯一索引擋下(): void {
		global $wpdb;
		$table = $this->submissions_table();

		$row = [
			'assignment_id' => 1,
			'user_id'       => 1,
			'text_body'     => '第一次繳交內容',
			'attachments'   => '[]',
			'submitted_at'  => \current_time( 'mysql' ),
			'status'        => 'pending',
			'created_at'    => \current_time( 'mysql' ),
			'updated_at'    => \current_time( 'mysql' ),
		];

		$first = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, $first, '第一筆 (assignment_id=1, user_id=1) 繳交應成功寫入' );

		$wpdb->suppress_errors( true );
		$second = $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->suppress_errors( false );

		$this->assertFalse(
			$second,
			'同 (assignment_id, user_id) 的第二筆 insert 應被 uq_assignment_submissions_owner 唯一索引擋下'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE assignment_id = %d AND user_id = %d", 1, 1 )
		);
		$this->assertSame( 1, $count, '重複 insert 被擋下後，該 (assignment_id, user_id) 應仍只有 1 筆' );
	}

	// ========== DB 版本升級（計劃 DEC-04：1.1.0 → 1.2.0）與冪等 ==========

	/**
	 * @test
	 * @group smoke
	 * 測試：pc_db_version 停留在舊版（1.1.0）時呼叫 AbstractTable::maybe_upgrade()，
	 * 版本應推進到 CURRENT_DB_VERSION（計劃 DEC-04：1.2.0），且三張作業表可用；
	 * 重複呼叫一次不應拋出例外、版本不變（冪等）
	 */
	public function test_舊版本呼叫maybe_upgrade會升級並補建三張表且冪等(): void {
		\update_option( AbstractTable::DB_VERSION_OPTION, '1.1.0' );

		AbstractTable::maybe_upgrade();

		$this->assertSame(
			'1.2.0',
			AbstractTable::CURRENT_DB_VERSION,
			'AbstractTable::CURRENT_DB_VERSION 應推進到 1.2.0（計劃 DEC-04）'
		);
		$this->assertSame(
			AbstractTable::CURRENT_DB_VERSION,
			\get_option( AbstractTable::DB_VERSION_OPTION ),
			'maybe_upgrade() 後 pc_db_version option 應更新為 CURRENT_DB_VERSION'
		);

		$this->assertNotEmpty( $this->describe_columns( $this->submissions_table() ), 'maybe_upgrade() 後 pc_assignment_submissions 應可用' );
		$this->assertNotEmpty( $this->describe_columns( $this->attachments_table() ), 'maybe_upgrade() 後 pc_assignment_attachments 應可用' );
		$this->assertNotEmpty( $this->describe_columns( $this->messages_table() ), 'maybe_upgrade() 後 pc_assignment_messages 應可用' );

		try {
			AbstractTable::maybe_upgrade();
		} catch ( \Throwable $th ) {
			$this->fail( 'maybe_upgrade() 重複呼叫不應拋出例外（冪等）：' . $th->getMessage() );
		}

		$this->assertSame(
			AbstractTable::CURRENT_DB_VERSION,
			\get_option( AbstractTable::DB_VERSION_OPTION ),
			'重複呼叫 maybe_upgrade() 後版本應維持不變（冪等）'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 測試：DB 版本升級（maybe_upgrade）不影響既有 6 張自訂表（pc_avl_coursemeta 等）的既有列
	 *
	 * 回歸守門聲明（P1-R 自我申報）：AbstractTable::maybe_upgrade() 現在就存在，且三個
	 * create_assignment_*_table() 早退式設計本來就不會清空既有表，所以本測試在實作前就會通過。
	 * 目的是守住「之後修改 install() / maybe_upgrade() 時不得誤動既有表資料」這條回歸線，
	 * 而不是驗證本階段的新行為。
	 */
	public function test_升級不影響既有六張自訂表的既有列(): void {
		$course_id = $this->create_course();
		$user_id   = $this->factory()->user->create();
		$this->enroll_user_to_course( $user_id, $course_id, 0 );

		$expire_date_before = $this->get_course_meta( $course_id, $user_id, 'expire_date' );
		$this->assertNotSame( '', $expire_date_before, '前置：expire_date 應已寫入（enroll_user_to_course 建立資料失敗）' );

		AbstractTable::maybe_upgrade();

		$this->assertSame(
			$expire_date_before,
			$this->get_course_meta( $course_id, $user_id, 'expire_date' ),
			'maybe_upgrade() 不應影響 pc_avl_coursemeta 既有列（Plugin::COURSE_TABLE_NAME）'
		);
		$this->assert_user_has_course_access( $user_id, $course_id );
	}

	// ========== Plugin::activate() 建表（計劃 §5.1：plugin.php activate() 補呼叫）==========

	/**
	 * @test
	 * @group smoke
	 * 測試：Plugin::activate() 執行後，三張作業表可用
	 */
	public function test_plugin_activate之後三張作業表可用(): void {
		Plugin::instance()->activate();

		$this->assertNotEmpty( $this->describe_columns( $this->submissions_table() ), 'activate() 後 pc_assignment_submissions 應可用' );
		$this->assertNotEmpty( $this->describe_columns( $this->attachments_table() ), 'activate() 後 pc_assignment_attachments 應可用' );
		$this->assertNotEmpty( $this->describe_columns( $this->messages_table() ), 'activate() 後 pc_assignment_messages 應可用' );
	}
}
