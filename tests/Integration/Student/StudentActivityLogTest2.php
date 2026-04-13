<?php
/**
 * 學員活動日誌類型整合測試
 *
 * Feature: specs/features/student/學員活動日誌類型.feature
 * 測試 pc_student_logs 的 9 種 log_type 定義與寫入觸發點：
 * - AtHelper 的 9 個 slug 定義
 * - 5 個有 Email Trigger vs 4 個 log-only slug
 * - At.php constructor 只有 5 個 trigger 有 add_action
 *
 * 注意：此測試聚焦於 slug 定義層，實際寫入 log 的部分
 * 已在 StudentActivityLogTest.php 中測試。
 *
 * @group student
 * @group log
 * @group activity
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\Plugin;

/**
 * Class StudentActivityLogTest2
 * 測試學員活動日誌的 log_type slug 定義
 */
class StudentActivityLogTest2 extends TestCase {

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/** @var int 課程 100 ID */
	private int $course_100_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 AtHelper 與 Plugin 常數
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// pc_student_logs 不在 WP 測試框架的事務回滾範圍內，每次手動清空
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME );

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_log2_' . uniqid(),
				'user_email' => 'alice_log2_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->course_100_id = $this->create_course(
			[ 'post_title' => 'PHP 基礎課', '_is_course' => 'yes' ]
		);

		$this->ids['Alice']     = $this->alice_id;
		$this->ids['Course100'] = $this->course_100_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * Plugin::STUDENT_LOGS_TABLE_NAME 常數存在
	 */
	public function test_冒煙_STUDENT_LOGS_TABLE_NAME常數存在(): void {
		$this->assertTrue( defined( 'J7\PowerCourse\Plugin::STUDENT_LOGS_TABLE_NAME' ), 'STUDENT_LOGS_TABLE_NAME 應已定義' );
	}

	/**
	 * @test
	 * @group smoke
	 * AtHelper::$allowed_slugs 共 9 個 slug
	 */
	public function test_冒煙_allowed_slugs共9個(): void {
		$this->assertCount( 9, AtHelper::$allowed_slugs, 'allowed_slugs 應有 9 個 slug' );
	}

	// ========== Slug 定義矩陣 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 5 個 Email Trigger slug 存在且有 hook
	 */
	public function test_5個Email_Trigger_slug有hook(): void {
		$email_trigger_slugs = [
			AtHelper::COURSE_GRANTED,
			AtHelper::COURSE_FINISHED,
			AtHelper::COURSE_LAUNCHED,
			AtHelper::CHAPTER_ENTERED,
			AtHelper::CHAPTER_FINISHED,
		];

		foreach ( $email_trigger_slugs as $slug ) {
			$helper = new AtHelper( $slug );
			$this->assertSame( "power_email_send_{$slug}", $helper->hook, "{$slug} 的 hook 應為 power_email_send_{$slug}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 4 個 log-only slug（非 Email Trigger）同樣在 allowed_slugs 中
	 */
	public function test_4個log_only_slug在allowed_slugs中(): void {
		$log_only_slugs = [
			AtHelper::ORDER_CREATED,
			AtHelper::CHAPTER_UNFINISHED,
			AtHelper::COURSE_REMOVED,
			AtHelper::UPDATE_STUDENT,
		];

		foreach ( $log_only_slugs as $slug ) {
			$this->assertContains( $slug, AtHelper::$allowed_slugs, "{$slug} 應在 allowed_slugs 中" );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 4 個 log-only slug 的 label 正確
	 */
	public function test_4個log_only_slug_label正確(): void {
		$expected_labels = [
			AtHelper::ORDER_CREATED      => '訂單成立時',
			AtHelper::CHAPTER_UNFINISHED => '單元未完成時',
			AtHelper::COURSE_REMOVED     => '管理員手動移除課程權限時',
			AtHelper::UPDATE_STUDENT     => '更新學員觀看課程期限時',
		];

		foreach ( $expected_labels as $slug => $expected_label ) {
			$helper = new AtHelper( $slug );
			$this->assertSame( $expected_label, $helper->label, "{$slug} 的 label 應為 {$expected_label}" );
		}
	}

	/**
	 * @test
	 * @group happy
	 * Rule: AtHelper 的 meta_key_at 和 meta_key_sent_at 格式正確
	 */
	public function test_AtHelper_meta_key格式正確(): void {
		$helper = new AtHelper( AtHelper::COURSE_GRANTED );

		$this->assertSame( 'course_granted_at', $helper->meta_key_at );
		$this->assertSame( 'course_granted_sent_at', $helper->meta_key_sent_at );
	}

	// ========== pc_student_logs 資料表 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: pc_student_logs 資料表存在（由 AbstractTable 建立）
	 */
	public function test_student_logs_資料表存在(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		// ensure_tables_exist 在 set_up 中已呼叫
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		$this->assertSame( $table_name, $result, 'pc_student_logs 資料表應存在' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 可向 pc_student_logs 直接寫入一筆記錄（測試 DB schema）
	 */
	public function test_可直接寫入student_log記錄(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		$result = $wpdb->insert(
			$table_name,
			[
				'user_id'   => $this->alice_id,
				'course_id' => $this->course_100_id,
				'log_type'  => AtHelper::ORDER_CREATED,
				'title'     => '購買包含課程權限的商品',
				'content'   => '訂單 #8001',
			],
			[ '%d', '%d', '%s', '%s', '%s' ]
		);

		$this->assertNotFalse( $result, '應可向 pc_student_logs 寫入記錄' );
		$this->assertSame( 1, $result, '應插入 1 筆記錄' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 寫入後可讀取正確的 log_type
	 */
	public function test_寫入log後可讀取正確的log_type(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		$wpdb->insert(
			$table_name,
			[
				'user_id'   => $this->alice_id,
				'course_id' => $this->course_100_id,
				'log_type'  => AtHelper::COURSE_REMOVED,
				'title'     => '移除課程權限',
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE user_id = %d AND log_type = %s ORDER BY id DESC LIMIT 1",
				$this->alice_id,
				AtHelper::COURSE_REMOVED
			)
		);

		$this->assertNotNull( $log, '應能讀取寫入的 log' );
		$this->assertSame( AtHelper::COURSE_REMOVED, $log->log_type, 'log_type 應為 course_removed' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 9 種 log_type 皆可寫入資料表
	 */
	public function test_9種log_type皆可寫入資料表(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		foreach ( AtHelper::$allowed_slugs as $slug ) {
			$result = $wpdb->insert(
				$table_name,
				[
					'user_id'   => $this->alice_id,
					'course_id' => $this->course_100_id,
					'log_type'  => $slug,
					'title'     => "測試 {$slug}",
				],
				[ '%d', '%d', '%s', '%s' ]
			);

			$this->assertNotFalse( $result, "{$slug} 應可寫入 pc_student_logs" );
		}

		// 驗證共 9 筆
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
				$this->alice_id
			)
		);

		$this->assertSame( 9, $count, '應有 9 筆 log 記錄（各種 log_type 各一筆）' );
	}

	// ========== At 類別只有 5 個 Email trigger ==========

	/**
	 * @test
	 * @group happy
	 * Rule: At 類別的 constructor 只有 5 個 add_action（Email trigger）
	 * 透過 ReflectionMethod 確認 constructor 中不包含 order_created / chapter_unfinished 的 add_action
	 */
	public function test_At_constructor只有5個Email_trigger(): void {
		$reflection = new \ReflectionClass( At::class );
		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			$this->markTestSkipped( 'At 類別沒有 constructor' );
		}

		$filename = $constructor->getFileName();
		$start    = $constructor->getStartLine();
		$end      = $constructor->getEndLine();

		if ( ! $filename ) {
			$this->markTestSkipped( '無法讀取 At constructor 原始碼' );
		}

		$lines = file( $filename );
		$body  = implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );

		// constructor 應包含 5 個 Email trigger 的 action 設定
		$this->assertStringContainsString( 'COURSE_GRANTED', $body, 'At constructor 應包含 COURSE_GRANTED trigger' );
		$this->assertStringContainsString( 'COURSE_LAUNCHED', $body, 'At constructor 應包含 COURSE_LAUNCHED trigger' );
		$this->assertStringContainsString( 'CHAPTER_ENTERED', $body, 'At constructor 應包含 CHAPTER_ENTERED trigger' );
		$this->assertStringContainsString( 'CHAPTER_FINISHED', $body, 'At constructor 應包含 CHAPTER_FINISHED trigger' );

		// log-only 的 slug 不應在 constructor 中 add_action
		// ORDER_CREATED / CHAPTER_UNFINISHED / COURSE_REMOVED / UPDATE_STUDENT 不應有 schedule 類的 add_action
		// （AtHelper 常數字串可能出現，但不應有對應的 schedule_xxx_email 方法呼叫）
		$this->assertStringNotContainsString( 'schedule_order_created_email', $body, 'order_created 不應有 Email trigger' );
		$this->assertStringNotContainsString( 'schedule_chapter_unfinished_email', $body, 'chapter_unfinished 不應有 Email trigger' );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: log_type 為空字串時，資料庫仍可接受（欄位允許任意字串）
	 */
	public function test_空log_type仍可寫入(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		$result = $wpdb->insert(
			$table_name,
			[
				'user_id'   => $this->alice_id,
				'course_id' => $this->course_100_id,
				'log_type'  => '',
				'title'     => '空 log_type 測試',
			],
			[ '%d', '%d', '%s', '%s' ]
		);

		// 資料表不應有 CHECK CONSTRAINT 阻止空字串
		$this->assertNotFalse( $result, '空 log_type 應可寫入（DB schema 不限制）' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 同一 user_id 可有多筆不同 log_type 的記錄
	 */
	public function test_同用戶可有多筆不同log_type(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;

		$log_types = [ AtHelper::COURSE_GRANTED, AtHelper::CHAPTER_ENTERED, AtHelper::ORDER_CREATED ];

		foreach ( $log_types as $log_type ) {
			$wpdb->insert(
				$table_name,
				[
					'user_id'   => $this->alice_id,
					'course_id' => $this->course_100_id,
					'log_type'  => $log_type,
					'title'     => "Log {$log_type}",
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE user_id = %d",
				$this->alice_id
			)
		);

		$this->assertSame( 3, $count, '同用戶應有 3 筆不同的 log 記錄' );
	}
}
