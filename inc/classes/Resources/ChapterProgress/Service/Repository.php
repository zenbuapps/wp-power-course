<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\ChapterProgress\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\ChapterProgress\Model\ChapterProgress;

/**
 * 章節續播進度 Repository
 * 負責 pc_chapter_progress 資料表的 CRUD 操作
 * 所有 SQL 查詢使用 $wpdb->prepare() 防止 SQL injection
 */
final class Repository {

	/**
	 * 查詢指定用戶與章節的進度紀錄
	 *
	 * @param int $user_id    用戶 ID
	 * @param int $chapter_id 章節 ID
	 * @return ChapterProgress|null 找不到時回傳 null
	 */
	public static function find( int $user_id, int $chapter_id ): ?ChapterProgress {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND chapter_id = %d LIMIT 1", // phpcs:ignore
				$user_id,
				$chapter_id
			)
		);

		if ( null === $row ) {
			return null;
		}

		return ChapterProgress::from_row( $row );
	}

	/**
	 * 寫入或更新章節進度（INSERT ... ON DUPLICATE KEY UPDATE）
	 * updated_at 一律由 SQL NOW() 寫入，PHP 不傳入時間戳（R1 風險緩解）
	 *
	 * @param int $user_id               用戶 ID
	 * @param int $chapter_id            章節 ID
	 * @param int $course_id             課程 ID（server 端計算）
	 * @param int $last_position_seconds 播放秒數（已四捨五入的整數）
	 * @return void
	 */
	public static function upsert( int $user_id, int $chapter_id, int $course_id, int $last_position_seconds ): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (user_id, chapter_id, course_id, last_position_seconds, updated_at, created_at)
				VALUES (%d, %d, %d, %d, NOW(), NOW())
				ON DUPLICATE KEY UPDATE
					last_position_seconds = VALUES(last_position_seconds),
					updated_at            = NOW()",
				$user_id,
				$chapter_id,
				$course_id,
				$last_position_seconds
			)
		);
	}

	/**
	 * 刪除指定用戶在指定課程下的所有進度紀錄
	 * 於退課時呼叫
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 * @return int 刪除的列數
	 */
	public static function delete_by_course_user( int $user_id, int $course_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;

		$result = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND course_id = %d", // phpcs:ignore
				$user_id,
				$course_id
			)
		);

		return (int) $result;
	}
}
