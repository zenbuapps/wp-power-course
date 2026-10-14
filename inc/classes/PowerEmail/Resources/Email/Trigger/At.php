<?php

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Trigger;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\PowerEmail\Resources\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;
use J7\PowerCourse\Resources\Course\LifeCycle as CourseLifeCycle;
use J7\PowerCourse\Resources\Chapter\Core\LifeCycle as ChapterLifeCycle;
use J7\PowerCourse\Resources\AccessPass\Service\ExpiringNotifier;
use J7\PowerCourse\Resources\Assignment\Service\Grading as AssignmentGrading;
use J7\PowerCourse\Resources\Assignment\Service\SubmissionRepository as AssignmentSubmissionRepository;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;


/**
 * Email Trigger At 觸發發信時機點
 */
final class At {
	use \J7\WpUtils\Traits\SingletonTrait;

	const AS_GROUP = 'power_email';

	const SEND_USERS_HOOK = 'power_email_send_users'; // 直接寄給用戶的 AS hook

	/**
	 * 作業截止提醒的 WP action（Issue #131）
	 *
	 * 本階段（P5）只做「收到這個 action 之後怎麼排信、怎麼寄」這條路徑；
	 * 「每天掃描誰該收信」由 P6 的 Assignment\Service\DueReminder 負責並以
	 * do_action( self::ASSIGNMENT_DUE_SOON_ACTION, $assignment_id, $user_id, $course_id, $due_at )
	 * 觸發（參數順序即 schedule_assignment_due_soon_email() 的簽名）。
	 * 常數寫在這裡而不是引用 DueReminder：該類別尚未存在，引用會讓整個外掛載入即 fatal。
	 */
	const ASSIGNMENT_DUE_SOON_ACTION = 'power_course_assignment_due_soon';

	/** @var array<int, string> 會觸發批改通知的狀態（改回 pending 不寄，對齊 Assignment\Service\Grading） */
	private const GRADED_NOTIFIABLE_STATUSES = [ 'pass', 'fail' ];

	/** Constructor */
	public function __construct() {
		// ---- 開通課程權限後 ----//
		\add_action( CourseLifeCycle::ADD_STUDENT_TO_COURSE_ACTION, [ $this, 'schedule_course_granted_email' ], 30, 3 );
		\add_action( ( new AtHelper(AtHelper::COURSE_GRANTED) )->hook, [ $this, 'send_course_email' ], 10 );
		// ---- END 開通課程權限後 ----//

		// ---- 課程開課時 ----//
		\add_action( CourseLifeCycle::COURSE_LAUNCHED_ACTION, [ $this, 'course_launch_email' ], 20, 2 );
		\add_action( ( new AtHelper(AtHelper::COURSE_LAUNCHED) )->hook, [ $this, 'send_course_email' ], 10 );
		// ---- END 課程開課時 ----//

		// ---- 進入單元時 ----//
		\add_action( ChapterLifeCycle::CHAPTER_ENTERED_ACTION, [ $this, 'chapter_enter_email' ], 10, 2 );
		\add_action( ( new AtHelper(AtHelper::CHAPTER_ENTERED) )->hook, [ $this, 'send_course_email' ], 10 );
		// ---- END 進入單元時 ----//

		// ---- 完成單元時 ----//
		\add_action( ChapterLifeCycle::CHAPTER_FINISHED_ACTION, [ $this, 'chapter_finish_email' ], 10, 3 );
		\add_action( ( new AtHelper(AtHelper::CHAPTER_FINISHED) )->hook, [ $this, 'send_course_email' ], 10 );
		// ---- END 完成單元時 ----//

		// ---- 課程通行證到期前 ----//
		\add_action( ExpiringNotifier::EXPIRING_ACTION, [ $this, 'schedule_access_pass_expiring_email' ], 10, 3 );
		\add_action( ( new AtHelper( AtHelper::ACCESS_PASS_EXPIRING ) )->hook, [ $this, 'send_access_pass_expiring_email' ], 10 );
		// ---- END 課程通行證到期前 ----//

		// ---- 作業批改時 ----//
		\add_action( AssignmentGrading::GRADED_ACTION, [ $this, 'schedule_assignment_graded_email' ], 10, 5 );
		\add_action( ( new AtHelper( AtHelper::ASSIGNMENT_GRADED ) )->hook, [ $this, 'send_assignment_graded_email' ], 10 );
		// ---- END 作業批改時 ----//

		// ---- 作業截止前 ----//
		\add_action( self::ASSIGNMENT_DUE_SOON_ACTION, [ $this, 'schedule_assignment_due_soon_email' ], 10, 4 );
		\add_action( ( new AtHelper( AtHelper::ASSIGNMENT_DUE_SOON ) )->hook, [ $this, 'send_assignment_due_soon_email' ], 10 );
		// ---- END 作業截止前 ----//

		\add_filter( 'power_email_can_send', [ $this, 'trigger_condition' ], 20, 5 );

		// ---- 寄送指定用戶 emails ----//
		\add_action( self::SEND_USERS_HOOK, [ $this, 'send_users_callback' ], 10, 2 );
	}

