<?php
/**
 * Chapter Service Progress
 *
 * 章節進度（完成狀態）的業務邏輯，供 REST callback、MCP tools
 * 及 Wave 3 的 Student\Service\Progress 共用。
 *
 * ⚠️ Wave 3 依賴：此類別的 public API 簽名為相依項目，
 * 變更前請先通知 Progress agent。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Service;

use J7\PowerCourse\Resources\Chapter\Core\LifeCycle as ChapterLifeCycle;
use J7\PowerCourse\Resources\Chapter\Utils\MetaCRUD as AVLChapterMeta;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Chapter 進度 Service
 *
 * 負責章節完成 / 取消完成的狀態切換。
 */
final class Progress {

	/**
	 * 將章節標記為已完成
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $user_id    用戶 ID
	 *
	 * @return bool 是否標記成功
	 * @throws \RuntimeException 當找不到課程時拋出
	 */
	public static function mark_finished( int $chapter_id, int $user_id ): bool {
		$course_id = self::resolve_course_id( $chapter_id );
		$product   = \wc_get_product( $course_id );

		if ( ! $product instanceof \WC_Product ) {
			throw new \RuntimeException( "找不到章節 {$chapter_id} 所屬的課程" );
		}

		\wp_cache_delete( "pid_{$product->get_id()}_uid_{$user_id}", 'pc_course_progress' );

		$success = AVLChapterMeta::add(
			$chapter_id,
			$user_id,
			'finished_at',
			\wp_date( 'Y-m-d H:i:s' )
		);

		// 保留舊 callback 行為：無論 success 為何，均觸發 finished action
		\do_action(
			ChapterLifeCycle::CHAPTER_FINISHED_ACTION,
			$chapter_id,
			$course_id,
			$user_id
		);

		return (bool) $success;
	}

	/**
	 * 將章節取消完成狀態
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $user_id    用戶 ID
	 *
	 * @return bool 是否取消成功
	 * @throws \RuntimeException 當找不到課程時拋出
	 */
	public static function mark_unfinished( int $chapter_id, int $user_id ): bool {
		$course_id = self::resolve_course_id( $chapter_id );
		$product   = \wc_get_product( $course_id );

		if ( ! $product instanceof \WC_Product ) {
			throw new \RuntimeException( "找不到章節 {$chapter_id} 所屬的課程" );
		}

		\wp_cache_delete( "pid_{$product->get_id()}_uid_{$user_id}", 'pc_course_progress' );

		$success = AVLChapterMeta::delete(
			$chapter_id,
			$user_id,
			'finished_at'
		);

		// 保留舊 callback 行為：無論 success 為何，均觸發 unfinished action
		\do_action(
			ChapterLifeCycle::CHAPTER_UNFINISHEDED_ACTION,
			$chapter_id,
			$course_id,
			$user_id
		);

		return (bool) $success;
	}

	/**
	 * 切換章節完成狀態
	 *
	 * 根據 $is_finished 呼叫 mark_finished 或 mark_unfinished。
	 *
	 * @param int  $chapter_id  章節 ID
	 * @param int  $user_id     用戶 ID
	 * @param bool $is_finished 目標狀態（true=已完成）
	 *
	 * @return bool 是否切換成功
	 * @throws \RuntimeException 當找不到課程時拋出
	 */
	public static function toggle_finish( int $chapter_id, int $user_id, bool $is_finished ): bool {
		return $is_finished
		? self::mark_finished( $chapter_id, $user_id )
		: self::mark_unfinished( $chapter_id, $user_id );
	}

	/**
	 * 判斷章節目前是否已被某用戶標記為完成
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $user_id    用戶 ID
	 *
	 * @return bool
	 */
	public static function is_finished( int $chapter_id, int $user_id ): bool {
		$finished_at = AVLChapterMeta::get( $chapter_id, $user_id, 'finished_at', true );
		return ! empty( $finished_at );
	}

	/**
	 * 找出章節所屬的 course_id
	 *
	 * @param int $chapter_id 章節 ID
	 *
	 * @return int 課程 ID
	 * @throws \RuntimeException 當找不到課程關聯時拋出
	 */
	private static function resolve_course_id( int $chapter_id ): int {
		$course_id = ChapterUtils::get_course_id( $chapter_id );

		if ( ! $course_id ) {
			throw new \RuntimeException( "章節 {$chapter_id} 沒有關聯到任何課程" );
		}

		return (int) $course_id;
	}
}
