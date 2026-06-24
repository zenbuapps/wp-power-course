<?php
/**
 * AccessPass Repository 整合測試
 * 測試 pc_user_access_pass 資料表的 CRUD 操作
 *
 * Feature: specs/entity/erm.dbml（TABLE: pc_user_access_pass）
 * Issue #252：課程通行證（Access Pass）資料層
 *
 * @group access-pass
 * @group repository
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use J7\PowerCourse\Resources\AccessPass\Service\Repository;

/**
 * Class RepositoryTest
 * 測試 pc_user_access_pass 資料表的 Repository 操作
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Repository 類別不存在
 *   - pc_user_access_pass 資料表尚未建立
 */
class RepositoryTest extends \Tests\Integration\TestCase {

	/** @var string 自訂表名稱（不含 prefix）*/
	private const TABLE_NAME = 'pc_user_access_pass';

	/** @var int 測試通行證 ID（pc_access_pass CPT post_id）*/
	private int $pass_id_a;

	/** @var int 第二個測試通行證 ID */
	private int $pass_id_b;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int Bob 用戶 ID */
	private int $bob_id;

	/** @var int 測試訂單 ID（模擬 source_order_id）*/
	private int $order_id;

	/**
	 * 初始化依賴（Repository 使用靜態方法，不需注入）
	 */
	protected function configure_dependencies(): void {
		// 確保 pc_user_access_pass 資料表存在
		// production code 尚未實作時，此表不存在，後續測試會明確失敗
		if ( method_exists( \J7\PowerCourse\AbstractTable::class, 'create_user_access_pass_table' ) ) {
			\J7\PowerCourse\AbstractTable::create_user_access_pass_table();
		}
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立測試通行證（pc_access_pass CPT）
		$this->pass_id_a = $this->factory()->post->create(
			[
				'post_type'   => 'pc_access_pass',
				'post_title'  => '全站通行證 A',
				'post_status' => 'publish',
			]
		);

		$this->pass_id_b = $this->factory()->post->create(
			[
				'post_type'   => 'pc_access_pass',
				'post_title'  => '分類通行證 B',
				'post_status' => 'publish',
			]
		);

		// Background：建立測試用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		$this->bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Bob'] = $this->bob_id;

		// 模擬一個訂單 ID（追溯用，不需真實建立 WC Order）
		$this->order_id = 999001;
	}

	/**
	 * 每個測試後清理 pc_user_access_pass 表
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Repository 類別存在
	 */
	public function test_repository_類別存在(): void {
		$this->assertTrue(
			class_exists( Repository::class ),
			'J7\PowerCourse\Resources\AccessPass\Service\Repository 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Repository 所有必要方法存在
	 */
	public function test_repository_必要方法存在(): void {
		$methods = [
			'insert_or_update',
			'find_by_user',
			'delete_by_pass',
			'count_distinct_users_by_pass',
		];

		foreach ( $methods as $method ) {
			$this->assertTrue(
				method_exists( Repository::class, $method ),
				"Repository::{$method} 方法不存在"
			);
		}
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 pc_user_access_pass 資料表存在
	 */
	public function test_資料表應存在(): void {
		global $wpdb;
		$table  = $wpdb->prefix . self::TABLE_NAME;
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $table, $result, "pc_user_access_pass 資料表不存在：{$table}" );
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * 首次 insert_or_update 應建立新列
	 *
	 * Rule（erm.dbml）：訂單達開通條件時新增一列
	 * Example：Alice 購買通行證 A，source_order_id=999001，expire_date=null（永久）
	 */
	public function test_首次insert_or_update建立新列(): void {
		// Given：無既有紀錄

		// When：呼叫 insert_or_update
		Repository::insert_or_update(
			$this->alice_id,
			$this->pass_id_a,
			$this->order_id,
			null
		);

		// Then：應建立一列
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertNotEmpty( $rows, '應找到 Alice 的持有紀錄' );
		$this->assertCount( 1, $rows, '應僅有一列' );

		$row = $rows[0];
		$this->assertSame( $this->alice_id, (int) $row->user_id, 'user_id 不符' );
		$this->assertSame( $this->pass_id_a, (int) $row->pass_id, 'pass_id 不符' );
		$this->assertSame( $this->order_id, (int) $row->source_order_id, 'source_order_id 不符' );
	}

	/**
	 * @test
	 * @group happy
	 * 相同 (user_id, pass_id) 重複 insert_or_update 應覆蓋而非新增重複列（upsert 語意）
	 *
	 * Rule（erm.dbml）：同 (user_id, pass_id) 後到覆蓋 expire/source_order，不產生重複列
	 * Example：Alice 第二次購買通行證 A（不同訂單），expire_date 更新為新到期時間
	 */
	public function test_重複insert_or_update覆蓋不產生重複列(): void {
		// Given：Alice 已持有通行證 A（訂單 999001，expire_date=null）
		Repository::insert_or_update(
			$this->alice_id,
			$this->pass_id_a,
			$this->order_id,
			null
		);

		// When：同一 (user_id, pass_id)，以新訂單 999002 與新 expire_date 更新
		$new_order_id   = 999002;
		$new_expire     = '1893456000'; // 2030-01-01 timestamp
		Repository::insert_or_update(
			$this->alice_id,
			$this->pass_id_a,
			$new_order_id,
			$new_expire
		);

		// Then：應僅有一列，不產生重複
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE_NAME;
		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE user_id = %d AND pass_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->alice_id,
				$this->pass_id_a
			)
		);
		$this->assertSame( 1, $count, '不應產生重複列，upsert 應更新既有列' );

		// And：更新後的欄位應反映最新值
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertCount( 1, $rows );
		$row = $rows[0];
		$this->assertSame( $new_order_id, (int) $row->source_order_id, 'source_order_id 應更新為新訂單' );
		$this->assertSame( $new_expire, $row->expire_date, 'expire_date 應更新為新到期時間' );
	}

	/**
	 * @test
	 * @group happy
	 * find_by_user 應回傳指定用戶的所有持有列
	 *
	 * Example：Alice 持有通行證 A 與 B，find_by_user(Alice) 應回傳 2 列
	 */
	public function test_find_by_user回傳指定用戶所有持有列(): void {
		// Given：Alice 持有通行證 A 與 B
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->alice_id, $this->pass_id_b, $this->order_id, null );

		// When：查詢 Alice 的持有列
		$rows = Repository::find_by_user( $this->alice_id );

		// Then：應回傳 2 列
		$this->assertCount( 2, $rows, 'find_by_user 應回傳 Alice 的全部 2 筆持有紀錄' );

		$pass_ids = array_map( fn( $r ) => (int) $r->pass_id, $rows );
		$this->assertContains( $this->pass_id_a, $pass_ids, '應包含通行證 A' );
		$this->assertContains( $this->pass_id_b, $pass_ids, '應包含通行證 B' );
	}