	/**
	 * 安排 觸發開通課程權限 的寄送時機
	 *
	 * @param int        $user_id 用戶ID
	 * @param int        $course_id 課程ID
	 * @param int|string $expire_date 課程到期日
	 *
	 * @return void
	 */
	public function schedule_course_granted_email( int $user_id, int $course_id, int|string $expire_date ): void {
		$this->schedule_email(
			AtHelper::COURSE_GRANTED,
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
			);
	}

	/**
	 * 觸發條件
	 * 根據觸發條件不同決定要不要寄 Email
	 *
	 * @param bool          $can_send 是否可以寄信
	 * @param EmailResource $email 信件
	 * @param int           $user_id 用戶 ID
	 * @param int           $course_id 課程 ID
	 * @param int           $chapter_id 章節 ID
	 * @return bool
	 */
	public function trigger_condition( bool $can_send, EmailResource $email, int $user_id, int $course_id, int $chapter_id ): bool {
		// 如果原本就不能寄信，就不用檢查觸發條件
		// 如果不存在 課程 id，也直接返回就好
		// 沒有 course_id 的情況是對用戶直接寄信
		if (!$can_send || !$course_id) {
			return $can_send;
		}

		// allow_repeat_send=false 才檢查是否已寄；true 時無視 mark_as_sent 照觸發就寄。
		// 傳 [ $course_id, $chapter_id ] 而非單一 id —— 必須與寫入端
		// CPT::record_user_id_after_send_email() 的 get_identifier() 參數完全一致，
		// 否則 identifier 字串對不上，去重永遠失效（見 Email::is_sent docblock）
		if ( ! $email->allow_repeat_send && $email->is_sent( [ $course_id, $chapter_id ], $user_id ) ) {
			return false;
		}

		if ($email->condition instanceof Condition) {
			return $email->condition->can_trigger($user_id, $course_id, $chapter_id);
		}

		return true;
	}


	/**
	 * Send users callback
	 * 指定用戶的發信
	 *
	 * @param array<numeric-string|int> $email_ids 電子郵件 ID 陣列
	 * @param array<numeric-string|int> $user_ids 使用者 ID 陣列
	 */
	public function send_users_callback( array $email_ids, array $user_ids ): void {

		$batch_options = [
			'batch_size'  => 20,  // 每批次處理的項目數量
			'pause_ms'    => 750, // 每批次之間暫停的毫秒數
			'flush_cache' => false, // 每批次後是否清除 WordPress 快取
		];

		PowerhouseUtils::batch_process(
			$email_ids,
			static function ( $email_id ) use ( $user_ids, $batch_options ) {
				$email = new EmailResource( (int) $email_id );
				PowerhouseUtils::batch_process(
				$user_ids,
				static fn( $user_id ) => $email->send_email( (int) $user_id ),
					$batch_options
					);
			},
			$batch_options
			);
	}


