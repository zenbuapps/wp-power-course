<?php
/**
 * AccessPass Repository（Issue #252）
 *
 * 負責 pc_user_access_pass 資料表的 CRUD 操作。
 * 所有 SQL 查詢使用 $wpdb->prepare() 防止 SQL injection。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;

/**
 * Class Repository
 * pc_user_access_pass 自訂關聯表（使用者 ↔ 權限包持有關係）的存取層。
 */
final class Repository {

	/**
	 * 取得資料表完整名稱（含 prefix）
	 *
	 * @return string
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;
	}

	/**
	 * 寫入或更新持有關係（同 (user_id, pass_id) 採 upsert 去重）
	 *
	 * Rule（erm.dbml）：同 (user_id, pass_id) 後到覆蓋 expire/source_order，不產生重複列。
	 * 資料表 (user_id, pass_id) 為非唯一索引（供查找），故以 SELECT → UPDATE/INSERT 達成 upsert 語意。
	 *
	 * @param int         $user_id         學員 user ID
	 * @param int         $pass_id         權限包 post ID
	 * @param int|null    $source_order_id 取得來源 WC 訂單 ID
	 * @param string|null $expire_date     到期表達式：null/"0"=永久；10 位 timestamp=限時；"subscription_{id}"=跟隨訂閱
	 * @param int|null    $granted_by      發放者 user ID：訂單 / 訂閱自動開通為 null，後台手動發放記錄操作者
	 * @return void
	 */
	public static function insert_or_update( int $user_id, int $pass_id, ?int $source_order_id = null, ?string $expire_date = null, ?int $granted_by = null ): void {
		global $wpdb;
		$table = self::table();

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND pass_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$pass_id
			)
		);

		if ( null !== $existing_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				[
					'source_order_id' => $source_order_id,
					'expire_date'     => $expire_date,
					'granted_by'      => $granted_by,
				],
				[ 'id' => (int) $existing_id ],
				[ '%d', '%s', '%d' ],
				[ '%d' ]
			);
			self::flush_gate_cache( $user_id );
			return;
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			[
				'user_id'         => $user_id,
				'pass_id'         => $pass_id,
				'source_order_id' => $source_order_id,
				'expire_date'     => $expire_date,
				'granted_by'      => $granted_by,
				'granted_at'      => \current_time( 'mysql' ),
			],
			[ '%d', '%d', '%d', '%s', '%d', '%s' ]
		);

		self::flush_gate_cache( $user_id );
	}

	/**
	 * 持有關係異動後失效 Gate request 級讀取快取
	 *
	 * 任何寫入（授予 / 收回）後呼叫，確保同 request 後續觀看判定（compute-on-read）取得最新狀態，
	 * 避免 memoize 的「持有列快照」過時（亦修正測試環境 DB 交易回滾後 id 重用導致的快取污染）。
	 *
	 * @param int|null $user_id 指定失效某使用者的持有列快取；null 時清整個 Gate 群組
	 *
	 * @return void
	 */
	private static function flush_gate_cache( ?int $user_id = null ): void {
		if ( \class_exists( Gate::class ) ) {
			Gate::flush_cache( $user_id );
		}
	}

	/**
	 * 查詢指定用戶的所有持有列
	 *
	 * @param int $user_id 學員 user ID
	 * @return array<object> 持有列物件陣列；無紀錄時回傳空陣列
	 */
	public static function find_by_user( int $user_id ): array {
		global $wpdb;
		$table = self::table();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			)
		);

		return self::to_object_rows( $rows );
	}

	/**
	 * 刪除指定權限包的所有持有列
	 *
	 * Rule（erm.dbml）：刪除權限包 → 連帶刪除此表對應 pass_id 的所有列。
	 * 僅 DELETE pass_id 列，絕不碰 avl_course_ids 或 pc_avl_coursemeta（OR 疊加不誤砍其他來源）。
	 *
	 * @param int $pass_id 權限包 post ID
	 * @return int 刪除的列數
	 */
	public static function delete_by_pass( int $pass_id ): int {
		global $wpdb;
		$table = self::table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE pass_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pass_id
			)
		);

		// 收回多名用戶持有列，無法逐一指定 user → 清整個 Gate 群組
		self::flush_gate_cache();

		return (int) $result;
	}

	/**
	 * 刪除單一使用者對單一權限包的持有列（後台手動撤銷用）
	 *
	 * 只 DELETE (user_id, pass_id) 這一列，絕不碰 avl_course_ids 或 pc_avl_coursemeta——
	 * 觀看權是 OR 疊加，撤銷通行證不得誤砍該學員單獨購買 / 逐課綁定的課程。
	 *
	 * @param int $user_id 學員 user ID
	 * @param int $pass_id 權限包 post ID
	 * @return int 刪除的列數（該使用者本來就沒持有時回 0）
	 */
	public static function delete_by_user_pass( int $user_id, int $pass_id ): int {
		global $wpdb;
		$table = self::table();

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE user_id = %d AND pass_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$pass_id
			)
		);

		// 撤銷後必須失效該 user 的 Gate memoize，否則同 request 內仍判定為可觀看
		self::flush_gate_cache( $user_id );

		return (int) $result;
	}

	/**
	 * 查詢指定權限包的持有列（後台持有名單，分頁）
	 *
	 * 依 granted_at 由新到舊排序，同時間則以 id 遞減作為穩定次序。
	 *
	 * @param int $pass_id 權限包 post ID
	 * @param int $limit   每頁筆數（<= 0 時回傳全部）
	 * @param int $offset  位移
	 * @return array<object> 持有列物件陣列；無紀錄時回傳空陣列
	 */
	public static function find_by_pass( int $pass_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$table = self::table();

		if ( $limit <= 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE pass_id = %d ORDER BY granted_at DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$pass_id
				)
			);
			return self::to_object_rows( $rows );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE pass_id = %d ORDER BY granted_at DESC, id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pass_id,
				$limit,
				\max( 0, $offset )
			)
		);

		return self::to_object_rows( $rows );
	}

	/**
	 * 把 $wpdb->get_results() 的結果收斂成物件列陣列
	 *
	 * 理由：get_results() 的回傳型別是 mixed；查詢失敗會回 null，
	 * 故統一在此過濾出物件，讓呼叫端拿到的一定是 array<object>。
	 *
	 * @param mixed $rows get_results() 的原始回傳
	 * @return array<object>
	 */
	private static function to_object_rows( $rows ): array {
		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$objects = [];
		foreach ( $rows as $row ) {
			if ( \is_object( $row ) ) {
				$objects[] = $row;
			}
		}

		return $objects;
	}

	/**
	 * 計算指定權限包的持有列總數（分頁 total 用）
	 *
	 * 與 count_distinct_users_by_pass() 不同：後者算 distinct user 數（刪除影響提示用），
	 * 本方法算列數。upsert 去重下兩者應相等，但語義不同故分開。
	 *
	 * @param int $pass_id 權限包 post ID
	 * @return int 持有列總數
	 */
	public static function count_by_pass( int $pass_id ): int {
		global $wpdb;
		$table = self::table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE pass_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pass_id
			)
		);

		return (int) $count;
	}

	/**
	 * 計算指定權限包的 distinct 持有用戶數
	 *
	 * @param int $pass_id 權限包 post ID
	 * @return int distinct 用戶數；無持有用戶時回傳 0
	 */
	public static function count_distinct_users_by_pass( int $pass_id ): int {
		global $wpdb;
		$table = self::table();

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$table} WHERE pass_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$pass_id
			)
		);

		return (int) $count;
	}
}
