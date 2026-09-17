<?php
/**
 * Assignment 生命週期連動（Issue #131，DEC-06）
 *
 * 對應 specs/features/chapter/刪除章節.feature「章節作業連動」與 specs/features/assignment/刪除作業.feature：
 *   - pc_chapter 非 trash → trash：該章節的 publish 作業移至垃圾桶（繳交保留）；
 *     子章節由 Course\LifeCycle 逐一 wp_trash_post，各自再觸發本 hook
 *   - pc_chapter trash → 非 trash：該章節的 trash 作業還原為 publish。
 *     用 transition_post_status 而非 untrash_post，才能同時涵蓋 wp_untrash_post 與
 *     課程還原時 Course\LifeCycle 直接 wp_update_post 改回 publish 的路徑（R-10）
 *   - pc_chapter before_delete_post：該章節所有作業（任何狀態）強制刪除。
 *     wp_delete_post 只會處理同 post_type 的子文章，不處理會留下孤兒作業（R-11）
 *   - pc_assignment before_delete_post：刪除該作業的討論訊息與繳交；附件列與實體檔案保留
 *   - wp_untrash_post_status：pc_assignment 自垃圾桶還原一律回到 publish（WP 5.6+ 預設為 draft）
 *
 * 連動失敗只記 log，不擋章節 / 作業本身的操作（計劃 §7）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Service\MessageRepository;
use J7\PowerCourse\Resources\Assignment\Service\SubmissionRepository;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;

/**
 * Class LifeCycle
 */
final class LifeCycle {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		// 章節進出垃圾桶時連動作業
		\add_action( 'transition_post_status', [ __CLASS__, 'sync_assignments_on_chapter_transition' ], 10, 3 );

		// 章節永久刪除前，強制刪除其作業
		\add_action( 'before_delete_post', [ __CLASS__, 'delete_assignments_before_chapter_deleted' ], 10, 1 );

		// 作業永久刪除前，刪除其討論訊息與繳交
		\add_action( 'before_delete_post', [ __CLASS__, 'delete_submissions_before_assignment_deleted' ], 10, 1 );