	/**
	 * 課程開課時的 Email
	 *
	 * @param int $user_id 用戶 ID
	 * @param int $course_id 課程 ID
	 */
	public function course_launch_email( int $user_id, int $course_id ): void {
		$this->schedule_email(
			'course_launch',
			[
				'user_id'   => $user_id,
				'course_id' => $course_id,
			]
			);
	}

	/**
	 * 進入章節時的 Email
	 *
	 * @param \WP_Post    $chapter 章節文章物件
	 * @param \WC_Product $product 課程
	 */
	public function chapter_enter_email( $chapter, $product ): void {
		$user_id   = \get_current_user_id();
		$course_id = $product->get_id();

		$this->schedule_email(
			'chapter_enter',
			[
				'user_id'    => $user_id,
				'course_id'  => $course_id,
				'chapter_id' => $chapter->ID,
			]
			);
	}

	/**
	 * 完成章節時的 Email
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id 課程 ID
	 * @param int $user_id 用戶 ID
	 */
	public function chapter_finish_email( int $chapter_id, int $course_id, int $user_id ): void {
		$this->schedule_email(
			'chapter_finish',
			[
				'user_id'    => $user_id,
				'course_id'  => $course_id,
				'chapter_id' => $chapter_id,
			]
			);
	}



	/**
	 * 發送課程的 Email
	 *
	 * @param array{email_id: int, user_id: int, course_id: int, chapter_id: ?int, context: string} $args 參數
	 */
	/**
	 * 安排課程通行證到期預警信的寄送
	 *
	 * 刻意不複用 schedule_email()：
	 *   1. schedule_email() 的 identifier 由 course_id / chapter_id 組成，通行證情境沒有這兩者；
	 *      這裡改用 [pass_id, expire_timestamp]，讓「同一次到期只寄一封、續期後可再寄」成立。
	 *   2. schedule_email() 只用 as_get_scheduled_actions 擋「還沒跑完的排程」。到期前 N 天
	 *      每天都會被掃到，信寄完後 action 轉 COMPLETE，隔天就擋不住了 → 必須再用 is_sent()
	 *      查 pc_email_records 的永久紀錄才不會天天重寄。
	 *   3. 不套用 email 的延遲寄送設定：「到期前 N 天」本身就是時間點，再疊一層延遲只會讓
	 *      實際寄送時間難以預期（延遲超過 N 天甚至會在到期後才寄）。
	 *
	 * @param int $user_id          學員 user ID
	 * @param int $pass_id          通行證 post ID
	 * @param int $expire_timestamp 到期 Unix timestamp
	 *
	 * @return void
	 */
	public function schedule_access_pass_expiring_email( int $user_id, int $pass_id, int $expire_timestamp ): void {
		$at_helper = new AtHelper( AtHelper::ACCESS_PASS_EXPIRING );
		$hook      = $at_helper->hook;

		$email_ids = \get_posts(
			[
				'post_type'      => Email\CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_key'       => 'trigger_at',
				'meta_value'     => $at_helper->slug,
			]
		);

		$identifier_ids = [ $pass_id, $expire_timestamp ];

		foreach ( $email_ids as $email_id ) {
			$email = new EmailResource( (int) $email_id );

			// 這一次到期已經寄過 → 不再寄（identifier 含 expire_timestamp）
			if ( $email->is_sent( $identifier_ids, $user_id ) ) {
				continue;
			}

			$group = $email->get_identifier( $identifier_ids, $user_id );

			// 已排程但還沒跑完 → 不重複排
			$scheduled = \as_get_scheduled_actions(
				[
					'hook'   => $hook,
					'group'  => $group,
					'status' => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
				],
				'ids'
			);
			if ( $scheduled ) {
				continue;
			}

			\as_enqueue_async_action(
				$hook,
				[
					[
						'email_id'         => (int) $email_id,
						'user_id'          => $user_id,
						'pass_id'          => $pass_id,
						'expire_timestamp' => $expire_timestamp,
						'context'          => AtHelper::ACCESS_PASS_EXPIRING,
					],
				],
				$group
			);
		}
	}

