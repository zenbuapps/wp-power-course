<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\ChapterProgress\Service;

use J7\PowerCourse\Resources\ChapterProgress\Model\ChapterProgress;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\Powerhouse\Domains\Post\Utils as PostUtils;

/**
 * 章節續播進度業務服務層
 * 封裝 video_type 白名單、<5s 略過、四捨五入、last_visit_info 同步等業務規則
 */
final class Service {

	/** 最小寫入秒數（未達此值靜默略過，回傳 written: false） */
	const MIN_WRITE_SECONDS = 5;

	/** 允許記錄的影片類型白名單 */
	const ALLOWED_VIDEO_TYPES = [ 'bunny', 'youtube', 'vimeo' ];

	/**
	 * 取得指定用戶在指定章節的播放進度
	 *
	 * @param int $user_id    用戶 ID
	 * @param int $chapter_id 章節 ID
	 * @return array{chapter_id:int,course_id:int,last_position_seconds:int,updated_at:string|null}
	 */
	public static function get_progress( int $user_id, int $chapter_id ): array {
		$record = Repository::find( $user_id, $chapter_id );

		if ( null === $record ) {
			$course_id = (int) PostUtils::get_top_post_id( $chapter_id );
			return [
				'chapter_id'            => $chapter_id,
				'course_id'             => $course_id,
				'last_position_seconds' => 0,
				'updated_at'            => null,
			];
		}

		return [
			'chapter_id'            => $record->chapter_id,
			'course_id'             => $record->course_id,
			'last_position_seconds' => $record->last_position_seconds,
			'updated_at'            => $record->updated_at,
		];
	}

	/**
	 * 寫入章節播放進度
	 * 業務規則：
	 *   1. 取得章節 video_type，不在白名單 → 拋 InvalidArgumentException（API 層回 400）
	 *   2. 秒數 < MIN_WRITE_SECONDS → 回 written:false，不寫 DB
	 *   3. 四捨五入為整數
	 *   4. server 端由 PostUtils::get_top_post_id 計算 course_id
	 *   5. 同步更新 AVLCourseMeta.last_visit_info
	 *
	 * @param int   $user_id     用戶 ID
	 * @param int   $chapter_id  章節 ID
	 * @param float $raw_seconds 前端傳入的秒數（float）
	 * @return array{written:bool,chapter_id:int,course_id:int,last_position_seconds:int,updated_at:string|null}
	 * @throws \InvalidArgumentException 當 video_type 不在白名單時
	 */
	public static function upsert_progress( int $user_id, int $chapter_id, float $raw_seconds ): array {
		// 取得章節 video_type 並做白名單檢查
		$chapter_video = \get_post_meta( $chapter_id, 'chapter_video', true );
		$video_type    = is_array( $chapter_video ) ? ( $chapter_video['type'] ?? 'none' ) : 'none';

		if ( ! in_array( $video_type, self::ALLOWED_VIDEO_TYPES, true ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Video type "%s" is not allowed for progress tracking.', (string) $video_type )
			);
		}

		// server 端計算 course_id（不信任前端）
		$course_id = (int) PostUtils::get_top_post_id( $chapter_id );

		// <5s 靜默略過
		if ( $raw_seconds < self::MIN_WRITE_SECONDS ) {
			return [
				'written'               => false,
				'chapter_id'            => $chapter_id,
				'course_id'             => $course_id,
				'last_position_seconds' => (int) round( $raw_seconds ),
				'updated_at'            => null,
			];
		}

		// 四捨五入為整數
		$position_seconds = (int) round( $raw_seconds );

		// 寫入 DB
		Repository::upsert( $user_id, $chapter_id, $course_id, $position_seconds );

		// 同步更新 course 層 last_visit_info（chapter_id 指標）
		self::sync_last_visit_info( $user_id, $chapter_id, $course_id );

		// 回讀以取得 updated_at（由 DB NOW() 寫入）
		$record = Repository::find( $user_id, $chapter_id );

		return [
			'written'               => true,
			'chapter_id'            => $chapter_id,
			'course_id'             => $course_id,
			'last_position_seconds' => $position_seconds,
			'updated_at'            => $record?->updated_at,
		];
	}

	/**
	 * 刪除指定用戶在指定課程的所有進度紀錄（退課時呼叫）
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 * @return void
	 */
	public static function delete_all_for_user_in_course( int $user_id, int $course_id ): void {
		Repository::delete_by_course_user( $user_id, $course_id );
	}

	/**
	 * 同步更新 AVLCourseMeta 的 last_visit_info
	 *
	 * @param int $user_id    用戶 ID
	 * @param int $chapter_id 章節 ID
	 * @param int $course_id  課程 ID
	 * @return void
	 */
	private static function sync_last_visit_info( int $user_id, int $chapter_id, int $course_id ): void {
		$existing = AVLCourseMeta::get( $course_id, $user_id, 'last_visit_info', true );
		$existing = is_array( $existing ) ? $existing : [];

		$meta_value = array_merge(
			$existing,
			[
				'chapter_id'    => $chapter_id,
				'last_visit_at' => \wp_date( 'Y-m-d H:i:s' ),
			]
		);

		AVLCourseMeta::update( $course_id, $user_id, 'last_visit_info', $meta_value );
	}
}
