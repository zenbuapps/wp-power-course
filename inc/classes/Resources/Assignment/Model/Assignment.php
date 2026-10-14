<?php
/**
 * Assignment Model（Issue #131）
 *
 * 章節作業 CPT（pc_assignment）的 DTO，封裝 wp_posts + wp_postmeta。
 * to_array() / to_list() 是作業 API 回應的唯一輸出格式，Api / Service 不得自行組陣列。
 * 注意：與講師指派的 Teacher Service 無關（R-01）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Model;

use J7\PowerCourse\Resources\Assignment\Core\CPT;

/**
 * Class Assignment
 *
 * 欄位對照（specs/entity/erm.dbml TABLE assignments）：
 *   - chapter_id：wp_posts.post_parent（pc_chapter，任意層級）
 *   - course_id：postmeta parent_course_id
 *   - title / instructions：wp_posts.post_title / post_content
 *   - allow_text / allow_file / required_text / required_file / max_files / due_at：postmeta
 *   - menu_order / post_status：wp_posts 原生欄位
 */
final class Assignment {

	/** 題型設定預設值（建立作業時未提供的欄位套用此值） */
	public const DEFAULTS = [
		'instructions'  => '',
		'allow_text'    => 'yes',
		'allow_file'    => 'no',
		'required_text' => 'no',
		'required_file' => 'no',
		'max_files'     => 1,
		'due_at'        => 0,
		'menu_order'    => 0,
	];

	/**
	 * Constructor
	 *
	 * @param int    $id            作業 post ID
	 * @param int    $chapter_id    所屬章節 ID
	 * @param int    $course_id     所屬章節的根課程 ID
	 * @param string $title         作業標題
	 * @param string $instructions  作業說明（HTML）
	 * @param string $allow_text    允許文字繳交 yes | no
	 * @param string $allow_file    允許檔案繳交 yes | no
	 * @param string $required_text 文字必填 yes | no
	 * @param string $required_file 檔案必填 yes | no
	 * @param int    $max_files     附件數量上限
	 * @param int    $due_at        截止時間 10 位 timestamp，0 = 無截止
	 * @param int    $menu_order    同章節排序鍵
	 * @param string $post_status   文章狀態
	 * @param string $created_at    建立時間（站台時區）
	 * @param string $updated_at    更新時間（站台時區）
	 */
	public function __construct(
		public int $id,
		public int $chapter_id,
		public int $course_id,
		public string $title,
		public string $instructions,
		public string $allow_text,
		public string $allow_file,
		public string $required_text,
		public string $required_file,
		public int $max_files,
		public int $due_at,
		public int $menu_order,
		public string $post_status,
		public string $created_at,
		public string $updated_at
	) {}

	/**
	 * 以 WP_Post 建立 Model
	 *
	 * @param \WP_Post $post pc_assignment post
	 * @return self
	 */
	public static function from_post( \WP_Post $post ): self {
		$id = (int) $post->ID;

		return new self(
			id: $id,
			chapter_id: (int) $post->post_parent,
			course_id: (int) \get_post_meta( $id, 'parent_course_id', true ),
			title: (string) $post->post_title,
			instructions: (string) $post->post_content,
			allow_text: self::get_yes_no_meta( $id, 'allow_text' ),
			allow_file: self::get_yes_no_meta( $id, 'allow_file' ),
			required_text: self::get_yes_no_meta( $id, 'required_text' ),
			required_file: self::get_yes_no_meta( $id, 'required_file' ),
			max_files: self::get_int_meta( $id, 'max_files' ),
			due_at: self::get_int_meta( $id, 'due_at' ),
			menu_order: (int) $post->menu_order,
			post_status: (string) $post->post_status,
			created_at: (string) $post->post_date,
			updated_at: (string) $post->post_modified
		);
	}

	/**
	 * 以 post ID 載入 Model（不限 post_status）
	 *
	 * @param int $id 作業 post ID
	 * @return self|null 找不到或非 pc_assignment 時回傳 null
	 */
	public static function instance( int $id ): ?self {
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		return self::from_post( $post );
	}

	/**
	 * 輸出為 API 回應格式（對齊 specs/api/api.yml components.schemas.Assignment）
	 *
	 * @return array{id: int, chapter_id: int, course_id: int, title: string, instructions: string, allow_text: string, allow_file: string, required_text: string, required_file: string, max_files: int, due_at: int, menu_order: int, created_at: string, updated_at: string}
	 */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'chapter_id'    => $this->chapter_id,
			'course_id'     => $this->course_id,
			'title'         => $this->title,
			'instructions'  => $this->instructions,
			'allow_text'    => $this->allow_text,
			'allow_file'    => $this->allow_file,
			'required_text' => $this->required_text,
			'required_file' => $this->required_file,
			'max_files'     => $this->max_files,
			'due_at'        => $this->due_at,
			'menu_order'    => $this->menu_order,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
		];
	}

	/**
	 * Model 陣列輸出為 API 回應列表（逐筆 to_array()，保留原順序）
	 *
	 * @param array<int, self> $assignments 作業 Model 陣列
	 * @return array<int, array<string, mixed>>
	 */
	public static function to_list( array $assignments ): array {
		return \array_map(
			static fn( self $assignment ): array => $assignment->to_array(),
			$assignments
		);
	}

	/**
	 * 讀取 yes / no postmeta，缺值時回傳預設值
	 *
	 * @param int    $id  作業 post ID
	 * @param string $key meta key（allow_text / allow_file / required_text / required_file）
	 * @return string yes | no
	 */
	private static function get_yes_no_meta( int $id, string $key ): string {
		$value = \get_post_meta( $id, $key, true );
		if ( 'yes' === $value || 'no' === $value ) {
			return $value;
		}

		return (string) self::DEFAULTS[ $key ];
	}

	/**
	 * 讀取整數 postmeta，缺值時回傳預設值
	 *
	 * @param int    $id  作業 post ID
	 * @param string $key meta key（max_files / due_at）
	 * @return int
	 */
	private static function get_int_meta( int $id, string $key ): int {
		$value = \get_post_meta( $id, $key, true );
		if ( \is_numeric( $value ) ) {
			return (int) $value;
		}

		return (int) self::DEFAULTS[ $key ];
	}
}