	/**
	 * Action Scheduler callback：寄出通行證到期預警信
	 *
	 * @param mixed $args 排程時帶入的參數（Action Scheduler 反序列化後型別不保證，故逐一轉型）
	 *
	 * @return void
	 */
	public function send_access_pass_expiring_email( $args ): void {
		if ( ! \is_array( $args ) ) {
			return;
		}

		$email_id         = (int) ( $args['email_id'] ?? 0 );
		$user_id          = (int) ( $args['user_id'] ?? 0 );
		$pass_id          = (int) ( $args['pass_id'] ?? 0 );
		$expire_timestamp = (int) ( $args['expire_timestamp'] ?? 0 );

		if ( ! $email_id || ! $user_id || ! $pass_id ) {
			return;
		}

		$email = new EmailResource( $email_id );
		$email->send_access_pass_email( $user_id, $pass_id, $expire_timestamp );
	}

	/**
	 * 安排「作業批改通知信」的寄送（監聽 Assignment\Service\Grading::GRADED_ACTION）
	 *
	 * 去重單位是「這一次批改」：identifier ids = [ submission_id, graded_at 的 timestamp ]（DEC-10），
	 * 同一筆繳交改判後 graded_at 變了就能再寄一次。
	 *
	 * @param int    $submission_id 繳交 ID
	 * @param int    $user_id       學員 user ID
	 * @param int    $assignment_id 作業 post ID
	 * @param int    $course_id     課程（商品）ID
	 * @param string $status        批改後狀態 pass | fail（pending 不寄）
	 *
	 * @return void
	 */
	public function schedule_assignment_graded_email( int $submission_id, int $user_id, int $assignment_id, int $course_id, string $status ): void {
		if ( ! \in_array( $status, self::GRADED_NOTIFIABLE_STATUSES, true ) ) {
			return;
		}

		$submission = AssignmentSubmissionRepository::find( $submission_id );
		if ( null === $submission ) {
			return;
		}

		$graded_at = self::to_timestamp( $submission->graded_at );

		$this->schedule_assignment_email(
			AtHelper::ASSIGNMENT_GRADED,
			[ $submission_id, $graded_at ],
			[
				'user_id'       => $user_id,
				'assignment_id' => $assignment_id,
				'course_id'     => $course_id,
				'submission_id' => $submission_id,
				'status'        => $status,
				'feedback'      => (string) $submission->feedback,
				'graded_at'     => $graded_at,
			]
		);
	}

	/**
	 * 安排「作業截止提醒信」的寄送（監聽 self::ASSIGNMENT_DUE_SOON_ACTION，由 P6 的掃描觸發）
	 *
	 * 去重單位是「這個截止時間」：identifier ids = [ assignment_id, due_at ]（DEC-10），截止時間改過就能再提醒一次。
	 *
	 * @param int $assignment_id 作業 post ID
	 * @param int $user_id       學員 user ID
	 * @param int $course_id     課程（商品）ID
	 * @param int $due_at        截止時間（10 位 Unix timestamp）
	 *
	 * @return void
	 */
	public function schedule_assignment_due_soon_email( int $assignment_id, int $user_id, int $course_id, int $due_at ): void {
		$this->schedule_assignment_email(
			AtHelper::ASSIGNMENT_DUE_SOON,
			[ $assignment_id, $due_at ],
			[
				'user_id'       => $user_id,
				'assignment_id' => $assignment_id,
				'course_id'     => $course_id,
				'due_at'        => $due_at,
				'status'        => '',
				'feedback'      => '',
			]
		);
	}

	/**
	 * Action Scheduler callback：寄出作業批改通知信
	 *
	 * @param mixed $args 排程時帶入的參數（Action Scheduler 反序列化後型別不保證）
	 *
	 * @return void
	 */
	public function send_assignment_graded_email( $args ): void {
		$this->send_assignment_email( $args );
	}

