<?php
/**
 * 線性觀看（循序學習模式）核心邏輯工具類
 *
 * 負責解鎖計算、鎖定驗證、鎖定訊息生成。
 * 核心演算法：最遠進度模式 — 找到已完成章節中位置最後面的那一個，
 * 從第一章到該位置的下一個章節全部解鎖。
 */

declare(strict_types=1);

namespace J7\PowerCourse\Utils;

use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Plugin;

/**
 * 線性觀看靜態工具類
 */
final class LinearViewing {

	/**
	 * 課程是否啟用線性觀看
	 *
	 * @param int $course_id 課程（商品）ID.
	 * @return bool
	 */
	public static function is_enabled( int $course_id ): bool {
		$enabled = \get_post_meta( $course_id, 'enable_linear_viewing', true );
		return $enabled === 'yes';
	}

	/**
	 * 計算學員在課程中的解鎖狀態
	 *
	 * 最遠進度模式：
	 * 1. 取得課程所有章節的平攤一維序列
	 * 2. 取得使用者已完成的章節 IDs
	 * 3. 找到已完成章節中在序列中位置最後面的那一個（max_finished_index）
	 * 4. 從第一章到 max_finished_index + 1（含）全部解鎖
	 *
	 * @param int $course_id 課程 ID.
	 * @param int $user_id   使用者 ID.
	 * @return array{unlocked_ids: array<int>, locked_ids: array<int>}
	 */
	public static function get_unlock_status( int $course_id, int $user_id ): array {
		$flatten_ids = ChapterUtils::get_flatten_post_ids( $course_id );

		if ( empty( $flatten_ids ) ) {
			return [
				'unlocked_ids' => [],
				'locked_ids'   => [],
			];
		}

		// 取得已完成的章節 IDs
		$finished_ids = self::get_finished_chapter_ids( $course_id, $user_id );

		// 找到最遠已完成位置
		$max_finished_index = -1;
		foreach ( $finished_ids as $finished_id ) {
			$index = array_search( $finished_id, $flatten_ids, true );
			if ( false !== $index && $index > $max_finished_index ) {
				$max_finished_index = $index;
			}
		}

		// 解鎖到 max_finished_index + 1（含），至少解鎖第一章（index 0）
		$unlock_up_to = $max_finished_index + 1;

		$unlocked_ids = array_slice( $flatten_ids, 0, $unlock_up_to + 1 );
		$locked_ids   = array_slice( $flatten_ids, $unlock_up_to + 1 );

		return [
			'unlocked_ids' => array_values( $unlocked_ids ),
			'locked_ids'   => array_values( $locked_ids ),
		];
	}

	/**
	 * 判斷特定章節是否對學員鎖定
	 *
	 * @param int $chapter_id 章節 ID.
	 * @param int $course_id  課程 ID.
	 * @param int $user_id    使用者 ID.
	 * @return bool
	 */
	public static function is_chapter_locked( int $chapter_id, int $course_id, int $user_id ): bool {
		$status = self::get_unlock_status( $course_id, $user_id );
		return in_array( $chapter_id, $status['locked_ids'], true );
	}

	/**
	 * 取得學員當前應觀看的章節 ID
	 *
	 * 回傳 unlocked_ids 的最後一個元素（即第一個未完成但已解鎖的章節）。
	 * 若所有章節都已解鎖，回傳最後一個章節。
	 * 若課程無章節，回傳 null。
	 *
	 * @param int $course_id 課程 ID.
	 * @param int $user_id   使用者 ID.
	 * @return int|null
	 */
	public static function get_current_chapter_id( int $course_id, int $user_id ): ?int {
		$status = self::get_unlock_status( $course_id, $user_id );

		if ( empty( $status['unlocked_ids'] ) ) {
			// 沒有章節
			$flatten_ids = ChapterUtils::get_flatten_post_ids( $course_id );
			return $flatten_ids[0] ?? null;
		}

		// 回傳解鎖清單的最後一個章節（即當前進度的最前沿）
		return (int) end( $status['unlocked_ids'] );
	}

	/**
	 * 取得鎖定提示文字（指名需完成的章節）
	 *
	 * @param int $chapter_id 被鎖定的章節 ID.
	 * @param int $course_id  課程 ID.
	 * @param int $user_id    使用者 ID.
	 * @return string
	 */
	public static function get_lock_message( int $chapter_id, int $course_id, int $user_id ): string {
		$status       = self::get_unlock_status( $course_id, $user_id );
		$unlocked_ids = $status['unlocked_ids'];

		if ( empty( $unlocked_ids ) ) {
			return esc_html__( 'Please complete the previous chapters first to view this chapter', 'power-course' );
		}

		// 找到 unlocked 的最後一個章節（即需要先完成的章節）
		$prev_chapter_id = (int) end( $unlocked_ids );
		$prev_title      = \get_the_title( $prev_chapter_id );

		return sprintf(
			/* translators: %s: 前一章節名稱 */
			esc_html__( 'Please complete "%s" first to view this chapter', 'power-course' ),
			$prev_title
		);
	}

	/**
	 * 是否免除線性觀看限制（管理員）
	 *
	 * 使用 current_user_can 而非 is_admin_preview()，
	 * 因為管理員即使是學員也應免除限制。
	 *
	 * @param int $user_id 使用者 ID（未使用，改用 current_user_can）.
	 * @return bool
	 */
	public static function is_exempt( int $user_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return \current_user_can( 'manage_woocommerce' );
	}

	/**
	 * 取得使用者在指定課程中已完成的章節 IDs
	 *
	 * @param int $course_id 課程 ID.
	 * @param int $user_id   使用者 ID.
	 * @return array<int>
	 */
	private static function get_finished_chapter_ids( int $course_id, int $user_id ): array {
		global $wpdb;

		$table_name   = $wpdb->prefix . Plugin::CHAPTER_TABLE_NAME;
		$flatten_ids  = ChapterUtils::get_flatten_post_ids( $course_id );

		if ( empty( $flatten_ids ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $flatten_ids ), '%d' ) );

		$sql = $wpdb->prepare(
			"SELECT DISTINCT post_id FROM {$table_name} WHERE user_id = %d AND meta_key = 'finished_at' AND post_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			array_merge( [ $user_id ], $flatten_ids )
		);

		$results = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', $results );
	}
}
