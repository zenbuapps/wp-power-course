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
	 * 數值型欄位（其餘一律以 %s 處理）
	 *
	 * @var array<string>
	 */
	private static array $numeric_columns = [
		'id',
		'post_id',
		'user_id',
		'email_id',
		'mark_as_sent',
	];

	/**
	 * 依欄位名產生 $wpdb 的 format 陣列
	 *
	 * $wpdb->update() 的 format 陣列是**逐欄位對位**的：給的個數不足時，
	 * WP 會把第一個 format 套用到剩下所有欄位（見 wpdb::process_field_formats）。
	 * 舊版寫死 `[ '%d' ]` 而 where 有三個欄位，於是
	 * `trigger_at = 'course_granted'` 被當成 %d → 轉成 0 →
	 * SQL 變成 `trigger_at = 0`，在 MySQL 非嚴格模式下**任何非數字字串都等於 0**，
	 * 所以「只想清 course_granted」實際上把 chapter_finish 等其他類型一起清掉。
	 *
	 * @param array<string, mixed> $fields 欄位 => 值
	 * @return array<string> format 陣列
	 */
	private static function build_formats( array $fields ): array {
		$formats = [];
		foreach ( \array_keys( $fields ) as $key ) {
			$formats[] = \in_array( $key, self::$numeric_columns, true ) ? '%d' : '%s';
		}
		return $formats;
	}

	/**
	 * 濾掉不在白名單的欄位
	 *
	 * @param array<string, mixed> $fields 欄位 => 值
	 * @return array<string, mixed>|null 全部合法時回傳原陣列，出現未知欄位回傳 null
	 */
	private static function filter_columns( array $fields ): ?array {
		foreach ( \array_keys( $fields ) as $key ) {
			if ( ! \in_array( $key, self::$allowed_columns, true ) ) {
				// fail-close：不靜默丟棄。丟掉一個條件會讓 WHERE 變寬，
				// 而本表的主要用途是 is_sent() 的「已寄過就別再寄」閘門 ——
				// 條件變寬 = 更容易誤判成已寄 = 該寄的信不寄，且完全無跡可循。
				if ( \class_exists( \J7\WpUtils\Classes\WC::class ) ) {
					\J7\WpUtils\Classes\WC::log(
						[
							'unknown_column'   => $key,
							'allowed_columns'  => self::$allowed_columns,
						],
						'EmailRecord\CRUD 收到不在白名單的欄位，查詢已中止'
					);
				}
				return null;
			}
		}
		return $fields;
	}

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

		// 欄位名不能用 prepare 的 placeholder，只能走白名單。
		// 舊版是 "{$key} = '{$value}'" 直接內插欄位名**與**值再 // phpcs:ignore ——
		// 呼叫端今天剛好只傳內部值，但這是「下一個人多傳一個使用者輸入就中招」的形狀。
		$where = self::filter_columns( $where );
		if ( null === $where ) {
			return [];
		}

		$where_arr = [];
		$values    = [];
		foreach ( $where as $key => $value ) {
			$where_arr[] = "`{$key}` = %s";
			$values[]    = (string) $value;
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

		$where = self::filter_columns( $where );
		$data  = self::filter_columns( $data );
		if ( null === $where || null === $data || ! $where || ! $data ) {
			return false;
		}

		return $wpdb->update(
				$table_name,
				$data,
				$where,
				self::build_formats( $data ),
				self::build_formats( $where )
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
