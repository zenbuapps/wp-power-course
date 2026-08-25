<?php
/**
 * 通行證到期預警掃描 整合測試
 *
 * Issue #252 補完：到期前完全沒有任何預警。
 * ExpiringNotifier 每日掃描 pc_user_access_pass，對「N 天內即將到期」的持有列
 * 觸發 power_course_access_pass_expiring，由 PowerEmail 排信。
 *
 * 關鍵不變式：
 *   1. 只掃 fixed / assigned——unlimited 沒有到期日，follow_subscription 由訂閱系統負責。
 *   2. 已過期的不再提醒（提醒的是「快到期」，不是「已經沒了」）。
 *   3. 通行證 / 使用者已刪除時靜默跳過，不丟錯、不寄信。
 *
 * @group access-pass
 * @group expiring-notifier
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\ExpiringNotifier;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Holdings;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;

/**
 * Class ExpiringNotifierTest
 */
class ExpiringNotifierTest extends TestCase {

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var array<int, array{0:int,1:int,2:int}> 掃描期間捕捉到的 action 參數 */
	private array $captured = [];

	/**
	 * 初始化依賴（ExpiringNotifier::run 為靜態方法）
	 */
	protected function configure_dependencies(): void {
	}

	/** 掛上捕捉用的 action listener */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->captured = [];

