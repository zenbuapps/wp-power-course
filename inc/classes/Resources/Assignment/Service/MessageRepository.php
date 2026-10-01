<?php
/**
 * 作業討論訊息 Repository（Issue #131）
 *
 * 負責 pc_assignment_messages 資料表的存取。
 * 第一波 P2 只需要「依繳交刪除」（作業永久刪除時連動清除，DEC-06）；
 * 未讀數 / 總數 / 最後時間在 P4 補上；第二波補上討論串的寫入、讀取與標已讀。
 * 所有 SQL 一律 $wpdb->prepare()；$wpdb->insert 不 wp_slash（prepare 處理，無 unslash）。
 * 讀取、標已讀、統計一律排除已軟刪除（deleted_at IS NOT NULL）的訊息（DEC-W2-07）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Message as MessageModel;

/**
 * Class MessageRepository
 */
final class MessageRepository {

	/** 單次 DELETE 的 IN 子句最多幾個 ID */
	private const DELETE_CHUNK_SIZE = 500;

	/** 讀取訊息時只取這些欄位：sender_id / sender_email 不離開資料庫（DEC-W2-06） */
	private const VIEW_COLUMNS = 'id, submission_id, sender_type, body, read_at, created_at';

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
	 * 新增一則討論訊息（read_at、deleted_at 一律 NULL）
	 *
	 * @param int         $submission_id 所屬繳交 ID
	 * @param string      $sender_type   MessageModel::SENDER_STUDENT | SENDER_TEACHER
	 * @param int|null    $sender_id     發送者 user ID
	 * @param string|null $sender_email  發送者 email 快照（學員訊息為 null）
	 * @param string      $body          訊息內容（原樣寫入）
	 * @param string      $created_at    建立時間（站台時區 Y-m-d H:i:s）
	 * @return int 新訊息 ID
	 * @throws \RuntimeException 寫入失敗時。
	 */
	public static function insert( int $submission_id, string $sender_type, ?int $sender_id, ?string $sender_email, string $body, string $created_at ): int {
		global $wpdb;

		// $wpdb->insert 對 null 值會輸出 SQL NULL，不需要自行組佔位符
		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			[
				'submission_id' => $submission_id,
				'sender_type'   => $sender_type,
				'sender_id'     => $sender_id,
				'sender_email'  => $sender_email,
				'body'          => $body,
				'read_at'       => null,
				'created_at'    => $created_at,
				'deleted_at'    => null,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( false === $result || $wpdb->insert_id <= 0 ) {
			throw new \RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : 'Failed to insert assignment message' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 依 ID 取得訊息
	 *
	 * @param int $message_id 訊息 ID
	 * @return MessageModel|null
	 */
	public static function find( int $message_id ): ?MessageModel {
		global $wpdb;
		$table   = self::table();
		$columns = self::VIEW_COLUMNS;

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$message_id
			),
			ARRAY_A
		);

		return \is_array( $row ) ? MessageModel::from_row( $row ) : null;
	}

	/**
	 * 取得某繳交的討論串（排除已軟刪除，依 created_at ASC, id ASC；同一秒以 id 決定先後，DEC-W2-06）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array<int, MessageModel>
	 * @throws \RuntimeException 查詢失敗時。
	 */
	public static function list_by_submission( int $submission_id ): array {
		global $wpdb;
		$table   = self::table();
		$columns = self::VIEW_COLUMNS;

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT {$columns} FROM {$table} WHERE submission_id = %d AND deleted_at IS NULL ORDER BY created_at ASC, id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$submission_id
			),
			ARRAY_A
		);

		if ( '' !== $wpdb->last_error ) {
			throw new \RuntimeException( $wpdb->last_error );
		}

		return \array_values(
			\array_map(
				static fn( array $row ): MessageModel => MessageModel::from_row( $row ),
				(array) $rows
			)
		);
	}

	/**
	 * 把某繳交中「指定發送方、尚未讀、未軟刪除」的訊息標為已讀（DEC-W2-04）
	 *
	 * 已有 read_at 的訊息不覆寫；兩位管理員同時讀取時，後者因 read_at IS NULL 條件更新 0 列。
	 *
	 * @param int    $submission_id 繳交 ID
	 * @param string $sender_type   要標已讀的那一方（查詢端的對方）
	 * @param string $read_at       讀取時間（站台時區 Y-m-d H:i:s）
	 * @return int 更新的列數
	 * @throws \RuntimeException 更新失敗時。
	 */
	public static function mark_read( int $submission_id, string $sender_type, string $read_at ): int {
		global $wpdb;
		$table = self::table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET read_at = %s WHERE submission_id = %d AND sender_type = %s AND read_at IS NULL AND deleted_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$read_at,
				$submission_id,
				$sender_type
			)
		);

		if ( false === $result ) {
			throw new \RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : 'Failed to mark assignment messages as read' );
		}

		return (int) $result;
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
					SUM( CASE WHEN sender_type = %s AND read_at IS NULL THEN 1 ELSE 0 END ) AS unread_count,
					MAX( created_at ) AS last_message_at
				FROM {$table}
				WHERE deleted_at IS NULL AND submission_id IN ({$placeholders})
				GROUP BY submission_id";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// 未讀 = 學員發送且管理員尚未讀；sender_type 與寫入端共用 Model 常數（R-W2-19）
		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, \array_merge( [ MessageModel::SENDER_STUDENT ], $ids ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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
