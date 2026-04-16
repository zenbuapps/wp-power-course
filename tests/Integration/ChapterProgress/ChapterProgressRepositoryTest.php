<?php
/**
 * ChapterProgress Repository 整合測試
 * Feature: specs/features/progress/紀錄章節續播秒數.feature
 * 測試 pc_chapter_progress 資料表的 CRUD 操作
 *
 * @group chapter-progress
 * @group repository
 */

declare( strict_types=1 );

namespace Tests\Integration\ChapterProgress;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\ChapterProgress\Service\Repository as ChapterProgressRepository;
use J7\PowerCourse\Resources\ChapterProgress\Model\ChapterProgress;

/**
 * Class ChapterProgressRepositoryTest
 * 測試 pc_chapter_progress 資料表操作
 */
final class ChapterProgressRepositoryTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試章節 ID */
	private int $chapter_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// Repository 為靜態方法類別
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background: 建立測試課程
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		// Background: 建立測試章節
		$this->chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第一章',
			]
		);

		// Background: 建立 Alice 用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;
	}

	/**
	 * 每個測試後清理 chapter_progress 表
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 pc_chapter_progress 資料表存在
	 * 驗證：AbstractTable::create_chapter_progress_table() 已在 bootstrap 時建立表
	 */
	public function test_chapter_progress_資料表應存在(): void {
		global $wpdb;

		// 驗證資料表存在
		$table  = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore

		$this->assertSame(
			$table,
			$result,
			"pc_chapter_progress 資料表不存在：{$table}"
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Repository 類別存在
	 */
	public function test_repository_類別存在(): void {
		$this->assertTrue(
			class_exists( ChapterProgressRepository::class ),
			'ChapterProgressRepository 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Model 類別存在
	 */
	public function test_model_類別存在(): void {
		$this->assertTrue(
			class_exists( ChapterProgress::class ),
			'ChapterProgress Model 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Repository 方法存在
	 */
	public function test_repository_方法應存在(): void {
		$this->assertTrue(
			method_exists( ChapterProgressRepository::class, 'find' ),
			'Repository::find 方法不存在'
		);
		$this->assertTrue(
			method_exists( ChapterProgressRepository::class, 'upsert' ),
			'Repository::upsert 方法不存在'
		);
		$this->assertTrue(
			method_exists( ChapterProgressRepository::class, 'delete_by_course_user' ),
			'Repository::delete_by_course_user 方法不存在'
		);
	}

	// ========== 資料表結構測試 ==========

	/**
	 * @test
	 * @group structure
	 * 驗證資料表含有正確的欄位
	 */
	public function test_資料表欄位應正確(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;

		$columns = $wpdb->get_results( "DESCRIBE {$table}" ); // phpcs:ignore
		$column_names = array_column( $columns, 'Field' );

		$expected_columns = [
			'id',
			'user_id',
			'chapter_id',
			'course_id',
			'last_position_seconds',
			'updated_at',
			'created_at',
		];

		foreach ( $expected_columns as $col ) {
			$this->assertContains( $col, $column_names, "資料表缺少欄位：{$col}" );
		}
	}

	/**
	 * @test
	 * @group structure
	 * 驗證 (user_id, chapter_id) UNIQUE INDEX 存在
	 */
	public function test_unique_index應存在(): void {
		global $wpdb;
		$table   = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table} WHERE Non_unique = 0 AND Key_name != 'PRIMARY'" ); // phpcs:ignore

		$this->assertNotEmpty(
			$indexes,
			'pc_chapter_progress 缺少 UNIQUE INDEX'
		);

		// 驗證 unique index 包含 user_id 與 chapter_id
		$index_columns = array_column( $indexes, 'Column_name' );
		$this->assertContains( 'user_id', $index_columns, 'UNIQUE INDEX 缺少 user_id 欄位' );
		$this->assertContains( 'chapter_id', $index_columns, 'UNIQUE INDEX 缺少 chapter_id 欄位' );
	}

	// ========== 快樂路徑（Happy Flow）==========

	/**
	 * @test
	 * @group happy
	 * 首次 upsert 應建立新列
	 * Rule: (user_id, chapter_id) 複合 unique index，寫入採 upsert
	 * Example: 首次寫入建立新列
	 */
	public function test_首次upsert建立新列(): void {
		// Given: 無既有紀錄

		// When: 呼叫 upsert
		ChapterProgressRepository::upsert(
			$this->alice_id,
			$this->chapter_id,
			$this->course_id,
			42
		);

		// Then: 應建立一列
		$result = ChapterProgressRepository::find( $this->alice_id, $this->chapter_id );

		$this->assertNotNull( $result, '應找到新建立的紀錄' );
		$this->assertSame( $this->alice_id, $result->user_id, 'user_id 不符' );
		$this->assertSame( $this->chapter_id, $result->chapter_id, 'chapter_id 不符' );
		$this->assertSame( 42, $result->last_position_seconds, 'last_position_seconds 應為 42' );
	}

	/**
	 * @test
	 * @group happy
	 * 既有紀錄更新不產生重複列
	 * Rule: (user_id, chapter_id) 複合 unique index，寫入採 upsert
	 * Example: 既有紀錄更新不產生重複列
	 */
	public function test_upsert更新既有紀錄不重複(): void {
		// Given: Alice 在章節 chapter_id 的 last_position_seconds 為 60
		ChapterProgressRepository::upsert(
			$this->alice_id,
			$this->chapter_id,
			$this->course_id,
			60
		);

		// When: 再次 upsert，秒數改為 120
		ChapterProgressRepository::upsert(
			$this->alice_id,
			$this->chapter_id,
			$this->course_id,
			120
		);

		// Then: 應僅有一列
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND chapter_id = %d", // phpcs:ignore
				$this->alice_id,
				$this->chapter_id
			)
		);

		$this->assertSame( 1, $count, '不應產生重複列' );

		// And: last_position_seconds 應為 120
		$result = ChapterProgressRepository::find( $this->alice_id, $this->chapter_id );
		$this->assertNotNull( $result );
		$this->assertSame( 120, $result->last_position_seconds, 'last_position_seconds 應更新為 120' );
	}

	/**
	 * @test
	 * @group happy
	 * updated_at 由 server NOW() 寫入（不由 PHP 端傳入時間戳）
	 * Rule: 一律用 SQL NOW() 寫 updated_at，PHP 不傳時間戳
	 */
	public function test_updated_at由server寫入(): void {
		$before = gmdate( 'Y-m-d H:i:s' );

		ChapterProgressRepository::upsert(
			$this->alice_id,
			$this->chapter_id,
			$this->course_id,
			100
		);

		$after  = gmdate( 'Y-m-d H:i:s' );
		$result = ChapterProgressRepository::find( $this->alice_id, $this->chapter_id );

		$this->assertNotNull( $result );
		$this->assertNotNull( $result->updated_at, 'updated_at 不應為 null' );

		// updated_at 應在操作前後時間範圍內（容忍 2 秒誤差）
		$updated_timestamp = strtotime( $result->updated_at );
		$before_timestamp  = strtotime( $before ) - 2;
		$after_timestamp   = strtotime( $after ) + 2;

		$this->assertGreaterThanOrEqual(
			$before_timestamp,
			$updated_timestamp,
			'updated_at 早於操作開始時間'
		);
		$this->assertLessThanOrEqual(
			$after_timestamp,
			$updated_timestamp,
			'updated_at 晚於操作結束時間'
		);
	}

	/**
	 * @test
	 * @group happy
	 * delete_by_course_user 應刪除指定 user+course 的所有紀錄
	 */
	public function test_delete_by_course_user刪除所有紀錄(): void {
		// Given: 建立多個章節 progress 紀錄
		$chapter_id_2 = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第二章' ]
		);

		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_id, $this->course_id, 60 );
		ChapterProgressRepository::upsert( $this->alice_id, $chapter_id_2, $this->course_id, 120 );

		// 確認資料已建立
		$this->assertNotNull( ChapterProgressRepository::find( $this->alice_id, $this->chapter_id ) );
		$this->assertNotNull( ChapterProgressRepository::find( $this->alice_id, $chapter_id_2 ) );

		// When: 呼叫 delete_by_course_user
		$deleted = ChapterProgressRepository::delete_by_course_user( $this->alice_id, $this->course_id );

		// Then: 應刪除 2 列
		$this->assertSame( 2, $deleted, "應刪除 2 列，實際刪除 {$deleted} 列" );

		// And: 找不到任何紀錄
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $this->chapter_id ),
			'chapter_id 的紀錄應已被刪除'
		);
		$this->assertNull(
			ChapterProgressRepository::find( $this->alice_id, $chapter_id_2 ),
			'chapter_id_2 的紀錄應已被刪除'
		);
	}

	/**
	 * @test
	 * @group happy
	 * find 無紀錄時應回傳 null
	 */
	public function test_find無紀錄回傳null(): void {
		$result = ChapterProgressRepository::find( $this->alice_id, $this->chapter_id );
		$this->assertNull( $result, '無紀錄時 find 應回傳 null' );
	}

	// ========== 邊緣案例（Edge Cases）==========

	/**
	 * @test
	 * @group edge
	 * delete_by_course_user 不應刪除其他用戶的紀錄
	 */
	public function test_delete_by_course_user僅刪除指定用戶紀錄(): void {
		$bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
			]
		);

		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_id, $this->course_id, 60 );
		ChapterProgressRepository::upsert( $bob_id, $this->chapter_id, $this->course_id, 90 );

		// 刪除 Alice 的紀錄
		ChapterProgressRepository::delete_by_course_user( $this->alice_id, $this->course_id );

		// Bob 的紀錄不應被刪除
		$bob_result = ChapterProgressRepository::find( $bob_id, $this->chapter_id );
		$this->assertNotNull( $bob_result, 'Bob 的紀錄不應被刪除' );
		$this->assertSame( 90, $bob_result->last_position_seconds );
	}
}