		\add_action(
			ExpiringNotifier::EXPIRING_ACTION,
			[ $this, 'capture_expiring' ],
			10,
			3
		);
	}

	/** 卸載 listener 並清理持有表 */
	public function tear_down(): void {
		\remove_action( ExpiringNotifier::EXPIRING_ACTION, [ $this, 'capture_expiring' ], 10 );

		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Gate::flush_cache();

		parent::tear_down();
	}

	/**
	 * 捕捉 power_course_access_pass_expiring 的觸發
	 *
	 * @param int $user_id          學員 ID
	 * @param int $pass_id          通行證 ID
	 * @param int $expire_timestamp 到期 timestamp
	 *
	 * @return void
	 */
	public function capture_expiring( int $user_id, int $pass_id, int $expire_timestamp ): void {
		$this->captured[] = [ $user_id, $pass_id, $expire_timestamp ];
	}

	/**
	 * 建立通行證 CPT
	 *
	 * @param string $limit_type 期限模式
	 * @return int pass_id
	 */
	private function create_access_pass( string $limit_type = 'fixed' ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '到期預警測試通行證',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', 'all' );
		\update_post_meta( $pass_id, 'limit_type', $limit_type );
		\update_post_meta( $pass_id, 'access_pass_status', 'active' );

		return $pass_id;
	}

	/**
	 * 取出捕捉到的 pass_id 清單
	 *
	 * @return array<int>
	 */
	private function captured_pass_ids(): array {
		return \array_map( static fn( array $args ): int => $args[1], $this->captured );
	}

	// ========== Smoke ==========

	/**
	 * @test
	 * @group smoke
	 * Rule: ExpiringNotifier 的常數與方法存在
	 */
	public function test_notifier_常數與方法存在(): void {
		$this->assertSame( 'pc_access_pass_expiring_scan', ExpiringNotifier::SCAN_HOOK );
		$this->assertSame( 'power_course_access_pass_expiring', ExpiringNotifier::EXPIRING_ACTION );
		$this->assertTrue( \method_exists( ExpiringNotifier::class, 'run' ) );
		$this->assertTrue( \method_exists( ExpiringNotifier::class, 'maybe_schedule_scan' ) );
	}

	// ========== 命中條件 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 且落在門檻內 → 觸發預警，帶出正確的 user / pass / 到期時間
	 */
	public function test_fixed_門檻內_觸發預警(): void {
		$expire  = \time() + ( 3 * DAY_IN_SECONDS );
		$pass_id = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		ExpiringNotifier::run();

		$this->assertCount( 1, $this->captured, '門檻內的持有列應觸發一次' );
		$this->assertSame( [ $this->alice_id, $pass_id, $expire ], $this->captured[0] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: assigned（指定日期到期）同樣需要預警
	 */
	public function test_assigned_門檻內_觸發預警(): void {
		$expire  = \time() + ( 2 * DAY_IN_SECONDS );
		$pass_id = $this->create_access_pass( 'assigned' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		ExpiringNotifier::run();

		$this->assertSame( [ $pass_id ], $this->captured_pass_ids() );
	}

	// ========== 不命中條件 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 還很久才到期（超出門檻）→ 不提醒
	 */
	public function test_遠期到期_不觸發(): void {
		$threshold = Holdings::get_expiring_threshold_days();
		$expire    = \time() + ( ( $threshold + 30 ) * DAY_IN_SECONDS );
		$pass_id   = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured, '超出門檻不應提醒' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 已經過期的不再提醒——提醒的是「快到期」，不是「已經沒了」
	 */
	public function test_已過期_不觸發(): void {
		$pass_id = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) ( \time() - DAY_IN_SECONDS ) );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: unlimited（expire_date='0'）沒有到期日，不入列
	 */
	public function test_unlimited_不觸發(): void {
		$pass_id = $this->create_access_pass( 'unlimited' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: follow_subscription 的到期由訂閱系統負責，不重複造輪子
	 */
	public function test_follow_subscription_不觸發(): void {
		$pass_id = $this->create_access_pass( 'follow_subscription' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, 'subscription_123' );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: limit_type 與 expire_date 不一致（資料異常）時，以 limit_type 為準擋下
	 *
	 * SQL 的 REGEXP 只看得到 expire_date 的形狀，這是迴圈內的第二道防線。
	 */
	public function test_limit_type非fixed但有timestamp_不觸發(): void {
		$pass_id = $this->create_access_pass( 'unlimited' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) ( \time() + DAY_IN_SECONDS ) );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured, 'limit_type=unlimited 不該被預警' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 通行證已被刪除時靜默跳過
	 */
	public function test_通行證已刪除_不觸發(): void {
		$pass_id = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) ( \time() + DAY_IN_SECONDS ) );
		\wp_delete_post( $pass_id, true );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 使用者已被刪除時靜默跳過（無從寄信）
	 */
	public function test_使用者已刪除_不觸發(): void {
		$pass_id = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( 999999, $pass_id, null, (string) ( \time() + DAY_IN_SECONDS ) );

		ExpiringNotifier::run();

		$this->assertSame( [], $this->captured );
	}

	// ========== 多筆 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 多筆命中時逐筆觸發，不命中的不混入
	 */
	public function test_多筆混合_只觸發命中者(): void {
		$hit_a = $this->create_access_pass( 'fixed' );
		$hit_b = $this->create_access_pass( 'assigned' );
		$miss  = $this->create_access_pass( 'unlimited' );

		$bob = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		Repository::insert_or_update( $this->alice_id, $hit_a, null, (string) ( \time() + DAY_IN_SECONDS ) );
		Repository::insert_or_update( $bob, $hit_b, null, (string) ( \time() + ( 2 * DAY_IN_SECONDS ) ) );
		Repository::insert_or_update( $this->alice_id, $miss, null, '0' );

		ExpiringNotifier::run();

		$pass_ids = $this->captured_pass_ids();
		$this->assertCount( 2, $pass_ids );
		$this->assertContains( $hit_a, $pass_ids );
		$this->assertContains( $hit_b, $pass_ids );
		$this->assertNotContains( $miss, $pass_ids );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 掃描本身是唯讀的——連跑兩次會觸發兩次（去重是下游 PowerEmail 的責任）
	 *
	 * 這一點刻意釘住：若哪天有人把去重塞進掃描器，前台「續期後應再提醒」的行為會一起壞掉。
	 */
	public function test_掃描不做去重_連跑兩次觸發兩次(): void {
		$pass_id = $this->create_access_pass( 'fixed' );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) ( \time() + DAY_IN_SECONDS ) );

		ExpiringNotifier::run();
		ExpiringNotifier::run();

		$this->assertCount( 2, $this->captured );
	}
}
