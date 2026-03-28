<?php

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Trigger;

use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Email Trigger Condition 觸發發信時機點
 */
final class Condition {


	/**
	 * @var string 觸發時機點
	 * course_granted | course_finish | course_launch | chapter_finish | chapter_enter
	 * 開通課程時       | 完成課程時     | 課程開課時        | 完成單元時       | 進入單元時
	 */
	public string $trigger_at = '';

	/**
	 * @var array<string> 屬於課程的觸發時機點，反之為屬於章節/單元的觸發時機點
	 */
	private array $course_trigger_at = [ 'course_granted', 'course_finish', 'course_launch' ];

	/**
	 * @var array<string|int> 課程 ID 陣列
	 */
	private array $course_ids = [];

	/**
	 * @var array<string|int> 章節 ID 陣列
	 */
	private array|null $chapter_ids = null;


	/**
	 * @var array<string|int> 課程/章節 ID 陣列
	 */
	public array $required_ids = [];

	/**
	 * @var string 觸發條件
	 * each         | all      | qty_greater_than
	 * 任何一個達成時 | 全部達成時 | 達成指定數量時
	 */
	public string $trigger_condition = 'each';

	/**
	 * @var int|null 數量
	 */
	public int|null $qty = null;

	/**
	 * @var string 發送方式
	 * send_now | send_later
	 * 立即寄送  | 延遲寄送
	 */
	public string $sending_type = 'send_now';

	/**
	 * @var string|null 延遲寄送數量 N 天/時/分
	 * send_now | send_later
	 * 立即寄送  | 延遲寄送
	 */
	public string|null $sending_value = null;


	/**
	 * @var string|null 延遲寄送單位 天/時/分
	 * day | hour | minute
	 * 天   | 時   | 分
	 */
	public string|null $sending_unit = null;

	/**
	 * @var array<string>|null 延遲寄送範圍 開始時間、結束時間 [HH:MM, HH:MM]
	 */
	public array|null $sending_range = null;

	/**
	 * Constructor
	 *
	 * @param array{trigger_at: ?string, trigger_condition: string, course_ids: ?array<string|int>, chapter_ids: ?array<string|int>, qty: ?int, sending: array{type: ?string, value: ?string, unit: ?string, range: ?array{start: string, end: string}}} $condition 觸發條件
	 */
	public function __construct( array $condition ) {
		$this->trigger_at        = $condition['trigger_at'] ?? AtHelper::COURSE_GRANTED;
		$this->trigger_condition = $condition['trigger_condition'];
		$this->course_ids        = (array) ( @$condition['course_ids'] ?? [] );
		$this->chapter_ids       = (array) ( @$condition['chapter_ids'] ?? [] );
		$this->qty               = (int) ( $condition['qty'] ?? null );
		$this->sending_type      = $condition['sending']['type'] ?? 'send_now';
		$this->sending_value     = $condition['sending']['value'] ?? null;
		$this->sending_unit      = $condition['sending']['unit'] ?? null;
		$this->sending_range     = $condition['sending']['range'] ?? null;

		$this->set_required_ids();
	}


	/**
	 * 依照不同條件，取得 required_ids (可能是課程/章節/單元 ID)
	 * 判斷是否屬於課程的觸發時機點，反之為屬於章節/單元的觸發時機點
	 *
	 * @return void
	 */
	private function set_required_ids(): void {
		// 先判斷是否是課程的觸發時機點
		if ( in_array( $this->trigger_at, $this->course_trigger_at, true) ) {
			if ( !empty( $this->course_ids ) ) {
				$this->required_ids = $this->course_ids;
				return;
			}

			/** @var array<int, int> $course_ids */
			$course_ids = \get_posts(
				[
					'post_type'      => 'product',
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'meta_key'       => '_is_course',
					'meta_value'     => 'yes',
				]
				);

			$this->required_ids = $course_ids;
			return;
		}

		// 章節/單元的觸發時機點

		// 第一種可能: 不指定課程 & 不指定章節 -> 取得所有章節
		if ( empty( $this->course_ids ) && empty( $this->chapter_ids ) ) {
			$chapter_ids = \get_posts(
				[
					'post_type'      => ChapterCPT::POST_TYPE,
					'posts_per_page' => -1,
					'post_status'    => 'publish',
					'fields'         => 'ids',
				]
				);

			$this->required_ids = $chapter_ids;
			return;
		}

		// 第二種可能: 指定課程 & 不指定章節 -> 把課程子章節都加總起來
		if ( !empty( $this->course_ids ) && empty( $this->chapter_ids ) ) {
			$chapter_ids = [];
			foreach ( $this->course_ids as $course_id ) {
				$course_chapter_ids = ChapterUtils::get_flatten_post_ids( (int) $course_id);
				$chapter_ids        = array_merge( $chapter_ids, $course_chapter_ids );
			}

			/** @var array<int, int> $chapter_ids */
			$this->required_ids = $chapter_ids;
			return;
		}

		// 第三種可能: (不)指定課程 & 指定章節 -> 取得指定章節
		if ( !empty( $this->chapter_ids ) ) {
			$this->required_ids = $this->chapter_ids;
			return;
		}

		// 如果都不符合以上條件，則回傳空陣列
		$this->required_ids = [];
	}


