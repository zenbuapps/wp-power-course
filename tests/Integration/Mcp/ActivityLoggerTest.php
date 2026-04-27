<?php
/**
 * MCP ActivityLogger 整合測試
 *
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Mcp;

use J7\PowerCourse\Api\Mcp\ActivityLogger;
use J7\PowerCourse\Api\Mcp\Migration;

/**
 * Class ActivityLoggerTest
 * 驗證 MCP 活動日誌寫入與清理邏輯
 */
class ActivityLoggerTest extends IntegrationTestCase {

	/** @var int 測試用用戶 ID */
	private int $user_id;

	/**
	 * 設定
	 */
	public function set_up(): void {
		parent::set_up();
		$this->user_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
	}

	/**
	 * 測試：log() 寫入活動記錄
	 *
	 * @group happy
	 */
	public function test_log_writes_activity_record(): void {
		$logger = new ActivityLogger();
		$logger->log( 'course_list', $this->user_id, [ 'per_page' => 10 ], [ 'count' => 5 ], true );

		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND tool_name = %s", $this->user_id, 'course_list' )
		);
		$this->assertSame( '1', (string) $count, '應有 1 筆活動記錄' );
	}

	/**
	 * 測試：log() 儲存 request_payload 為 JSON
	 *
	 * @group happy
	 */
	public function test_log_stores_payload_as_json(): void {
		$logger  = new ActivityLogger();
		$payload = [ 'course_id' => 42, 'page' => 1 ];
		$logger->log( 'course_get', $this->user_id, $payload, 'OK', true );

		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT request_payload FROM {$table} WHERE user_id = %d AND tool_name = %s ORDER BY id DESC LIMIT 1", $this->user_id, 'course_get' )
		);
		$stored = $row ? json_decode( $row->request_payload, true ) : [];
		$this->assertSame( $payload, $stored, '儲存的 payload 應與傳入一致' );
	}

	/**
	 * 測試：log() success = false 時正確儲存
	 *
	 * @group error
	 */
	public function test_log_stores_failure_record(): void {
		$logger = new ActivityLogger();
		$logger->log( 'chapter_create', $this->user_id, [], 'Permission denied', false );

		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT success FROM {$table} WHERE user_id = %d AND tool_name = %s ORDER BY id DESC LIMIT 1", $this->user_id, 'chapter_create' )
		);
		$this->assertSame( '0', (string) $row->success, '失敗記錄 success 欄位應為 0' );
	}

	/**
	 * 測試：get_recent_logs() 回傳最近 N 筆記錄
	 *
	 * @group happy
	 */
	public function test_get_recent_logs_returns_limited_records(): void {
		$logger = new ActivityLogger();
		for ( $i = 0; $i < 5; $i++ ) {
			$logger->log( "tool_{$i}", $this->user_id, [], "result_{$i}", true );
		}

		$logs = $logger->get_recent_logs( 3 );
		$this->assertCount( 3, $logs, 'get_recent_logs(3) 應回傳 3 筆' );
	}

	/**
	 * 測試：prune_old_logs() 刪除超過 N 天的記錄
	 *
	 * @group happy
	 */
	public function test_prune_old_logs_removes_stale_records(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migration::ACTIVITY_TABLE_NAME;

		// 直接插入 35 天前的舊記錄
		$old_date = gmdate( 'Y-m-d H:i:s', strtotime( '-35 days' ) );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[
				'tool_name'    => 'old_tool',
				'user_id'      => $this->user_id,
				'success'      => 1,
				'created_at'   => $old_date,
			],
			[ '%s', '%d', '%d', '%s' ]
		);

		// 插入新記錄
		$logger = new ActivityLogger();
		$logger->log( 'new_tool', $this->user_id, [], 'OK', true );

		// 清理 30 天前的記錄
		$deleted = $logger->prune_old_logs( 30 );
		$this->assertGreaterThanOrEqual( 1, $deleted, '應刪除至少 1 筆舊記錄' );

		// 驗證舊記錄已消失
		$old_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool_name = %s", 'old_tool' )
		);
		$this->assertSame( '0', (string) $old_count, '舊記錄應被清除' );

		// 驗證新記錄仍存在
		$new_count = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE tool_name = %s", 'new_tool' )
		);
		$this->assertSame( '1', (string) $new_count, '新記錄應保留' );
	}

	/**
	 * 測試：get_recent_logs() 預設 100 筆
	 *
	 * @group smoke
	 */
	public function test_get_recent_logs_default_limit(): void {
		$logger = new ActivityLogger();
		// 只插入 3 筆，回傳應 <= 3
		for ( $i = 0; $i < 3; $i++ ) {
			$logger->log( "tool_{$i}", $this->user_id, [], 'OK', true );
		}
		$logs = $logger->get_recent_logs();
		$this->assertLessThanOrEqual( 100, count( $logs ), '預設不超過 100 筆' );
		$this->assertGreaterThanOrEqual( 3, count( $logs ), '應包含剛插入的 3 筆' );
	}
}
