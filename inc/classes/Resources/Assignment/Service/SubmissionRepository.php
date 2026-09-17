<?php
/**
 * 作業繳交 Repository（Issue #131）
 *
 * 負責 pc_assignment_submissions 資料表的存取。
 * 第一波 P2 只需要「依作業查 ID / 依作業刪除」（作業永久刪除時連動清除，DEC-06）；
 * 繳交 UPSERT、依附件 ID 搜尋等在 P3 補上。
 * 所有 SQL 一律 $wpdb->prepare()。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;

/**
 * Class SubmissionRepository
 */
final class SubmissionRepository {

	/**
	 * 取得資料表完整名稱（含 prefix）
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;
	}

	/**
	 * 取得指定作業的所有繳交 ID
	 *
	 * @param int $assignment_id 作業 ID
	 * @return array<int, int> 繳交 ID 陣列（依 id 由小到大）
	 * @throws \RuntimeException 查詢失敗時。
	 */
	public static function get_ids_by_assignment( int $assignment_id ): array {
		global $wpdb;
		$table = self::table();

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE assignment_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$assignment_id
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error );
		}

		return \array_map( 'intval', $ids );
	}

	/**
	 * 刪除指定作業的所有繳交
	 *
	 * 只刪繳交列；討論訊息由 MessageRepository::delete_by_submission_ids() 處理，
	 * 附件列與實體檔案保留（作業刪除.feature：附件仍在）。
	 *
	 * @param int $assignment_id 作業 ID
	 * @return int 刪除的列數
	 * @throws \RuntimeException 刪除失敗時。
	 */
	public static function delete_by_assignment( int $assignment_id ): int {
		global $wpdb;
		$table = self::table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE assignment_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$assignment_id
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( $wpdb->last_error );
		}

		return (int) $result;
	}
}
