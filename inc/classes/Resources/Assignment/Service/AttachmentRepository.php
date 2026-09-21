<?php
/**
 * 作業附件 Repository（Issue #131）
 *
 * 負責 pc_assignment_attachments 資料表的存取。所有 SQL 一律 $wpdb->prepare()；
 * $wpdb->insert 不 wp_slash（prepare 處理，無 unslash）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Attachment as AttachmentModel;

/**
 * Class AttachmentRepository
 */
final class AttachmentRepository {

	/**
	 * 取得資料表完整名稱（含 prefix）
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::ASSIGNMENT_ATTACHMENTS_TABLE_NAME;
	}

	/**
	 * 新增一筆附件紀錄
	 *
	 * @param int    $user_id       上傳者 user ID
	 * @param string $original_name 原始檔名
	 * @param string $stored_path   受保護目錄內的儲存路徑
	 * @param string $mime_type     標準 MIME 類型
	 * @param int    $file_size     檔案大小（bytes）
	 * @param string $created_at    建立時間（站台時區 Y-m-d H:i:s）
	 * @return int 新附件 ID
	 * @throws \RuntimeException 寫入失敗時。
	 */
	public static function insert( int $user_id, string $original_name, string $stored_path, string $mime_type, int $file_size, string $created_at ): int {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			[
				'user_id'       => $user_id,
				'original_name' => $original_name,
				'stored_path'   => $stored_path,
				'mime_type'     => $mime_type,
				'file_size'     => $file_size,
				'created_at'    => $created_at,
			],
			[ '%d', '%s', '%s', '%s', '%d', '%s' ]
		);

		if ( false === $result || $wpdb->insert_id <= 0 ) {
			throw new \RuntimeException( '' !== $wpdb->last_error ? $wpdb->last_error : 'Failed to insert assignment attachment' );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * 依 ID 取得附件
	 *
	 * @param int $attachment_id 附件 ID
	 * @return AttachmentModel|null
	 */
	public static function find( int $attachment_id ): ?AttachmentModel {
		global $wpdb;
		$table = self::table();

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$attachment_id
			),
			ARRAY_A
		);

		return \is_array( $row ) ? AttachmentModel::from_row( $row ) : null;
	}

	/**
	 * 一次取得多個附件的原始檔名（繳交回應補 file_name 用，避免逐筆查）
	 *
	 * @param array<int, int> $attachment_ids 附件 ID 陣列
	 * @return array<int, string> 附件 ID => 原始檔名（不存在的 ID 不會出現）
	 */
	public static function get_names( array $attachment_ids ): array {
		return self::get_names_query( $attachment_ids, null );
	}

	/**
	 * 一次取得「指定使用者上傳」的附件原始檔名（繳交時驗證附件歸屬用）
	 *
	 * 一次查完，不逐筆查；呼叫端以回傳筆數是否等於去重後的 ID 數判斷歸屬是否全部通過。
	 *
	 * @param array<int, int> $attachment_ids 附件 ID 陣列
	 * @param int             $user_id        上傳者 user ID
	 * @return array<int, string> 附件 ID => 原始檔名（不存在或非本人上傳的 ID 不會出現）
	 */
	public static function get_names_owned_by( array $attachment_ids, int $user_id ): array {
		return self::get_names_query( $attachment_ids, $user_id );
	}

	/**
	 * 共用查詢：供 get_names() 與 get_names_owned_by() 使用
	 *
	 * @param array<int, int> $attachment_ids 附件 ID 陣列
	 * @param int|null        $user_id        上傳者 user ID；null 表示不限上傳者
	 * @return array<int, string> 附件 ID => 原始檔名
	 */
	private static function get_names_query( array $attachment_ids, ?int $user_id ): array {
		global $wpdb;

		$ids = \array_values( \array_unique( \array_filter( \array_map( 'intval', $attachment_ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( ! $ids ) {
			return [];
		}

		$table        = self::table();
		$placeholders = \implode( ',', \array_fill( 0, \count( $ids ), '%d' ) );
		$owner_clause = null === $user_id ? '' : ' AND user_id = %d';

		$args = $ids;
		if ( null !== $user_id ) {
			$args[] = $user_id;
		}

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, original_name FROM {$table} WHERE id IN ({$placeholders}){$owner_clause}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$args
			),
			ARRAY_A
		);

		$names = [];
		foreach ( $rows as $row ) {
			$id = isset( $row['id'] ) && \is_numeric( $row['id'] ) ? (int) $row['id'] : 0;
			if ( $id > 0 ) {
				$names[ $id ] = isset( $row['original_name'] ) && \is_scalar( $row['original_name'] ) ? (string) $row['original_name'] : '';
			}
		}

		return $names;
	}
}
