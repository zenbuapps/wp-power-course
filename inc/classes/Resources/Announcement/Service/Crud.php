<?php
/**
 * Announcement Service Crud
 *
 * 公告的 Create / Update / Delete / Restore 業務邏輯，供 REST callback 使用。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Service;

use J7\PowerCourse\Resources\Announcement\Core\CPT;
use J7\PowerCourse\Resources\Announcement\Utils\Utils;
use J7\Powerhouse\Utils\Base as PowerhouseBase;

/**
 * Announcement CRUD Service
 *
 * 職責：封裝公告建立、更新、刪除（軟刪除/還原）邏輯
 */
final class Crud {

	/**
	 * 建立單一公告
	 *
	 * 必要參數：post_title、parent_course_id
	 * 選用參數：post_content、post_status、post_date、visibility、end_at
	 *
	 * @param array<string, mixed> $data 公告主體資料
	 * @param array<string, mixed> $meta 額外的 meta 資料（visibility / end_at 已在 data 處理）
	 *
	 * @return int 新建公告 ID
	 * @throws \RuntimeException 當參數不合法或建立失敗時拋出
	 */
	public static function create( array $data, array $meta = [] ): int {
		$post_title       = trim( (string) ( $data['post_title'] ?? '' ) );
		$parent_course_id = isset( $data['parent_course_id'] ) ? (int) $data['parent_course_id'] : 0;
		$post_content     = (string) ( $data['post_content'] ?? '' );
		$post_status      = (string) ( $data['post_status'] ?? 'publish' );
		$post_date        = isset( $data['post_date'] ) && '' !== $data['post_date']
		? (string) $data['post_date']
		: \wp_date( 'Y-m-d H:i:s' );
		$visibility       = isset( $data['visibility'] ) && '' !== $data['visibility']
		? (string) $data['visibility']
		: Utils::VISIBILITY_PUBLIC;
		$end_at           = $data['end_at'] ?? '';

		// === 前置（參數）驗證 ===
		if ( '' === $post_title ) {
			throw new \RuntimeException( 'post_title 不可為空' );
		}
		if ( $parent_course_id <= 0 ) {
			throw new \RuntimeException( 'parent_course_id 不可為空' );
		}
		if ( ! Utils::is_valid_course( $parent_course_id ) ) {
			throw new \RuntimeException( 'parent_course_id 對應的課程不存在或不是課程商品' );
		}
		if ( ! Utils::is_valid_visibility( $visibility ) ) {
			throw new \RuntimeException( 'visibility 必須為 public 或 enrolled' );
		}
		if ( ! Utils::is_valid_end_at( $end_at ) ) {
			throw new \RuntimeException( 'end_at 必須為 10 位 Unix timestamp' );
		}

		// === end_at 須晚於 post_date ===
		if ( '' !== $end_at && null !== $end_at ) {
			$start_ts = (int) strtotime( $post_date );
			if ( (int) $end_at <= $start_ts ) {
				throw new \RuntimeException( 'end_at 必須晚於 post_date' );
			}
		}

		// === post_status 與 post_date 一致性自動修正 ===
		[ $post_status, $post_date ] = self::normalize_status_and_date( $post_status, $post_date );

		// === meta 整合 ===
		$meta_input = array_merge(
			$meta,
			[
				'parent_course_id' => $parent_course_id,
				'visibility'       => $visibility,
				'end_at'           => '' === $end_at ? '' : (int) $end_at,
				'editor'           => $meta['editor'] ?? 'power-editor',
			]
		);

		$insert_args = [
			'post_type'    => CPT::POST_TYPE,
			'post_title'   => $post_title,
			'post_content' => $post_content,
			'post_status'  => $post_status,
			'post_date'    => $post_date,
			'post_parent'  => $parent_course_id,
			'post_author'  => \get_current_user_id() ?: ( $data['post_author'] ?? 0 ),
			'meta_input'   => $meta_input,
		];

		$result = \wp_insert_post( $insert_args, true );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException( '建立公告失敗：' . $result->get_error_message() );
		}
		if ( ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( '建立公告失敗：回傳值不合法' );
		}
		return $result;
	}

	/**
	 * 更新單一公告
	 *
	 * @param int                  $announcement_id 公告 ID
	 * @param array<string, mixed> $data            更新欄位
	 * @param array<string, mixed> $meta            額外的 meta 資料
	 *
	 * @return int 被更新的公告 ID
	 * @throws \RuntimeException 當公告不存在、參數不合法或更新失敗時拋出
	 */
	public static function update( int $announcement_id, array $data = [], array $meta = [] ): int {
		if ( $announcement_id <= 0 ) {
			throw new \RuntimeException( 'id 不可為空' );
		}
		$post = \get_post( $announcement_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== CPT::POST_TYPE ) {
			throw new \RuntimeException( '公告不存在' );
		}

		// === 參數驗證 ===
		if ( array_key_exists( 'visibility', $data ) ) {
			if ( ! Utils::is_valid_visibility( $data['visibility'] ) ) {
				throw new \RuntimeException( 'visibility 必須為 public 或 enrolled' );
			}
		}
		if ( array_key_exists( 'end_at', $data ) ) {
			if ( ! Utils::is_valid_end_at( $data['end_at'] ) ) {
				throw new \RuntimeException( 'end_at 必須為 10 位 Unix timestamp' );
			}
			$end_at_value = $data['end_at'];
			if ( '' !== $end_at_value && null !== $end_at_value ) {
				$post_date = (string) ( $data['post_date'] ?? $post->post_date );
				$start_ts  = (int) strtotime( $post_date );
				if ( (int) $end_at_value <= $start_ts ) {
					throw new \RuntimeException( 'end_at 必須晚於 post_date' );
				}
			}
		}
		if ( array_key_exists( 'parent_course_id', $data ) ) {
			$parent_course_id = (int) $data['parent_course_id'];
			if ( $parent_course_id > 0 && ! Utils::is_valid_course( $parent_course_id ) ) {
				throw new \RuntimeException( 'parent_course_id 對應的課程不存在或不是課程商品' );
			}
		}

		// === post_status 與 post_date 一致性 ===
		$update_args = [ 'ID' => $announcement_id ];
		$post_status = isset( $data['post_status'] ) ? (string) $data['post_status'] : $post->post_status;
		$post_date   = isset( $data['post_date'] ) && '' !== $data['post_date']
		? (string) $data['post_date']
		: $post->post_date;

		if ( isset( $data['post_status'] ) || isset( $data['post_date'] ) ) {
			[ $post_status, $post_date ] = self::normalize_status_and_date( $post_status, $post_date );
			$update_args['post_status']  = $post_status;
			$update_args['post_date']    = $post_date;
			// 同步 GMT 時間欄位（避免 WP_Query 比較失誤）
			$update_args['post_date_gmt'] = \get_gmt_from_date( $post_date );
		}

		foreach ( [ 'post_title', 'post_content', 'post_parent' ] as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$update_args[ $key ] = $data[ $key ];
			}
		}

		// === 收集 meta input ===
		$meta_input = $meta;
		foreach ( [ 'visibility', 'end_at', 'parent_course_id' ] as $meta_key ) {
			if ( array_key_exists( $meta_key, $data ) ) {
				$meta_input[ $meta_key ] = $data[ $meta_key ];
			}
		}
		if ( ! empty( $meta_input ) ) {
			$update_args['meta_input'] = $meta_input;
		}

		$result = \wp_update_post( $update_args, true );

		if ( \is_wp_error( $result ) ) {
			throw new \RuntimeException( '更新公告失敗：' . $result->get_error_message() );
		}
		if ( ! is_int( $result ) || $result <= 0 ) {
			throw new \RuntimeException( '更新公告失敗：回傳值不合法' );
		}
		return $result;
	}

	/**
	 * 刪除（軟刪除）單一公告
	 *
	 * 已 trash 的公告再次刪除視為冪等成功（避免 wp_trash_post 對 trash post 回 false 誤判）。
	 *
	 * @param int  $announcement_id 公告 ID
	 * @param bool $force           是否永久刪除（true 直接 wp_delete_post，false 走 wp_trash_post）
	 *
	 * @return bool 是否成功
	 * @throws \RuntimeException 當公告不存在時拋出
	 */
	public static function delete( int $announcement_id, bool $force = false ): bool {
		if ( $announcement_id <= 0 ) {
			throw new \RuntimeException( 'id 不可為空' );
		}
		$post = \get_post( $announcement_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== CPT::POST_TYPE ) {
			throw new \RuntimeException( '公告不存在' );
		}

		if ( $force ) {
			$result = \wp_delete_post( $announcement_id, true );
			return false !== $result && null !== $result;
		}

		// 已 trash 視為成功（冪等）
		if ( 'trash' === $post->post_status ) {
			return true;
		}
		$result = \wp_trash_post( $announcement_id );
		return (bool) $result;
	}

	/**
	 * 批次刪除公告
	 *
	 * @param array<int|string> $ids   公告 ID 陣列
	 * @param bool              $force 是否永久刪除
	 *
	 * @return array{success: array<int>, failed: array<int>}
	 * @throws \RuntimeException 當 ids 為空陣列時拋出
	 */
	public static function delete_many( array $ids, bool $force = false ): array {
		if ( empty( $ids ) ) {
			throw new \RuntimeException( 'ids 不可為空陣列' );
		}

		$int_ids = array_map( 'intval', $ids );

		$results = PowerhouseBase::batch_process(
			$int_ids,
			static function ( $id ) use ( $force ) {
				$announcement_id = (int) $id;
				if ( $announcement_id <= 0 ) {
					return false;
				}
				$post = \get_post( $announcement_id );
				if ( ! $post instanceof \WP_Post || $post->post_type !== CPT::POST_TYPE ) {
					return false;
				}
				if ( $force ) {
					$res = \wp_delete_post( $announcement_id, true );
					return false !== $res && null !== $res;
				}
				if ( 'trash' === $post->post_status ) {
					return true;
				}
				return (bool) \wp_trash_post( $announcement_id );
			}
		);

		// batch_process 回傳 { total, success, failed, failed_items }
		$failed  = array_map( 'intval', $results['failed_items'] );
		$success = array_values( array_diff( $int_ids, $failed ) );

		return [
			'success' => $success,
			'failed'  => $failed,
		];
	}

	/**
	 * 還原已軟刪除的公告
	 *
	 * 行為：
	 * 1. 呼叫 wp_untrash_post() — WP 內建會讀 `_wp_desired_post_status` meta 還原 trash 前的原狀態
	 *    （透過 `wp_untrash_post_status` filter）。draft → 還原成 draft；publish → 還原成 publish 等。
	 * 2. untrash 完成後，若還原為 publish 但 post_date 已是未來時間，
	 *    透過 normalize_status_and_date() 補正為 future（反之亦然）。
	 *    補正用 wp_update_post() 寫回（內部會自動清 cache）。
	 * 3. 補正階段任何錯誤皆 catch \Throwable，不破壞已成功的 untrash 操作，僅記錄 log。
	 *
	 * @param int $announcement_id 公告 ID
	 * @return bool
	 * @throws \RuntimeException 當公告不存在時拋出
	 */
	public static function restore( int $announcement_id ): bool {
		if ( $announcement_id <= 0 ) {
			throw new \RuntimeException( 'id 不可為空' );
		}
		$post = \get_post( $announcement_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== CPT::POST_TYPE ) {
			throw new \RuntimeException( '公告不存在' );
		}

		// === Step 1：呼叫 wp_untrash_post，由 WP core 透過 _wp_desired_post_status 還原原狀態 ===
		$result = \wp_untrash_post( $announcement_id );
		if ( false === $result || null === $result ) {
			return false;
		}

		// === Step 2：normalize 補正（針對 publish/future 與 post_date 不一致的情境）===
		// 場景 1：原狀態為 publish 但 post_date 已是未來時間 → 應補正為 future
		// 場景 2：原狀態為 future 但 post_date 已過期（trash 期間時間流逝）→ 應補正為 publish
		// draft 等其他狀態：normalize 內部已 early return，不會被改寫。
		try {
			$restored = \get_post( $announcement_id );
			if ( $restored instanceof \WP_Post && in_array( $restored->post_status, [ 'publish', 'future' ], true ) ) {
				[ $new_status, $new_date ] = self::normalize_status_and_date( $restored->post_status, $restored->post_date );
				if ( $new_status !== $restored->post_status ) {
					\wp_update_post(
						[
							'ID'            => $announcement_id,
							'post_status'   => $new_status,
							'post_date'     => $new_date,
							'post_date_gmt' => \get_gmt_from_date( $new_date ),
						]
					);
				}
			}
		} catch ( \Throwable $e ) {
			// 補正階段失敗不影響 untrash 已成功的事實，記 log 後繼續回傳 success。
			\J7\WpUtils\Classes\WC::logger(
				'restore() normalize 補正失敗 id=' . $announcement_id . ' error=' . $e->getMessage(),
				'error'
			);
		}

		return true;
	}

	/**
	 * 修正 post_status 與 post_date 的一致性
	 *
	 * 規則：
	 * - post_status=draft → 一律 early return（draft 不受時間影響）
	 * - post_status=publish 但 post_date 為未來時間 → 改為 future
	 * - post_status=future 但 post_date 為過去時間 → 改為 publish
	 * - 其他 status（pending / private / trash / inherit）→ 原樣返回
	 *
	 * @param string $post_status 文章狀態
	 * @param string $post_date   發佈時間（Y-m-d H:i:s）
	 *
	 * @return array{0: string, 1: string} [post_status, post_date]
	 */
	private static function normalize_status_and_date( string $post_status, string $post_date ): array {
		// draft 一律不轉（即使 post_date 在過去/未來，皆保留 draft 與原 post_date）
		if ( 'draft' === $post_status ) {
			return [ $post_status, $post_date ];
		}
		if ( ! in_array( $post_status, [ 'publish', 'future' ], true ) ) {
			return [ $post_status, $post_date ];
		}
		$post_ts = (int) strtotime( $post_date );
		if ( $post_ts <= 0 ) {
			return [ $post_status, $post_date ];
		}
		$now = (int) \current_time( 'timestamp' );
		if ( 'publish' === $post_status && $post_ts > $now ) {
			return [ 'future', $post_date ];
		}
		if ( 'future' === $post_status && $post_ts <= $now ) {
			return [ 'publish', $post_date ];
		}
		return [ $post_status, $post_date ];
	}
}