	/**
	 * 是否可以觸發
	 *
	 * @param int $user_id 使用者 ID
	 * @param int $course_id 課程 ID
	 * @param int $chapter_id 章節 ID
	 * @return bool
	 */
	public function can_trigger( int $user_id, int $course_id, int $chapter_id ): bool {
		return match ($this->trigger_condition) {
			'each' => $this->is_each_trigger_condition_met($user_id, $course_id, $chapter_id),
			'all' => $this->is_all_trigger_condition_met($user_id),
			'qty_greater_than' => $this->is_qty_greater_than_trigger_condition_met($user_id),
			default => false,
			// 就會變任一個都符合的條件
			// 因為所有條件都判斷完了，default 應該 return false!?
		};
	}

	/**
	 * 是否任一達成
	 *
	 * @param int $user_id 使用者 ID
	 * @param int $course_id 課程 ID
	 * @param int $chapter_id 章節 ID
	 * @return bool
	 */
	private function is_each_trigger_condition_met( int $user_id, int $course_id, int $chapter_id ): bool {
		// 判斷是否是課程的時機點
		if ( in_array( $this->trigger_at, $this->course_trigger_at, true) ) {
			// 如果不在指定的課程 id 列表內，也不是選擇全部課程，就不寄送
			return in_array( $course_id, $this->required_ids );
		}

		return in_array( $chapter_id, $this->required_ids );
	}


	/**
	 * 是否全部達成
	 * 使用 array_diff 找出在 $required_ids 中但不在 $current_ids 中的元素
	 * 如果 $required_ids 中的所有元素都在 $current_ids 中存在就回傳 true
	 *
	 * @param int $user_id 使用者 ID
	 * @return bool
	 */
	private function is_all_trigger_condition_met( int $user_id ): bool {
		$current_ids = $this->get_current_ids($user_id);

		$diff = empty(array_diff($this->required_ids, $current_ids));
		return $diff;
	}

	/**
	 * 是否達成指定數量
	 *
	 * @param int $user_id 使用者 ID
	 * @return bool
	 */
	private function is_qty_greater_than_trigger_condition_met( int $user_id ): bool {
		$current_ids = $this->get_current_ids($user_id);

		// 找出相同的 課程 id
		$intersect = array_intersect($this->required_ids, $current_ids);

		return count($intersect) >= $this->qty;
	}


	/**
	 * 取得 current_ids 用來做比較
	 *
	 * @param int $user_id 使用者 ID
	 * @return array<int, int|string> current_ids 陣列
	 */
	private function get_current_ids( int $user_id ): array {
		$current_ids = match ($this->trigger_at) {
			'course_granted' => \get_user_meta($user_id, 'avl_course_ids') ?: [],
			'course_finish' => CourseUtils::get_finished_course_ids($user_id),
			'chapter_finish' => $this->get_metatable_post_ids(
				[
					'user_id'  => $user_id,
					'meta_key' => 'finished_at',
				],
				'chapter'
				),
			'chapter_enter' => $this->get_metatable_post_ids(
				[
					'user_id'  => $user_id,
					'meta_key' => 'first_visit_at',
				],
				'chapter'
				),
			'course_launch' => [], // 開課時只有 each 不需要判斷
			default => [],
		};

		$current_ids = \is_array($current_ids) ? $current_ids : [];

		/** @var array<int, int> */
		// @phpstan-ignore-next-line
		$current_ids = array_values( array_map( 'intval', $current_ids ) );

		return $current_ids;
	}

	/**
	 * 取得 post_ids
	 *
	 * @param array<string, string|int> $where 查詢條件
	 * @param string                    $table_slug 資料表 course |chapter
	 * @return array<string|int> post_ids 陣列
	 */
	private function get_metatable_post_ids( array $where, string $table_slug ): array {

		$class = match ($table_slug) {
			'course' => AVLCourseMeta::class,
			'chapter' => AVLChapterMeta::class,
			default => null,
		};

		if ( $class ) {
			$results = \call_user_func(
				[ $class, 'query' ],
				[
					'post_id',
				],
				$where
				);

			$post_ids = \wp_list_pluck($results, 'post_id');
			return $post_ids;
		}

		return [];
	}
}
