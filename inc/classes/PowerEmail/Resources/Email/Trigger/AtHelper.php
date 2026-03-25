<?php
/**
 * Email Trigger At Helper
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Trigger;

/**
 * Class At 觸發發信時機點
 */
final class AtHelper {

	// 六個時機點
	const COURSE_GRANTED     = 'course_granted';
	const COURSE_FINISHED    = 'course_finish';
	const COURSE_LAUNCHED    = 'course_launch';
	const CHAPTER_ENTERED    = 'chapter_enter';
	const CHAPTER_FINISHED   = 'chapter_finish';
	const ORDER_CREATED      = 'order_created'; // 目前 email 沒有這個 trigger
	const CHAPTER_UNFINISHED = 'chapter_unfinished'; // 目前 email 沒有這個 trigger
	const COURSE_REMOVED     = 'course_removed'; // 目前 email 沒有這個 trigger
	const UPDATE_STUDENT     = 'update_student'; // 目前 email 沒有這個 trigger
	/**
	 * 允許的時機點
	 *
	 * @var array<string>
	 */
	public static array $allowed_slugs = [
		self::COURSE_GRANTED,
		self::COURSE_FINISHED,
		self::COURSE_LAUNCHED,
		self::CHAPTER_ENTERED,
		self::CHAPTER_FINISHED,
		self::ORDER_CREATED, // 目前 email 沒有這個 trigger
		self::CHAPTER_UNFINISHED, // 目前 email 沒有這個 trigger
		self::COURSE_REMOVED, // 目前 email 沒有這個 trigger
		self::UPDATE_STUDENT, // 目前 email 沒有這個 trigger
	];

	/**
	 * @var string 時機點 label
	 */
	public string $label = '';

	/**
	 * @var string 觸發發信時機點 hook Action Scheduler hook
	 */
	public string $hook = '';

	/**
	 * @var string {slug}_at 為達成條件的時間點 meta key 名稱
	 */
	public string $meta_key_at = '';

	/**
	 * @var string {slug}_sent_at 為發信時間點 meta key 名稱
	 */
	public string $meta_key_sent_at = '';

	/**
	 * Constructor
	 *
	 * @param string $slug 時機點 slug
	 */
	public function __construct( public string $slug ) {
		$this->init();
	}

	/**
	 * 初始化
	 *
	 * @return void
	 */
	private function init(): void {
		$this->validate_slug( $this->slug );
		$this->set_label();
		// 觸發發信時機點
		$this->hook = "power_email_send_{$this->slug}";
		// 達成條件的時間點
		$this->meta_key_at = "{$this->slug}_at";
		// 發信時間點
		$this->meta_key_sent_at = "{$this->slug}_sent_at";
	}


	/**
	 * 取得時機點的 label
	 *
	 * @return void
	 */
	private function set_label(): void {
		$this->label = match ( $this->slug ) {
			self::COURSE_GRANTED => '開通課程權限後',
			self::COURSE_FINISHED  => '課程完成時',
			self::COURSE_LAUNCHED  => '課程開課時',
			self::CHAPTER_ENTERED  => '進入單元時',
			self::CHAPTER_FINISHED => '完成單元時',
			self::ORDER_CREATED  => '訂單成立時',
			self::CHAPTER_UNFINISHED => '單元未完成時',
			self::COURSE_REMOVED => '管理員手動移除課程權限時',
			self::UPDATE_STUDENT => '更新學員觀看課程期限時',
			default => '無效的時機點',
		};
	}

	/**
	 * 驗證時機點 slug
	 *
	 * @param string $slug 時機點 slug
	 * @return void
	 */
	private function validate_slug( string $slug ): void {
		if ( ! in_array( $slug, self::$allowed_slugs, true ) ) {
			\J7\WpUtils\Classes\ErrorLog::info( 'Invalid slug', $slug );
			$this->slug = self::COURSE_GRANTED;
		}
	}
}
