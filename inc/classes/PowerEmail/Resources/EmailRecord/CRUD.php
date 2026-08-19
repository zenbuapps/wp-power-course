<?php

declare ( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\EmailRecord;

use J7\PowerCourse\Plugin;

/** 對 pc_email_records table 的 CRUD 抽象 */
abstract class CRUD {

	/** @var string 對應的 table name */
	public static string $table_name = Plugin::EMAIL_RECORDS_TABLE_NAME;

	/**
	 * 允許出現在 WHERE 子句的欄位白名單
	 *
	 * 欄位名無法用 $wpdb->prepare() 的 placeholder 保護，只能靠白名單。
	 * 對照 pc_email_records 的 schema（見 Plugin 的 DDL）。
	 *
	 * @var array<string>
	 */
	public static array $allowed_columns = [
		'id',
		'post_id',
		'user_id',
		'email_id',
		'email_subject',
		'trigger_at',
		'mark_as_sent',
		'email_date',
		'identifier',
	];

	/**
	 * 取得紀錄
	 *
	 * @param array{id?:string, post_id?:string, user_id?:string, email_id?:string, email_subject?:string, trigger_at?:string, mark_as_sent?:string, email_date?:string, identifier?:string} $where 要查詢的條件
	 * @return array<int, object{id: int, post_id: int, user_id: int, email_id: int, email_subject: string, trigger_at: string, mark_as_sent: int, email_date: string, identifier: string}>
	 */
	public static function get( array $where ) {
		global $wpdb;
		$table_name = $wpdb->prefix . static::$table_name;

		if ( ! $where ) {
			return [];
		}

		$where_arr = [];
		$values    = [];
		foreach ( $where as $key => $value ) {
			// 欄位名不能用 prepare 的 placeholder，只能走白名單。
			// 舊版是 "{$key} = '{$value}'" 直接內插欄位名**與**值再 // phpcs:ignore ——
			// 呼叫端今天剛好只傳內部值，但這是「下一個人多傳一個使用者輸入就中招」的形狀。
			if ( ! \in_array( $key, self::$allowed_columns, true ) ) {
				continue;
			}
			$where_arr[] = "`{$key}` = %s";
			$values[]    = (string) $value;
		}

		if ( ! $where_arr ) {
			return [];
		}

		$sql = "SELECT * FROM `{$table_name}` WHERE " . implode( ' AND ', $where_arr );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql 只含白名單欄位名與 %s placeholder，值全走 prepare
		return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
	}


	/**
	 * 新增一筆寄信紀錄到資料表中
	 *
	 * @param int    $post_id 課程/章節 ID
	 * @param int    $user_id 使用者 ID
	 * @param int    $email_id 信件 ID
	 * @param string $email_subject 信件主題
	 * @param string $trigger_at 觸發時間
	 * @param string $identifier 信件標識符，用來判斷唯一質是否寄過信
	 * @param bool   $unique 是否單一紀錄
	 * @return int|false 成功時回傳新增的紀錄 ID，失敗時回傳 false
	 */
	public static function add( int $post_id, int $user_id, int $email_id, string $email_subject = '', string $trigger_at = '', string $identifier = '', bool $unique = true ): int|false {
		global $wpdb;
		$table_name = $wpdb->prefix . static::$table_name;

		$where = [
			'post_id'  => $post_id,
			'user_id'  => $user_id,
			'email_id' => $email_id,
		];

		$data = [
			'email_subject' => $email_subject,
			'trigger_at'    => $trigger_at,
			'email_date'    => \wp_date('Y-m-d H:i:s'),
			'mark_as_sent'  => 1,
			'identifier'    => $identifier,
		];

		if ($unique) {
			// 檢查紀錄是否存在
			$record = self::get(
				// @phpstan-ignore-next-line
				[
					'post_id'    => $post_id,
					'user_id'    => $user_id,
					'email_id'   => $email_id,
					'trigger_at' => $trigger_at,
					'identifier' => $identifier,
				]
				);
			if ($record) {
				return self::update(
					$where,
					$data
					);
			}
		}

		return $wpdb->insert(
				$table_name,
				array_merge($where, $data),
				[ '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s' ]
			);
	}

	/**
	 * 更新 record
	 *
	 * @param array{id?:string|int, post_id?:string|int, user_id?:string|int, email_id?:string|int, email_subject?:string, trigger_at?:string, mark_as_sent?:string|int, email_date?:string, identifier?:string} $where 要更新的資料
	 * @param array<string, mixed>                                                                                                                                                                               $data 要更新的資料
	 *
	 * @return int|false 成功時回傳更新的數量，失敗時回傳 false
	 */
	public static function update( array $where, array $data ): int|false {

		global $wpdb;

		$table_name = $wpdb->prefix . static::$table_name;

		return $wpdb->update(
				$table_name,
				$data,
				$where,
				null,
				[ // where format
					'%d',
				]
			);
	}


	/**
	 * 刪除紀錄
	 *
	 * @param int $id 紀錄 ID
	 * @return int|false 移除的數量, or false on error.
	 */
	public static function delete( int $id ): int|false {
		global $wpdb;
		$table_name = $wpdb->prefix . static::$table_name;
		return $wpdb->delete(
		$table_name,
		[
			'id' => $id,
		],
		[ '%d' ]
		);
	}
}
