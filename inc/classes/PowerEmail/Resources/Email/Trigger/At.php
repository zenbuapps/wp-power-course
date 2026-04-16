<?php

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Trigger;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\PowerEmail\Resources\Email;
use J7\PowerCourse\PowerEmail\Resources\Email\Email as EmailResource;
use J7\PowerCourse\Resources\Course\LifeCycle as CourseLifeCycle;
use J7\PowerCourse\Resources\Chapter\Core\LifeCycle as ChapterLifeCycle;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;


/**
 * Email Trigger At 觸發發信時機點
 */
final class At {
	use \J7\WpUtils\Traits\SingletonTrait;

	const AS_GROUP = 'power_email';

	const SEND_USERS_HOOK = 'power_email_send_users'; // 直接寄給用戶的 AS hook

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

		$post_id = $chapter_id ? $chapter_id : $course_id;
		$is_sent = $email->is_sent($post_id, $user_id);

		if ( $is_sent ) {
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
	public function send_course_email( $args ): void {
		$email = new EmailResource(  $args['email_id'] );
		$email->send_course_email(  $args['user_id'], $args['course_id'], $args['chapter_id'] ?? 0 );
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
				'hook'  => $hook,
				'group' => $group,
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