	/**
	 * @test
	 * @group happy
	 * find_by_user 無持有紀錄時應回傳空陣列
	 */
	public function test_find_by_user無紀錄回傳空陣列(): void {
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertIsArray( $rows, '回傳值應為陣列' );
		$this->assertEmpty( $rows, '無持有紀錄時 find_by_user 應回傳空陣列' );
	}

	/**
	 * @test
	 * @group happy
	 * delete_by_pass 應刪除指定通行證的所有持有列
	 *
	 * Rule（erm.dbml）：刪除權限包 → 連帶刪除此表對應 pass_id 的所有列
	 * Example：刪除通行證 A，Alice 與 Bob 對 A 的持有列均被刪除，B 的持有列不受影響
	 */
	public function test_delete_by_pass刪除指定通行證的所有持有列(): void {
		// Given：Alice 與 Bob 分別持有通行證 A；Alice 也持有通行證 B
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->bob_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->alice_id, $this->pass_id_b, $this->order_id, null );

		// When：刪除通行證 A 的所有持有列
		$deleted = Repository::delete_by_pass( $this->pass_id_a );

		// Then：應刪除 2 列（Alice + Bob 對通行證 A 的持有）
		$this->assertSame( 2, $deleted, "應刪除 2 列，實際刪除 {$deleted} 列" );

		// And：Alice 對通行證 A 的持有列不再存在
		$alice_rows = Repository::find_by_user( $this->alice_id );
		$alice_pass_ids = array_map( fn( $r ) => (int) $r->pass_id, $alice_rows );
		$this->assertNotContains( $this->pass_id_a, $alice_pass_ids, 'Alice 的通行證 A 持有列應已被刪除' );

