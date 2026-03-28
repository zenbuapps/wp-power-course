<?php

declare ( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\EmailRecord;

use J7\PowerCourse\Plugin;

/** 對 pc_email_records table 的 CRUD 抽象 */
abstract class CRUD {

	/** @var string 對應的 table name */
	public static string $table_name = Plugin::EMAIL_RECORDS_TABLE_NAME;

	/**
	 * 取得紀錄
	 *
	 * @param array{id?:string, post_id?:string, user_id?:string, email_id?:string, email_subject?:string, trigger_at?:string, mark_as_sent?:string, email_date?:string, identifier?:string} $where 要查詢的條件
	 * @return array<int, object{id: int, post_id: int, user_id: int, email_id: int, email_subject: string, trigger_at: string, mark_as_sent: int, email_date: string, identifier: string}>
	 */
	public static function get( array $where ) {
		global $wpdb;
		$table_name = $wpdb->prefix . static::$table_name;

		$where_arr = [];
		foreach ($where as $key => $value) {
			$where_arr[] = "{$key} = '{$value}'";
		}

		$where = implode(' AND ', $where_arr);
		return $wpdb->get_results("SELECT * FROM $table_name WHERE $where"); // phpcs:ignore
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
