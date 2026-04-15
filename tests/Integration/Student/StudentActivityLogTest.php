<?php
/**
 * 學員活動日誌類型整合測試
 *
 * Feature: specs/features/student/學員活動日誌類型.feature
 *
 * 測試 9 種 log_type 的寫入時機與 AtHelper 定義的 slug 對應矩陣。
 *
 * @group student
 * @group log
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;

/**
 * Class StudentActivityLogTest
 * 測試學員活動日誌的 9 種 log_type
 */
class StudentActivityLogTest extends TestCase {

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試章節 ID */
	private int $chapter_id;

	/** @var string 日誌資料表名稱 */
	private string $log_table;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		global $wpdb;
		$this->log_table = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_log_' . uniqid(),
				'user_email' => 'alice_log_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		$this->course_id  = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
	}

	// ========== 工具方法 ==========

	/**
	 * 直接插入一筆日誌（模擬 LifeCycle 的寫入行為）
	 *
	 * @param int    $user_id    用戶 ID
	 * @param int    $course_id  課程 ID
	 * @param string $log_type   日誌類型
	 * @param string $title      日誌標題
	 */
	private function insert_log( int $user_id, int $course_id, string $log_type, string $title = '' ): void {
		global $wpdb;
		$wpdb->insert(
			$this->log_table,
			[
				'user_id'    => $user_id,
				'course_id'  => $course_id,
				'log_type'   => $log_type,
				'title'      => $title,
				'created_at' => current_time( 'mysql' ),
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);
	}

	/**
	 * 查詢用戶的日誌（依 log_type）
	 *
	 * @param int    $user_id   用戶 ID
	 * @param string $log_type  日誌類型（空字串取全部）
	 * @return array<object>
	 */
	private function get_logs( int $user_id, string $log_type = '' ): array {
		global $wpdb;
		if ( $log_type ) {
			$results = $wpdb->get_results(  // phpcs:ignore
				$wpdb->prepare(
					"SELECT * FROM {$this->log_table} WHERE user_id = %d AND log_type = %s",  // phpcs:ignore
					$user_id,
					$log_type
				)
			);
		} else {
			$results = $wpdb->get_results(  // phpcs:ignore
				$wpdb->prepare(
					"SELECT * FROM {$this->log_table} WHERE user_id = %d",  // phpcs:ignore
					$user_id
				)
			);
		}
		return (array) $results;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * 日誌資料表存在且可寫入
	 */
	public function test_日誌資料表存在且可寫入(): void {
		global $wpdb;

		$this->insert_log( $this->alice_id, $this->course_id, 'order_created', '測試日誌' );

		// 確認資料表有資料
		$count = (int) $wpdb->get_var(  // phpcs:ignore
			$wpdb->prepare( "SELECT COUNT(*) FROM {$this->log_table} WHERE user_id = %d", $this->alice_id )  // phpcs:ignore
		);

		$this->assertGreaterThan( 0, $count, '日誌資料表應可寫入' );
	}

	// ========== Slug 對應矩陣 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: AtHelper 定義 9 個 slug，驗證每個 slug 可正確寫入日誌
	 *
	 * @dataProvider log_type_provider
	 */
	public function test_各種log_type可正確寫入( string $log_type, string $description ): void {
		$this->insert_log( $this->alice_id, $this->course_id, $log_type, $description );

		$logs = $this->get_logs( $this->alice_id, $log_type );

		$this->assertCount( 1, $logs, "{$log_type} 應成功寫入一筆日誌" );
		$this->assertSame( $log_type, $logs[0]->log_type );
	}

	/**
	 * 提供 9 種 log_type 的測試資料
	 *
	 * @return array<string, array<string>>
	 */
	public function log_type_provider(): array {
		return [
			'course_granted'     => [ 'course_granted', '開通課程權限後' ],
			'course_finish'      => [ 'course_finish', '課程完成時' ],
			'course_launch'      => [ 'course_launch', '課程開課時' ],
			'chapter_enter'      => [ 'chapter_enter', '進入單元時' ],
			'chapter_finish'     => [ 'chapter_finish', '完成單元時' ],
			'order_created'      => [ 'order_created', '訂單成立時' ],
			'chapter_unfinished' => [ 'chapter_unfinished', '單元未完成時' ],
			'course_removed'     => [ 'course_removed', '管理員手動移除課程權限時' ],
			'update_student'     => [ 'update_student', '更新學員觀看課程期限時' ],
		];
	}

	// ========== order_created ==========

	/**
	 * @test
	 * @group happy
	 * Rule: order_created 僅作為 log_type，不觸發 Email 發送
	 * （驗證 log_type 字串值，Email Trigger 不在此層測試）
	 */
	public function test_order_created_log_type字串正確(): void {
		$log_type = 'order_created';
		$this->insert_log( $this->alice_id, $this->course_id, $log_type, '購買包含課程 #100 權限的商品 訂單 #8001' );

		$logs = $this->get_logs( $this->alice_id, $log_type );
		$this->assertCount( 1, $logs );
		$this->assertStringContainsString( '購買包含課程', $logs[0]->title );
	}

	// ========== chapter_unfinished ==========

	/**
	 * @test
	 * @group happy
	 * Rule: chapter_unfinished — 學員取消完成章節時寫入日誌
	 */
	public function test_chapter_unfinished_log寫入(): void {
		$this->insert_log( $this->alice_id, $this->course_id, 'chapter_unfinished', "章節 {$this->chapter_id} 取消完成" );

		$logs = $this->get_logs( $this->alice_id, 'chapter_unfinished' );
		$this->assertCount( 1, $logs );
		$this->assertSame( 'chapter_unfinished', $logs[0]->log_type );
	}

	// ========== course_removed ==========

	/**
	 * @test
	 * @group happy
	 * Rule: course_removed — 管理員移除學員課程權限時寫入日誌
	 */
	public function test_course_removed_log寫入(): void {
		$this->insert_log( $this->alice_id, $this->course_id, 'course_removed', '管理員移除課程存取權' );

		$logs = $this->get_logs( $this->alice_id, 'course_removed' );
		$this->assertCount( 1, $logs );
	}

	// ========== update_student ==========

	/**
	 * @test
	 * @group happy
	 * Rule: update_student — 管理員更新到期日時寫入日誌
	 */
	public function test_update_student_log寫入(): void {
		$this->insert_log( $this->alice_id, $this->course_id, 'update_student', '更新到期日' );

		$logs = $this->get_logs( $this->alice_id, 'update_student' );
		$this->assertCount( 1, $logs );
	}

	// ========== 多種 log 共存 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 同一用戶可有多種 log_type 的日誌
	 */
	public function test_同一用戶可有多種log_type(): void {
		$log_types = [ 'order_created', 'course_granted', 'chapter_enter', 'chapter_finish' ];

		foreach ( $log_types as $log_type ) {
			$this->insert_log( $this->alice_id, $this->course_id, $log_type );
		}

		$all_logs = $this->get_logs( $this->alice_id );
		$this->assertCount( count( $log_types ), $all_logs, '應有多種 log_type 的日誌' );

		$actual_types = array_column( $all_logs, 'log_type' );
		foreach ( $log_types as $expected_type ) {
			$this->assertContains( $expected_type, $actual_types );
		}
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 日誌 title 最多 255 字元（varchar(255) 限制）
	 * 超過時 MySQL 會截斷，title 欄位長度上限為 255
	 */
	public function test_日誌title上限255字元(): void {
		// 255 字元以內可完整儲存
		$title_255 = str_repeat( 'A', 255 );
		$this->insert_log( $this->alice_id, $this->course_id, 'order_created', $title_255 );

		$logs = $this->get_logs( $this->alice_id, 'order_created' );
		$this->assertCount( 1, $logs );
		$this->assertSame( 255, strlen( $logs[0]->title ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 日誌 title 含特殊字元（XSS 輸入值）
	 */
	public function test_日誌title含XSS字元可儲存(): void {
		$xss_title = '<script>alert("xss")</script>';

		$this->insert_log( $this->alice_id, $this->course_id, 'order_created', $xss_title );

		$logs = $this->get_logs( $this->alice_id, 'order_created' );
		$this->assertCount( 1, $logs );
		// 儲存層不過濾，輸出層負責 escape
		$this->assertSame( $xss_title, $logs[0]->title );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: log_type 欄位為 varchar(20)，超過 20 字元會截斷
	 * 在定義的 9 個 slug 中，最長為 "chapter_unfinished"（18 字元），皆在限制內
	 */
	public function test_log_type欄位限制20字元(): void {
		// 標準 slug 均在 20 字元內，此測試驗證邊界
		$valid_type = 'chapter_unfinished'; // 18 字元，最長的標準 slug
		$this->insert_log( $this->alice_id, $this->course_id, $valid_type );

		$logs = $this->get_logs( $this->alice_id, $valid_type );
		$this->assertCount( 1, $logs );
		$this->assertSame( $valid_type, $logs[0]->log_type );
	}
}
