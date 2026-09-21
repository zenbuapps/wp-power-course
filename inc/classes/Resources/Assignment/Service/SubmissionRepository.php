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
use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;

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
	 * 依 ID 取得繳交
	 *
	 * @param int $submission_id 繳交 ID
	 * @return SubmissionModel|null
	 */
	public static function find( int $submission_id ): ?SubmissionModel {
		global $wpdb;
		$table = self::table();

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$submission_id
			),
			ARRAY_A
		);

		return \is_array( $row ) ? SubmissionModel::from_row( $row ) : null;
	}

	/**
	 * 取得某學員在某作業的繳交（UNIQUE KEY (assignment_id, user_id)，最多一筆）
	 *
	 * @param int $assignment_id 作業 ID
	 * @param int $user_id       學員 user ID
	 * @return SubmissionModel|null
	 */
	public static function find_by_owner( int $assignment_id, int $user_id ): ?SubmissionModel {
		global $wpdb;
		$table = self::table();

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE assignment_id = %d AND user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$assignment_id,
				$user_id
			),
			ARRAY_A
		);

		return \is_array( $row ) ? SubmissionModel::from_row( $row ) : null;
	}

	/**
	 * 一次取得某學員在多份作業的繳交（學員端列表用，避免逐筆查）
	 *
	 * @param array<int, int> $assignment_ids 作業 ID 陣列
	 * @param int             $user_id        學員 user ID
	 * @return array<int, SubmissionModel> 以 assignment_id 為鍵
	 */
	public static function find_many_by_owner( array $assignment_ids, int $user_id ): array {
		global $wpdb;

		$ids = \array_values( \array_unique( \array_filter( \array_map( 'intval', $assignment_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids ) {
			return [];
		}

		$table        = self::table();
		$placeholders = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND assignment_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				\array_merge( [ $user_id ], $ids )
			),
			ARRAY_A
		);

		$submissions = [];
		foreach ( $rows as $row ) {
			$submission = SubmissionModel::from_row( $row );
			$submissions[ $submission->assignment_id ] = $submission;
		}

		return $submissions;
	}

	/**
	 * 繳交 UPSERT（INSERT … ON DUPLICATE KEY UPDATE，計劃 §6.1）
	 *
	 * UNIQUE KEY uq_assignment_submissions_owner (assignment_id, user_id) 保證每位學員每份作業一筆；
	 * 重交時覆寫內容、狀態重設為 pending，並清空 feedback / graded_by / graded_at。
	 * 併發雙擊也只會留下一筆。
	 *
	 * @param int                                                           $assignment_id 作業 ID
	 * @param int                                                           $user_id       學員 user ID
	 * @param string|null                                                   $text_body     文字內容（空內容為 null）
	 * @param array<int, array{attachment_id: int, file_name: string|null}> $attachments   附件參照
	 * @param string                                                        $now           繳交時間（站台時區 Y-m-d H:i:s）
	 * @throws \RuntimeException 寫入失敗時。
	 */
	public static function upsert( int $assignment_id, int $user_id, ?string $text_body, array $attachments, string $now ): void {
		global $wpdb;
		$table = self::table();

		// text_body 可為 NULL；$wpdb->prepare() 的 %s 會把 null 轉成空字串，故以佔位符切換維持 NULL 語意
		$text_placeholder = null === $text_body ? 'NULL' : '%s';

		$args = [ $assignment_id, $user_id ];
		if ( null !== $text_body ) {
			$args[] = $text_body;
		}
		$args[] = SubmissionModel::encode_attachments( $attachments );
		$args[] = $now;
		$args[] = SubmissionModel::DEFAULT_STATUS;
		$args[] = $now;
		$args[] = $now;

		$sql = "INSERT INTO {$table}
					(assignment_id, user_id, text_body, attachments, submitted_at, status, feedback, graded_by, graded_at, created_at, updated_at)
				VALUES
					(%d, %d, {$text_placeholder}, %s, %s, %s, NULL, NULL, NULL, %s, %s)
				ON DUPLICATE KEY UPDATE
					text_body    = VALUES(text_body),
					attachments  = VALUES(attachments),
					submitted_at = VALUES(submitted_at),
					status       = VALUES(status),
					feedback     = NULL,
					graded_by    = NULL,
					graded_at    = NULL,
					updated_at   = VALUES(updated_at)";

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		if ( false === $result ) {
			throw new \RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : 'Failed to upsert assignment submission' );
		}
	}

	/**
	 * 寫入批改結果（計劃 §6.3）
	 *
	 * 任何 status（含改回 pending）都會寫入 graded_by / graded_at；
	 * 未提供評語時 feedback 一律清成 NULL（批改作業.feature「未提供 feedback 時評語清空」）。
	 * $wpdb->update() 對 null 值會輸出 SQL NULL，不需要自行組佔位符。
	 *
	 * @param int         $submission_id 繳交 ID
	 * @param string      $status        pending | pass | fail
	 * @param string|null $feedback      評語（null = 清空）
	 * @param int         $graded_by     批改者 user ID
	 * @param string      $graded_at     批改時間（站台時區 Y-m-d H:i:s）
	 * @throws \RuntimeException 寫入失敗時。
	 */
	public static function update_grade( int $submission_id, string $status, ?string $feedback, int $graded_by, string $graded_at ): void {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			[
				'status'     => $status,
				'feedback'   => $feedback,
				'graded_by'  => $graded_by,
				'graded_at'  => $graded_at,
				'updated_at' => $graded_at,
			],
			[ 'id' => $submission_id ],
			[ '%s', '%s', '%d', '%s', '%s' ],
			[ '%d' ]
		);

		if ( false === $result ) {
			throw new \RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : 'Failed to update assignment submission grade' );
		}
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
	 * 指定附件是否出現在任一繳交的 attachments JSON 中（管理員下載驗權用）
	 *
	 * 欄位 attachments 以 wp_json_encode 固定鍵順序存 [{"attachment_id":700,"file_name":…}]，
	 * 因此以 LIKE '%"attachment_id":700,%' 搜尋；pattern 含結尾逗號，避免 700 誤中 7000（R-16）。
	 * 不用 JSON_CONTAINS：舊版 MariaDB 不一定支援。
	 *
	 * @param int $attachment_id 附件 ID
	 * @return bool
	 * @throws \RuntimeException 查詢失敗時。
	 */
	public static function exists_with_attachment( int $attachment_id ): bool {
		global $wpdb;
		$table = self::table();

		$pattern = '%' . $wpdb->esc_like( '"attachment_id":' . $attachment_id . ',' ) . '%';

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE attachments LIKE %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pattern
			)
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error );
		}

		return null !== $found;
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
