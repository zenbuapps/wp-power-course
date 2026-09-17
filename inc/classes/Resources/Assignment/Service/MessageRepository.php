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