		// And：Alice 對通行證 B 的持有列不受影響
		$this->assertContains( $this->pass_id_b, $alice_pass_ids, 'Alice 的通行證 B 持有列不應受影響' );
	}

	/**
	 * @test
	 * @group happy
	 * count_distinct_users_by_pass 應回傳 distinct 持有用戶數
	 *
	 * Example：通行證 A 被 Alice 與 Bob 持有，count = 2
	 */
	public function test_count_distinct_users_by_pass回傳distinct用戶數(): void {
		// Given：Alice 與 Bob 持有通行證 A
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->bob_id, $this->pass_id_a, $this->order_id, null );

		// When：計算通行證 A 的 distinct 用戶數
		$count = Repository::count_distinct_users_by_pass( $this->pass_id_a );

		// Then：應回傳 2
		$this->assertSame( 2, $count, 'count_distinct_users_by_pass 應回傳 2（Alice + Bob）' );
	}

	/**
	 * @test
	 * @group happy
	 * count_distinct_users_by_pass 無持有用戶時應回傳 0
	 */
	public function test_count_distinct_users_by_pass無用戶時回傳0(): void {
		$count = Repository::count_distinct_users_by_pass( $this->pass_id_a );
		$this->assertSame( 0, $count, '無持有用戶時 count_distinct_users_by_pass 應回傳 0' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * delete_by_pass 不應影響其他通行證的持有列
	 *
	 * Rule：刪除通行證 A 僅影響 pass_id = A 的列，其他通行證的持有不受影響
	 */
	public function test_delete_by_pass不影響其他通行證(): void {
		// Given：Alice 持有通行證 A 與 B
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->alice_id, $this->pass_id_b, $this->order_id, null );

		// When：刪除通行證 A
		Repository::delete_by_pass( $this->pass_id_a );

		// Then：通行證 B 的持有列應不受影響
		$alice_rows     = Repository::find_by_user( $this->alice_id );
		$alice_pass_ids = array_map( fn( $r ) => (int) $r->pass_id, $alice_rows );
		$this->assertContains( $this->pass_id_b, $alice_pass_ids, '通行證 B 的持有列不應被刪除' );
		$this->assertCount( 1, $alice_rows, '刪除通行證 A 後 Alice 應只剩 1 筆持有紀錄（通行證 B）' );
	}

	/**
	 * @test
	 * @group edge
	 * count_distinct_users_by_pass 同一用戶多次持有（upsert 後）應計 1 個 distinct user
	 *
	 * Rule：即使 Alice 多次購買同一通行證，upsert 確保 DB 只有 1 列，distinct count = 1
	 */
	public function test_count_distinct_users_by_pass同一用戶多次upsert計1(): void {
		// Given：Alice 持有通行證 A（第一次），然後再次購買（第二次，相同 pass_id）
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, 111, null );
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, 222, null );

		// Then：count 應為 1（upsert 確保同 (user_id, pass_id) 只有一列）
		$count = Repository::count_distinct_users_by_pass( $this->pass_id_a );
		$this->assertSame( 1, $count, 'upsert 後同一用戶應只計為 1 個 distinct user' );
	}

	/**
	 * @test
	 * @group edge
	 * find_by_user 不應回傳其他用戶的持有列
	 *
	 * Rule：查詢只回傳指定 user_id 的列，不洩漏其他用戶資料
	 */
	public function test_find_by_user不回傳其他用戶資料(): void {
		// Given：Alice 與 Bob 各自持有通行證 A
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );
		Repository::insert_or_update( $this->bob_id, $this->pass_id_a, $this->order_id, null );

		// When：查詢 Alice 的持有列
		$alice_rows = Repository::find_by_user( $this->alice_id );

		// Then：只回傳 Alice 的列
		$this->assertCount( 1, $alice_rows, 'find_by_user 只應回傳 Alice 的列' );
		$this->assertSame( $this->alice_id, (int) $alice_rows[0]->user_id, 'user_id 應為 Alice' );
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 為 null 時表示永久持有（依 erm.dbml 規範）
	 */
	public function test_expire_date為null表示永久持有(): void {
		// Given：Alice 持有通行證 A，expire_date=null（永久）
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, null );

		// Then：expire_date 應儲存為 null 或 "0"（依實作慣例）
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertCount( 1, $rows );
		$expire = $rows[0]->expire_date;
		// erm.dbml：expire_date null/"0"=永久
		$this->assertTrue(
			$expire === null || $expire === '0' || $expire === '',
			"expire_date 應為 null、'0' 或空字串以表示永久，實際值：" . var_export( $expire, true )
		);
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 為 timestamp 字串時應正確儲存（限時模式）
	 *
	 * erm.dbml：limited 模式 → 10 位 Unix timestamp（granted 時 = now + limit_value*limit_unit）
	 */
	public function test_expire_date為timestamp字串時正確儲存(): void {
		$expire_date = '1893456000'; // 2030-01-01 00:00:00 UTC

		// When：insert_or_update with 10-digit timestamp
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, $expire_date );

		// Then：expire_date 應正確儲存
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $expire_date, $rows[0]->expire_date, 'expire_date timestamp 字串應正確儲存' );
	}

	/**
	 * @test
	 * @group edge
	 * expire_date 為 subscription 字串時應正確儲存（跟隨訂閱模式）
	 *
	 * erm.dbml：follow_subscription → "subscription_{訂閱id}"
	 */
	public function test_expire_date為subscription字串時正確儲存(): void {
		$expire_date = 'subscription_42'; // 跟隨訂閱 ID=42

		// When：insert_or_update with subscription expire
		Repository::insert_or_update( $this->alice_id, $this->pass_id_a, $this->order_id, $expire_date );

		// Then：expire_date 應正確儲存
		$rows = Repository::find_by_user( $this->alice_id );
		$this->assertCount( 1, $rows );
		$this->assertSame( $expire_date, $rows[0]->expire_date, 'expire_date subscription 字串應正確儲存' );
	}

	/**
	 * @test
	 * @group edge
	 * delete_by_pass 無持有列時應回傳 0（不拋出例外）
	 */
	public function test_delete_by_pass無資料時回傳0(): void {
		$deleted = Repository::delete_by_pass( $this->pass_id_a );
		$this->assertSame( 0, $deleted, '無資料時 delete_by_pass 應回傳 0 而非拋出例外' );
	}
}