	/**
	 * Action Scheduler callback：寄出作業截止提醒信
	 *
	 * @param mixed $args 排程時帶入的參數（Action Scheduler 反序列化後型別不保證）
	 *
	 * @return void
	 */
	public function send_assignment_due_soon_email( $args ): void {
		$this->send_assignment_email( $args );
	}

	/**
	 * 找出對應觸發時機的作業信模板並排程（graded / due_soon 共用）
	 *
	 * 刻意不複用 schedule_email()：
	 *   1. schedule_email() 的 identifier 由 course_id / chapter_id 組成；作業情境的去重單位
	 *      是「這一次批改」/「這個截止時間」，必須自己給 identifier ids。
	 *   2. **不呼叫 get_sending_timestamp()**：作業信件一律立即寄送，不套用模板的延遲寄送設定
	 *      （DEC-10，同 access_pass_expiring 的既有做法）。
	 *   3. 排程只擋得住「還沒跑完的 action」，信寄完後 action 轉 COMPLETE 就擋不住了，
	 *      故另外用 is_sent() 查 pc_email_records 的永久紀錄。
	 *
	 * @param string               $slug           AtHelper 的時機點 slug
	 * @param array<int, int>      $identifier_ids 去重用的 ID 組合
	 * @param array<string, mixed> $payload        排程參數（至少含 user_id / assignment_id / course_id）
	 *
	 * @return void
	 */
	private function schedule_assignment_email( string $slug, array $identifier_ids, array $payload ): void {
		$at_helper = new AtHelper( $slug );
		$hook      = $at_helper->hook;
		$user_id   = (int) ( $payload['user_id'] ?? 0 );
		$course_id = (int) ( $payload['course_id'] ?? 0 );

		if ( ! $user_id ) {
			return;
		}

		$email_ids = \get_posts(
			[
				'post_type'      => Email\CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_key'       => 'trigger_at',
				'meta_value'     => $at_helper->slug,
			]
		);

		foreach ( $email_ids as $email_id ) {
			$email = new EmailResource( (int) $email_id );

			// DEC-10：模板指定了課程就只寄該課程的作業；留空 = 所有課程
			if ( ! self::is_course_in_scope( $email, $course_id ) ) {
				continue;
			}

			// 這一次批改 / 這個截止時間已經寄過 → 不再寄
			if ( $email->is_sent( $identifier_ids, $user_id ) ) {
				continue;
			}

			$group = $email->get_identifier( $identifier_ids, $user_id );

			// 已排程但還沒跑完 → 不重複排
			$scheduled = \as_get_scheduled_actions(
				[
					'hook'   => $hook,
					'group'  => $group,
					'status' => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
				],
				'ids'
			);
			if ( $scheduled ) {
				continue;
			}

			$args                   = $payload;
			$args['email_id']       = (int) $email_id;
			$args['context']        = $slug;
			$args['identifier_ids'] = $identifier_ids;

			// 立即排程（排定時間 = 現在），而不是 as_enqueue_async_action()：
			// 後者建立的是 ActionScheduler_NullSchedule，get_date() 永遠回傳 null
			// （NullSchedule 只覆寫建構子、不填父類別的 private $scheduled_date），
			// 於是「排程的目標時間點」在外部完全不可觀測——包含
			// AssignmentTestCase::get_next_scheduled_action_timestamp() 這個驗證
			// 「沒有套用模板延遲寄送設定」（DEC-10）的唯一觀測點。
			// 兩者對 AS queue runner 的效果相同（scheduled_date <= now 立即可執行），
			// 差別只在 async 會多發一次 spawn 請求。
			\as_schedule_single_action( \time(), $hook, [ $args ], $group );
		}
	}

	/**
	 * Action Scheduler callback 共用實作：依排程參數寄出作業信件
	 *
	 * @param mixed $args 排程時帶入的參數
	 *
	 * @return void
	 */
	private function send_assignment_email( $args ): void {
		if ( ! \is_array( $args ) ) {
			return;
		}

		$email_id = (int) ( $args['email_id'] ?? 0 );
		$user_id  = (int) ( $args['user_id'] ?? 0 );

		if ( ! $email_id || ! $user_id ) {
			return;
		}

		$email = new EmailResource( $email_id );
		$email->send_assignment_email( $user_id, $args );
	}

