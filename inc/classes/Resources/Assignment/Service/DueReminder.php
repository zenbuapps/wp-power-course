<?php
/**
 * 作業截止提醒的每日掃描（Issue #131 P6）
 *
 * 每日 09:00（站台時區）掃描「截止時間落在 [現在, 現在 + 48 小時]（含兩端）」的已發佈作業，
 * 對仍有有效觀看權且尚未繳交的學員觸發 At::ASSIGNMENT_DUE_SOON_ACTION，
 * 由 PowerEmail\Trigger\At 接手排信（P5 已就緒）。
 *
 * 為何是專屬的每日排程，而不是掛既有的 Bootstrap::SCHEDULE_ACTION：
 * 理由同 AccessPass\Service\ExpiringNotifier——後者每 10 分鐘跑一次，
 * 截止提醒不需要 10 分鐘精度，掛上去等於每天多跑 143 次無謂的全站掃描。
 *
 * 為何去重不在這裡做：
 * 同一份作業在截止前會連續兩天被掃到，去重交給下游的 pc_email_records.identifier
 * （ids = [ assignment_id, due_at ]，DEC-10）——一個截止時間只寄一封，
 * 截止時間改過 identifier 就變了，會依新的截止時間再提醒一次。
 *
 * ⚠️ 本檔的 Assignment 指「章節作業」，與 Resources\Teacher 底下的「講師指派」Service 同名但無關（R-01）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\At;
use J7\PowerCourse\PowerEmail\Resources\Email\Trigger\AtHelper;
use J7\PowerCourse\Resources\AccessPass\Service\Repository as AccessPassRepository;
use J7\PowerCourse\Resources\Assignment\Core\CPT;
use J7\PowerCourse\Resources\Assignment\Model\Assignment as AssignmentModel;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;
use J7\PowerCourse\Resources\Assignment\Utils\Utils;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class DueReminder
 * 作業截止提醒的每日掃描器。
 */
final class DueReminder {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string Action Scheduler 的每日掃描 hook */
	public const SCAN_HOOK = 'pc_assignment_due_soon_scan';

	/** @var string 掃到「即將截止 + 尚未繳交」的學員時觸發的 action（PowerEmail\Trigger\At 接手排信） */
	public const DUE_SOON_ACTION = At::ASSIGNMENT_DUE_SOON_ACTION;

	/** @var int 提前提醒的時間長度（48 小時，對齊參考實作 course-assignments-cron.service.ts） */
	public const LEAD_TIME_SECONDS = 172800;

	/** @var int 每日掃描的執行時刻（站台時區的小時） */
	private const SCAN_HOUR = 9;

	/** Constructor */
	public function __construct() {
		\add_action( self::SCAN_HOOK, [ __CLASS__, 'run' ] );
	}

