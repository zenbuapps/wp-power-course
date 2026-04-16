<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\ChapterProgress\Model;

/**
 * 章節續播進度資料模型
 * 對應 pc_chapter_progress 資料表
 */
final class ChapterProgress {

	/**
	 * Constructor
	 *
	 * @param int         $id                   主鍵
	 * @param int         $user_id              用戶 ID
	 * @param int         $chapter_id           章節 ID
	 * @param int         $course_id            課程 ID（denormalized）
	 * @param int         $last_position_seconds 最後播放秒數（整數）
	 * @param string|null $updated_at           最後更新時間
	 * @param string|null $created_at           建立時間
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $chapter_id,
		public readonly int $course_id,
		public readonly int $last_position_seconds,
		public readonly ?string $updated_at,
		public readonly ?string $created_at
	) {}

	/**
	 * 從資料庫列物件建立 Model 實例
	 *
	 * @param object $row 資料庫列物件
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id: (int) ( $row->id ?? 0 ),
			user_id: (int) ( $row->user_id ?? 0 ),
			chapter_id: (int) ( $row->chapter_id ?? 0 ),
			course_id: (int) ( $row->course_id ?? 0 ),
			last_position_seconds: (int) ( $row->last_position_seconds ?? 0 ),
			updated_at: isset( $row->updated_at ) ? (string) $row->updated_at : null,
			created_at: isset( $row->created_at ) ? (string) $row->created_at : null
		);
	}
}