		// 作業自垃圾桶還原時回到 publish
		\add_filter( 'wp_untrash_post_status', [ __CLASS__, 'restore_assignment_as_publish' ], 10, 2 );
	}

	/**
	 * 章節狀態轉換時連動其作業
	 *
	 * @param string $new_status 新狀態
	 * @param string $old_status 舊狀態
	 * @param mixed  $post       文章物件（WP_Post）
	 * @return void
	 */
	public static function sync_assignments_on_chapter_transition( string $new_status, string $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || ChapterCPT::POST_TYPE !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		$chapter_id = (int) $post->ID;

		// 非 trash → trash：publish 作業移至垃圾桶（繳交與訊息保留）
		if ( 'trash' === $new_status ) {
			self::cascade_chapter_assignments(
				$chapter_id,
				'publish',
				'trash_assignment',
				static fn( int $assignment_id ) => \wp_trash_post( $assignment_id )
			);
			return;
		}

		// trash → 非 trash：trash 作業還原（還原狀態由 restore_assignment_as_publish 固定為 publish）
		if ( 'trash' === $old_status ) {
			self::cascade_chapter_assignments(
				$chapter_id,
				'trash',
				'untrash_assignment',
				static fn( int $assignment_id ) => \wp_untrash_post( $assignment_id )
			);
		}
	}

	/**
	 * 章節永久刪除前，強制刪除該章節所有作業（任何狀態）
	 *
	 * 每份作業的 wp_delete_post 會再觸發 delete_submissions_before_assignment_deleted 清掉繳交與訊息。
	 *
	 * @param int $post_id 即將被刪除的 post ID
	 * @return void
	 */
	public static function delete_assignments_before_chapter_deleted( int $post_id ): void {
		if ( ChapterCPT::POST_TYPE !== \get_post_type( $post_id ) ) {
			return;
		}

		self::cascade_chapter_assignments(
			$post_id,
			null,
			'delete_assignment',
			static fn( int $assignment_id ) => \wp_delete_post( $assignment_id, true )
		);
	}

	/**
	 * 作業永久刪除前，刪除該作業的討論訊息與繳交；附件列與實體檔案保留
	 *
	 * @param int $post_id 即將被刪除的 post ID
	 * @return void
	 */
	public static function delete_submissions_before_assignment_deleted( int $post_id ): void {
		if ( CPT::POST_TYPE !== \get_post_type( $post_id ) ) {
			return;
		}

		self::run_cascade(
			'delete_submissions',
			[ 'assignment_id' => $post_id ],
			static function () use ( $post_id ): bool {
				$submission_ids = SubmissionRepository::get_ids_by_assignment( $post_id );
				MessageRepository::delete_by_submission_ids( $submission_ids );
				SubmissionRepository::delete_by_assignment( $post_id );
				return true;
			}
		);
	}

	/**
	 * 作業自垃圾桶還原時一律回到 publish
	 *
	 * WP 5.6+ 的 wp_untrash_post() 預設還原為 draft，但作業沒有草稿狀態。
	 *
	 * @param string $new_status 預設還原狀態
	 * @param int    $post_id    還原的 post ID
	 * @return string
	 */
	public static function restore_assignment_as_publish( string $new_status, int $post_id ): string {
		if ( CPT::POST_TYPE !== \get_post_type( $post_id ) ) {
			return $new_status;
		}

		return 'publish';
	}

	/**
	 * 對章節底下（指定狀態）的每份作業執行一個連動步驟，各份作業失敗互不影響
	 *
	 * 作業 ID 在迴圈開始前一次取出，步驟本身改變作業狀態不影響本輪清單。
	 *
	 * @param int                  $chapter_id  章節 ID
	 * @param string|null          $post_status 只處理此狀態的作業；null = 任何狀態
	 * @param string               $step        步驟名稱（寫進 log）
	 * @param callable(int): mixed $operation   對單份作業執行的操作，參數為作業 ID；回傳 falsy 或 WP_Error 視為失敗
	 * @return void
	 */
	private static function cascade_chapter_assignments( int $chapter_id, ?string $post_status, string $step, callable $operation ): void {
		foreach ( self::get_assignment_ids( $chapter_id, $post_status ) as $assignment_id ) {
			self::run_cascade(
				$step,
				[
					'chapter_id'    => $chapter_id,
					'assignment_id' => $assignment_id,
				],
				static fn() => $operation( $assignment_id )
			);
		}
	}

	/**
	 * 取得章節底下的作業 ID
	 *
	 * 直接查 wp_posts（不經 WP_Query）：需要涵蓋任何狀態（含 trash / auto-draft），且不受物件快取影響。
	 *
	 * @param int         $chapter_id  章節 ID
	 * @param string|null $post_status 指定狀態；null = 任何狀態
	 * @return array<int, int>
	 */
	private static function get_assignment_ids( int $chapter_id, ?string $post_status = null ): array {
		if ( $chapter_id <= 0 ) {
			return [];
		}

		global $wpdb;

		if ( null === $post_status ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d ORDER BY ID ASC",
					CPT::POST_TYPE,
					$chapter_id
				)
			);
		} else {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = %s ORDER BY ID ASC",
					CPT::POST_TYPE,
					$chapter_id,
					$post_status
				)
			);
		}

		return \array_map( 'intval', $ids );
	}

	/**
	 * 執行一個連動步驟；回傳值為假或拋出例外時只記 log，不中斷外層操作（計劃 §7）
	 *
	 * @param string               $step     步驟名稱（寫進 log）
	 * @param array<string, mixed> $context  log context
	 * @param callable             $callback 連動步驟；回傳 falsy 值或 WP_Error 視為失敗
	 * @return void
	 */
	private static function run_cascade( string $step, array $context, callable $callback ): void {
		try {
			$result = $callback();
			if ( ! $result || \is_wp_error( $result ) ) {
				Plugin::logger( "Assignment cascade step failed: {$step}", 'error', $context );
			}
		} catch ( \Throwable $th ) {
			$context['error'] = $th->getMessage();
			Plugin::logger( "Assignment cascade step failed: {$step}", 'error', $context );
		}
	}
}
