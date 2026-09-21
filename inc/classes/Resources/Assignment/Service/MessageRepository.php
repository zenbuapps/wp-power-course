<?php
/**
 * 作業討論訊息 Repository（Issue #131）
 *
 * 負責 pc_assignment_messages 資料表的存取。
 * 第一波 P2 只需要「依繳交刪除」（作業永久刪除時連動清除，DEC-06）；
 * 未讀數 / 總數 / 最後時間在 P4 補上。
 * 所有 SQL 一律 $wpdb->prepare()。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;

/**
 * Class MessageRepository
 */
final class MessageRepository {

	/** 單次 DELETE 的 IN 子句最多幾個 ID */
	private const DELETE_CHUNK_SIZE = 500;

	/**
	 * 取得資料表完整名稱（含 prefix）
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;
	}

	/**
	 * 一次取得多筆繳交的討論串統計（DEC-07，批改列表用，避免逐筆查）
	 *
	 * 未讀數的定義是「學員發送且管理員尚未讀」（sender_type = student 且 read_at IS NULL）；
	 * 訊息總數與最後訊息時間皆不計已軟刪除（deleted_at）的訊息。
	 * 沒有任何訊息的繳交不會出現在回傳陣列中，呼叫端自行補 0 / 0 / null。
	 *
	 * @param array<int, int> $submission_ids 繳交 ID 陣列
	 * @return array<int, array{unread_count: int, message_count: int, last_message_at: string|null}> 以 submission_id 為鍵
	 * @throws \RuntimeException 查詢失敗時。
	 */
	public static function thread_summary( array $submission_ids ): array {
		$ids = \array_values(
			\array_unique(
				\array_filter(
					\array_map( 'intval', $submission_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( ! $ids ) {
			return [];
		}

		global $wpdb;
		$table        = self::table();
		$placeholders = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table 為固定表名、$placeholders 只由 %d 組成
		$sql = "SELECT submission_id,
					COUNT(*) AS message_count,
					SUM( CASE WHEN sender_type = 'student' AND read_at IS NULL THEN 1 ELSE 0 END ) AS unread_count,
					MAX( created_at ) AS last_message_at
				FROM {$table}
				WHERE deleted_at IS NULL AND submission_id IN ({$placeholders})
				GROUP BY submission_id";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $ids ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error );
		}

		$summary = [];
		foreach ( $rows as $row ) {
			$submission_id = isset( $row['submission_id'] ) && \is_numeric( $row['submission_id'] ) ? (int) $row['submission_id'] : 0;
			if ( $submission_id <= 0 ) {
				continue;
			}

			$last = $row['last_message_at'] ?? null;

			$summary[ $submission_id ] = [
				'unread_count'    => isset( $row['unread_count'] ) && \is_numeric( $row['unread_count'] ) ? (int) $row['unread_count'] : 0,
				'message_count'   => isset( $row['message_count'] ) && \is_numeric( $row['message_count'] ) ? (int) $row['message_count'] : 0,
				'last_message_at' => \is_scalar( $last ) && '' !== (string) $last ? (string) $last : null,
			];
		}

		return $summary;
	}

	/**
	 * 刪除屬於指定繳交的所有討論訊息（含已軟刪除的訊息）
	 *
	 * @param array<int, int> $submission_ids 繳交 ID 陣列
	 * @return int 刪除的列數
	 * @throws \RuntimeException 刪除失敗時。
	 */
	public static function delete_by_submission_ids( array $submission_ids ): int {
		$submission_ids = \array_values(
			\array_unique(
				\array_filter(
					\array_map( 'intval', $submission_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( ! $submission_ids ) {
			return 0;
		}

		global $wpdb;
		$table   = self::table();
		$deleted = 0;

		foreach ( \array_chunk( $submission_ids, self::DELETE_CHUNK_SIZE ) as $chunk ) {
			$placeholders = \implode( ',', \array_fill( 0, \count( $chunk ), '%d' ) );

			$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE submission_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$chunk
				)
			);

			if ( false === $result ) {
				throw new \RuntimeException( $wpdb->last_error );
			}

			$deleted += (int) $result;
		}

		return $deleted;
	}
}