	/**
	 * 模板的課程範圍是否涵蓋這門課（DEC-10 / R-25）
	 *
	 * @param EmailResource $email     信件
	 * @param int           $course_id 課程（商品）ID
	 *
	 * @return bool 模板沒指定課程（留空）時一律為 true
	 */
	private static function is_course_in_scope( EmailResource $email, int $course_id ): bool {
		$condition = $email->condition;
		if ( ! $condition instanceof Condition ) {
			return true;
		}

		$course_ids = \array_map( 'intval', $condition->get_course_ids() );
		if ( ! $course_ids ) {
			return true;
		}

		return \in_array( $course_id, $course_ids, true );
	}

	/**
	 * 站台時區的日期時間字串轉 Unix timestamp（無法解析時回傳 0）
	 *
	 * @param string|null $datetime 例如 2026-09-17 10:00:00（站台時區）
	 *
	 * @return int
	 */
	private static function to_timestamp( ?string $datetime ): int {
		if ( null === $datetime || '' === $datetime ) {
			return 0;
		}

		try {
			return ( new \DateTimeImmutable( $datetime, \wp_timezone() ) )->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Action Scheduler callback：寄出課程相關的觸發信
	 *
	 * @param mixed $args 排程時帶入的參數（email_id / user_id / course_id / chapter_id）
	 *
	 * @return void
	 */
	public function send_course_email( $args ): void {
		if ( ! \is_array( $args ) ) {
			return;
		}

		// Action Scheduler 的 payload 經序列化 / 反序列化後型別不保證，逐一轉型
		$email = new EmailResource( (int) $args['email_id'] );
		$email->send_course_email(
			(int) $args['user_id'],
			(int) $args['course_id'],
			(int) ( $args['chapter_id'] ?? 0 )
		);
	}

	/**
	 * 找出觸發時機的 Email，排程
	 *
	 * @param string                                                 $context 觸發條件
	 * @param array{user_id: int, course_id: int, chapter_id?: ?int} $args 參數
	 */
	private function schedule_email( string $context, array $args ): void {
		$at_helper = new AtHelper($context);
		$hook      = $at_helper->hook;
		$email_ids = \get_posts(
			[
				'post_type'      => Email\CPT::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'meta_key'       => 'trigger_at',
				'meta_value'     => $at_helper->slug,
			]
			);

		foreach ( $email_ids as $email_id ) {
			$email = new EmailResource( (int) $email_id );

			$timestamp = $email->get_sending_timestamp();

			if ( null === $timestamp ) {
				continue;
			}

			$args['email_id'] = $email_id;
			$args['context']  = $context;
			$user_id          = $args['user_id'];
			$course_id        = $args['course_id'];
			$chapter_id       = $args['chapter_id'] ?? 0;
			$group            = $email->get_identifier([ $course_id, $chapter_id ], $user_id);

			// $group 就類似唯一的 key 確認是否已經寄信過
			$scheduled_actions = \as_get_scheduled_actions(
			[
				'hook'   => $hook,
				'group'  => $group,
				'status' => [ \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ],
			],
			'ids'
			);
			$is_scheduled      = (bool) $scheduled_actions;

			if ($is_scheduled) {
				$args['scheduled_actions'] = $scheduled_actions;
				$args['group']             = $group;
				Plugin::logger(
					sprintf(
						/* translators: %s: 觸發條件 slug */
						__( '%s already scheduled or sent, skip duplicate schedule', 'power-course' ),
						$context
					),
					'warning',
					$args
				);
				continue;
			}

			if (0 === $timestamp) { // 立即寄送
				\as_enqueue_async_action( $hook, [ $args ], $group);
				continue;
			}

			\as_schedule_single_action( $timestamp, $hook, [ $args ], $group );
		}
	}
}
