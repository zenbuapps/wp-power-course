<?php
/**
 * MCP ActivityLogger — 寫入與查詢 MCP 活動日誌
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Api\Mcp;

/**
 * Class ActivityLogger
 * 負責寫入 wp_pc_mcp_activity 活動日誌，以及提供查詢與清理功能
 */
final class ActivityLogger {

	/**
	 * 記錄一筆 MCP tool 呼叫活動
	 *
	 * @param string       $tool_name tool 名稱
	 * @param int          $user_id   操作用戶 ID
	 * @param array<mixed> $args      呼叫參數（自動 JSON 編碼）
	 * @param mixed        $result    執行結果（轉為摘要字串）
	 * @param bool         $success   是否成功
	 * @param int|null     $token_id  使用的 token ID（可為 null）
	 * @param int|null     $duration_ms 執行時間（毫秒）
	 * @return void
	 */
	public function log(
		string $tool_name,
		int $user_id,
		array $args,
		mixed $result,
		bool $success,
		?int $token_id = null,
		?int $duration_ms = null
	): void {
		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;

		// 將結果轉為摘要字串（最多 500 字元）
		$result_summary = is_string( $result )
		? $result
		: wp_json_encode( $result );

		if ( is_string( $result_summary ) && strlen( $result_summary ) > 500 ) {
			$result_summary = substr( $result_summary, 0, 497 ) . '...';
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[
				'tool_name'       => sanitize_key( $tool_name ),
				'user_id'         => $user_id,
				'token_id'        => $token_id,
				'request_payload' => wp_json_encode( $args ),
				'response_summary' => $result_summary,
				'success'         => $success ? 1 : 0,
				'duration_ms'     => $duration_ms,
				'created_at'      => current_time( 'mysql', true ),
			],
			[ '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%s' ]
		);
	}

	/**
	 * 取得最近 N 筆活動記錄
	 *
	 * @param int $limit 最多回傳筆數（預設 100）
	 * @return array<int, \stdClass> 活動記錄列表
	 */
	public function get_recent_logs( int $limit = 100 ): array {
		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$limit = max( 1, $limit );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		/** @var array<\stdClass>|null $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			)
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * 清理超過 N 天的舊活動記錄
	 * 由 wp_cron 每日呼叫
	 *
	 * @param int $days 保留天數（預設 30）
	 * @return int 刪除筆數
	 */
	public function prune_old_logs( int $days = 30 ): int {
		global $wpdb;
		$table    = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$days      = max( 1, $days );
		$timestamp = strtotime( "-{$days} days" );
		$cutoff    = gmdate( 'Y-m-d H:i:s', false !== $timestamp ? $timestamp : 0 );

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				$cutoff
			)
		);

		return is_int( $deleted ) ? $deleted : 0;
	}
}