	/**
	 * 確保每日掃描已排程在「站台時區的 09:00」（冪等）
	 *
	 * 首次執行時間 = 站台時區的下一個 09:00；間隔固定 DAY_IN_SECONDS。
	 *
	 * 已排在 09:00 時直接返回，不重複建立；但若既有排程的執行時刻**不是**站台時區的 09:00
	 * （站長改過站台時區、或 DST 站台跨過日光節約邊界後固定 86400 間隔把時間推移了一小時，R-06），
	 * 則取消後重排——feature 的後置條件是「存在每日 09:00 的掃描排程」，
	 * 排在 17:00 的排程並不滿足它，只檢查「有沒有」會讓時區改動後永遠回不去 09:00。
	 *
	 * @return void
	 */
	public static function maybe_schedule_scan(): void {
		if ( ! \function_exists( 'as_next_scheduled_action' ) || ! \function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		$next = \as_next_scheduled_action( self::SCAN_HOOK );

		// true = 有一筆正在執行中的掃描（AS 對 running 狀態回傳 bool 而非 timestamp），
		// 跑完會自己續排下一次，這一輪不要插手。
		if ( true === $next ) {
			return;
		}

		if ( \is_int( $next ) ) {
			if ( self::is_at_scan_hour( $next ) ) {
				return;
			}

			if ( \function_exists( 'as_unschedule_all_actions' ) ) {
				\as_unschedule_all_actions( self::SCAN_HOOK, null );
			}
		}

		\as_schedule_recurring_action( self::get_next_scan_timestamp(), \DAY_IN_SECONDS, self::SCAN_HOOK );
	}

	/**
	 * 指定 timestamp 換算成站台時區後是否正好是 09:00
	 *
	 * @param int $timestamp Unix timestamp
	 * @return bool
	 */
	private static function is_at_scan_hour( int $timestamp ): bool {
		return \sprintf( '%02d:00', self::SCAN_HOUR ) === \wp_date( 'H:i', $timestamp );
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

	/**
	 * 掃描即將截止的作業並對尚未繳交的學員觸發提醒 action（計劃 §6.4）
	 *
	 * 流程：
	 *   1. Action Scheduler 未載入 → 靜默略過（下游排信需要它）
	 *   2. 沒有已發佈的 assignment_due_soon 模板 → 直接結束（大站的成本防線，R-24）
	 *   3. 查「publish 且 due_at ∈ [now, now + 48h]」的作業，依課程分組
	 *   4. 每門課先驗 is_course_ready()（未開課 / 課程已刪除整門跳過），
	 *      候選學員每門課只算一次（avl_course_ids ∪ 權限包持有者 → is_avl && ! is_expired）
	 *   5. 每份作業扣掉已繳交者後逐一 do_action；單一作業的例外只 log 不中斷整輪掃描
	 *
	 * @return void
	 */
	public static function run(): void {
		if ( ! \function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		if ( ! self::has_published_template() ) {
			return;
		}

		$assignments = self::get_due_soon_assignments();
		if ( ! $assignments ) {
			return;
		}

		foreach ( self::group_by_course( $assignments ) as $course_id => $course_assignments ) {
			self::notify_course( (int) $course_id, $course_assignments );
		}
	}

	/**
	 * 是否存在已發佈的 assignment_due_soon 郵件模板
	 *
	 * 沒有模板時整輪掃描什麼都不會寄，提早結束可省下全站作業 / 學員的查詢（R-24）。
	 *
	 * @return bool
	 */
	private static function has_published_template(): bool {
		$email_ids = \get_posts(
			[
				'post_type'      => EmailCPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => 'trigger_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => AtHelper::ASSIGNMENT_DUE_SOON, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);

		return (bool) $email_ids;
	}

	/**
	 * 取得截止時間落在 [現在, 現在 + 48 小時]（含兩端）的已發佈作業
	 *
	 * Post_status 限定 publish，垃圾桶 / 草稿的作業查不到（feature：垃圾桶中的作業不寄送）；
	 * due_at = 0（無截止時間）一定小於現在，自然被視窗排除。
	 *
	 * @return array<int, AssignmentModel>
	 */
	private static function get_due_soon_assignments(): array {
		$now   = Clock::now();
		$until = $now + self::LEAD_TIME_SECONDS;

		/** @var array<int, mixed> $posts */
		$posts = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => 'due_at',
						'value'   => [ $now, $until ],
						'compare' => 'BETWEEN',
						'type'    => 'NUMERIC',
					],
				],
			]
		);

		$assignments = [];
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$assignments[] = AssignmentModel::from_post( $post );
		}

		return $assignments;
	}

	/**
	 * 依課程分組（同一門課的候選學員只算一次）
	 *
	 * @param array<int, AssignmentModel> $assignments 作業 Model 陣列
	 * @return array<int, array<int, AssignmentModel>> 以 course_id 為鍵
	 */
	private static function group_by_course( array $assignments ): array {
		$grouped = [];
		foreach ( $assignments as $assignment ) {
			if ( $assignment->course_id <= 0 ) {
				continue;
			}
			$grouped[ $assignment->course_id ][] = $assignment;
		}

		return $grouped;
	}

	/**
	 * 對單一課程的所有即將截止作業觸發提醒
	 *
	 * @param int                         $course_id   課程（商品）ID
	 * @param array<int, AssignmentModel> $assignments 該課程即將截止的作業
	 * @return void
	 */
	private static function notify_course( int $course_id, array $assignments ): void {
		$product = \wc_get_product( $course_id );

		// 課程已刪除 / 不是商品 → 整門跳過（與 Gate 的靜默降級一致）
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		// 課程尚未開課 → 整門跳過
		if ( ! CourseUtils::is_course_ready( $product ) ) {
			return;
		}

		$candidate_user_ids = self::get_candidate_user_ids( $course_id, $product );
		if ( ! $candidate_user_ids ) {
			return;
		}

		$assignment_ids = \array_map( static fn( AssignmentModel $a ): int => $a->id, $assignments );

		try {
			// 一次 SQL 撈完本課程所有作業的已繳交者，避免逐份作業查 DB（R-24）
			$submitted_by_assignment = SubmissionRepository::get_submitted_user_ids( $assignment_ids );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'Assignment due soon scan failed to load submissions',
				'error',
				[
					'course_id' => $course_id,
					'error'     => $e->getMessage(),
				]
			);
			return;
		}

