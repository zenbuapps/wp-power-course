<?php
/**
 * AccessPass ExpiringNotifier（到期預警掃描）— Issue #252 補完
 *
 * 每日掃描 pc_user_access_pass，找出「N 天內即將到期」的持有列，
 * 觸發 power_course_access_pass_expiring action 交給 PowerEmail 排信。
 *
 * 為何是專屬的每日排程，而不是掛既有的 Bootstrap::SCHEDULE_ACTION：
 * 後者每 10 分鐘跑一次，到期預警不需要 10 分鐘精度，掛上去等於每天多跑 143 次無謂的全表掃描。
 *
 * 為何只掃 fixed / assigned：
 *   - unlimited           無到期日，沒有「快到期」可言。
 *   - follow_subscription 到期由訂閱生命週期決定（expire_date 是 "subscription_{id}"），
 *     續訂 / 到期通知本來就是 WooCommerce Subscriptions 的職責，不重複造輪子。
 * SQL 的 REGEXP 已經把這兩類排除（'0' 只有 1 位、"subscription_x" 非純數字），
 * 迴圈內再依 limit_type 複核一次，避免資料異常時誤寄。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\AccessPass\Model\AccessPass;
use J7\WpUtils\Classes\WP;

/**
 * Class ExpiringNotifier
 * 通行證到期預警的每日掃描器。
 */
final class ExpiringNotifier {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Action Scheduler 的每日掃描 hook */
	public const SCAN_HOOK = 'pc_access_pass_expiring_scan';

	/** @var string 掃到即將到期的持有列時觸發的 action（PowerEmail\Trigger\At 接手排信） */
	public const EXPIRING_ACTION = 'power_course_access_pass_expiring';

	/** @var array<string> 需要到期預警的期限模式（只有這兩種有絕對到期 timestamp） */
	private const NOTIFIABLE_LIMIT_TYPES = [ 'fixed', 'assigned' ];

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'maybe_schedule_scan' ] );
		\add_action( self::SCAN_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * 確保每日掃描已排程（冪等）
	 *
	 * @return void
	 */
	public static function maybe_schedule_scan(): void {
		if ( ! \function_exists( 'as_next_scheduled_action' ) || ! \function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( ! \as_next_scheduled_action( self::SCAN_HOOK ) ) {
			\as_schedule_recurring_action( \time(), \DAY_IN_SECONDS, self::SCAN_HOOK );
		}
	}

	/**
	 * 掃描即將到期的持有列並逐筆觸發預警 action
	 *
	 * 同一筆持有列會在到期前連續 N 天被掃到，去重交給下游：
	 * PowerEmail 的 identifier 含 expire_timestamp，一次到期只會寄一封；
	 * 續期後 expire_timestamp 改變 → identifier 改變 → 下一次到期仍會提醒。
	 *
	 * @return void
	 */
	public static function run(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;

		if ( ! WP::is_table_exists( $table ) ) {
			return;
		}

		$threshold_days = Holdings::get_expiring_threshold_days();
		$now            = \time();
		$until          = $now + ( $threshold_days * \DAY_IN_SECONDS );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT user_id, pass_id, expire_date FROM {$table} WHERE expire_date REGEXP '^[0-9]{10}$' AND CAST(expire_date AS UNSIGNED) BETWEEN %d AND %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now,
				$until
			)
		);

		if ( ! \is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! \is_object( $row ) ) {
				continue;
			}

			$user_id          = (int) ( $row->user_id ?? 0 );
			$pass_id          = (int) ( $row->pass_id ?? 0 );
			$expire_timestamp = (int) ( $row->expire_date ?? 0 );

			if ( $user_id <= 0 || $pass_id <= 0 || $expire_timestamp <= 0 ) {
				continue;
			}

			$pass = AccessPass::instance( $pass_id );
			// 通行證已被刪除 → 持有列殘留但無意義，跳過（與 Gate 的靜默降級一致）
			if ( ! $pass instanceof AccessPass ) {
				continue;
			}

			// 複核期限模式：SQL 的 REGEXP 理論上已排除，資料異常時這裡是第二道防線
			if ( ! \in_array( $pass->limit_type, self::NOTIFIABLE_LIMIT_TYPES, true ) ) {
				continue;
			}

			// 使用者已刪除 → 無從寄信
			if ( ! \get_userdata( $user_id ) ) {
				continue;
			}

			/**
			 * 通行證即將到期
			 *
			 * @param int $user_id          學員 user ID
			 * @param int $pass_id          通行證 post ID
			 * @param int $expire_timestamp 到期 Unix timestamp
			 */
			\do_action( self::EXPIRING_ACTION, $user_id, $pass_id, $expire_timestamp );
		}
	}

	/**
	 * 取消每日掃描排程（外掛停用時呼叫）
	 *
	 * @return void
	 */
	public static function unschedule_scan(): void {
		if ( ! \function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		\as_unschedule_all_actions( self::SCAN_HOOK, null );
	}
}