		foreach ( $assignments as $assignment ) {
			try {
				$submitted_user_ids = $submitted_by_assignment[ $assignment->id ] ?? [];
				$recipients         = \array_diff( $candidate_user_ids, $submitted_user_ids );

				foreach ( $recipients as $user_id ) {
					/**
					 * 作業即將截止且學員尚未繳交
					 *
					 * @param int $assignment_id 作業 post ID
					 * @param int $user_id       學員 user ID
					 * @param int $course_id     課程（商品）ID
					 * @param int $due_at        截止時間 10 位 Unix timestamp
					 */
					\do_action( self::DUE_SOON_ACTION, $assignment->id, (int) $user_id, $course_id, $assignment->due_at );
				}
			} catch ( \Throwable $e ) {
				// 單一作業算壞不能拖垮整輪掃描（計劃 §7）
				Plugin::logger(
					'Assignment due soon scan failed for assignment',
					'error',
					[
						'assignment_id' => $assignment->id,
						'course_id'     => $course_id,
						'error'         => $e->getMessage(),
					]
				);
				continue;
			}
		}
	}

	/**
	 * 計算某門課「目前仍有有效觀看權」的候選學員（同一次掃描內同一門課只算一次）
	 *
	 * 候選來源兩條，取聯集後才逐一判定，避免漏掉任何一種授權方式：
	 *   1. avl_course_ids usermeta（逐課綁定，單次 WP_User_Query）
	 *   2. pc_user_access_pass 的持有者（通行證 compute-on-read，不寫 avl_course_ids，單次 SQL）
	 *
	 * 判定前先 prime_user_caches() 把 user row + usermeta 一次灌進物件快取，
	 * 後續每位學員的 is_avl() / is_expired() / get_userdata() 都是記憶體命中，不會退化成逐列查詢（R-24）。
	 *
	 * @param int         $course_id 課程（商品）ID
	 * @param \WC_Product $product   課程商品（is_expired() 需要，避免逐人 wc_get_product）
	 * @return array<int, int> 有效觀看權的學員 user ID 陣列
	 */
	private static function get_candidate_user_ids( int $course_id, \WC_Product $product ): array {
		/** @var array<int, int|string> $enrolled_ids */
		$enrolled_ids = \get_users(
			[
				'meta_key'   => 'avl_course_ids', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $course_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ID',
			]
		);

		$candidates = \array_values(
			\array_unique(
				\array_merge(
					\array_map( 'intval', $enrolled_ids ),
					AccessPassRepository::get_all_holder_user_ids()
				)
			)
		);

		if ( ! $candidates ) {
			return [];
		}

		Utils::prime_user_caches( $candidates );

		$valid = [];
		foreach ( $candidates as $user_id ) {
			// 使用者已刪除 → 無從寄信
			if ( ! \get_userdata( $user_id ) ) {
				continue;
			}

			// is_avl()：逐課綁定或「涵蓋此課程的有效通行證」（Issue #252 OR 疊加）
			if ( ! CourseUtils::is_avl( $course_id, $user_id ) ) {
				continue;
			}

			// R-06：is_expired() 讀的是 time() 不是 Clock::now()，屬既有 Utils\Course 行為，本模組不改寫
			if ( CourseUtils::is_expired( $product, $user_id ) ) {
				continue;
			}

			$valid[] = $user_id;
		}

		return $valid;
	}

	/**
	 * 站台時區的下一個 09:00（Unix timestamp）
	 *
	 * 已過今天 09:00 就排到明天，等於「最快 24 小時內會跑第一次」。
	 *
	 * @return int
	 */
	private static function get_next_scan_timestamp(): int {
		$timezone = \wp_timezone();
		$now      = ( new \DateTimeImmutable( '@' . Clock::now() ) )->setTimezone( $timezone );
		$next     = $now->setTime( self::SCAN_HOUR, 0, 0 );

		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}
}
