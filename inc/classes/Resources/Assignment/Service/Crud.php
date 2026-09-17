<?php
/**
 * Assignment 建立 / 更新 / 刪除 / 排序服務（Issue #131）
 *
 * 對應 specs/features/assignment/{建立作業,更新作業,刪除作業,排序作業}.feature。
 * 業務行為以 zenbu-site-2.0 course-assignments.service.ts 為準；
 * 寫入 wp_insert_post / wp_update_post（含 meta_input）一律 wp_slash（slash 對稱規則）。
 *
 * 欄位驗證只有一份：create() / update() 共用 validate_fields()，
 * 正規化後的欄位再經 to_postarr() 轉成 wp_posts 原生欄位與 meta_input。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Resources\Assignment\Core\CPT;
use J7\PowerCourse\Resources\Assignment\Model\Assignment;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class Crud
 */
final class Crud {

	/** 題型欄位（值為 yes / no） */
	private const YES_NO_FIELDS = [ 'allow_text', 'allow_file', 'required_text', 'required_file' ];

	/** 對應 wp_posts 原生欄位的作業欄位；其餘欄位存 postmeta */
	private const POST_FIELD_MAP = [
		'title'        => 'post_title',
		'instructions' => 'post_content',
		'menu_order'   => 'menu_order',
	];

	/** 標題（title）長度上限，以 sanitize 後的字數計 */
	private const TITLE_MAX_LENGTH = 200;

	/** 作業說明（instructions）長度上限 */
	private const INSTRUCTIONS_MAX_LENGTH = 20000;

	/** 附件數量上限（max_files）的最小值 */
	private const MAX_FILES_MIN = 1;

	/** 附件數量上限（max_files）的最大值 */
	private const MAX_FILES_MAX = 20;

	/** 截止時間（due_at）非 0 時的最小值：最小的 10 位 Unix timestamp */
	private const DUE_AT_MIN = 1000000000;

	/** 截止時間（due_at）非 0 時的最大值：最大的 10 位 Unix timestamp */
	private const DUE_AT_MAX = 9999999999;

	/** 排序一次最多幾筆 */
	private const SORT_MAX_IDS = 200;

	/** 排序寫入 menu_order 的間距：(index + 1) × 10 */
	private const SORT_MENU_ORDER_STEP = 10;

	/**
	 * 建立作業（post_status=publish）
	 *
	 * 驗證順序：chapter_id 必填 → 欄位格式 → 繳交方式旗標 → 章節存在。
	 *
	 * @param array<string, mixed> $args chapter_id*, title*, instructions, allow_text, allow_file,
	 *                                   required_text, required_file, max_files, due_at, menu_order
	 * @return Assignment 新建的作業
	 * @throws AssignmentException 參數不合法（400）、章節不存在（404）、寫入失敗（500）。
	 */
	public static function create( array $args ): Assignment {
		$chapter_id = self::require_chapter_id( $args );

		// 建立時 title 必填：未提供視同空字串，交給長度驗證
		if ( ! \array_key_exists( 'title', $args ) ) {
			$args['title'] = '';
		}

		$fields = \array_merge( Assignment::DEFAULTS, self::validate_fields( $args ) );
		self::assert_submission_types( (string) $fields['allow_text'], (string) $fields['allow_file'] );

		$postarr = [
			'post_type'   => CPT::POST_TYPE,
			'post_status' => 'publish',
		] + self::to_postarr( $fields, $chapter_id );

		$post_id = \wp_insert_post( \wp_slash( $postarr ), true );
		if ( \is_wp_error( $post_id ) ) {
			throw AssignmentException::internal( $post_id->get_error_message() );
		}

		return Query::get( (int) $post_id );
	}

	/**
	 * 部分更新作業（只變更有提供的欄位）
	 *
	 * 驗證順序：欄位格式 → 作業存在 → 合併後的繳交方式旗標 → 章節存在（有提供 chapter_id 時）。
	 *
	 * @param int                  $id   作業 ID
	 * @param array<string, mixed> $args 同 create()，皆選填
	 * @return Assignment 更新後的作業
	 * @throws AssignmentException 參數不合法（400）、作業或章節不存在（404）、寫入失敗（500）。
	 */
	public static function update( int $id, array $args ): Assignment {
		$fields  = self::validate_fields( $args );
		$current = Query::get( $id );

		self::assert_submission_types(
			(string) ( $fields['allow_text'] ?? $current->allow_text ),
			(string) ( $fields['allow_file'] ?? $current->allow_file )
		);

		// 有提供 chapter_id 才改掛章節；格式不合法視同不存在的章節（404）
		$chapter_id = \array_key_exists( 'chapter_id', $args ) ? ( self::to_int( $args['chapter_id'] ) ?? 0 ) : null;
		$postarr    = [ 'ID' => $current->id ] + self::to_postarr( $fields, $chapter_id );

		$result = \wp_update_post( \wp_slash( $postarr ), true );
		if ( \is_wp_error( $result ) ) {
			throw AssignmentException::internal( $result->get_error_message() );
		}

		return Query::get( $current->id );
	}

	/**
	 * 刪除作業（移至垃圾桶，繳交與討論訊息保留）
	 *
	 * @param int $id 作業 ID
	 * @return void
	 * @throws AssignmentException 作業不存在（404）、移至垃圾桶失敗（500）。
	 */
	public static function trash( int $id ): void {
		$current = Query::get( $id );

		if ( ! \wp_trash_post( $current->id ) ) {
			throw AssignmentException::internal( \__( 'Failed to move assignment to trash', 'power-course' ) );
		}
	}

	/**
	 * 排序章節內作業：依 ids 先後以 (index + 1) × 10 寫入 menu_order
	 *
	 * 驗證順序：chapter_id 必填 → ids ≤ 200 → 空陣列直接回列表 → ids 皆存在且不重複 → 皆屬於該章節。
	 * 全部驗證通過後才寫入。
	 *
	 * @param array<string, mixed> $args chapter_id*, ids（未提供視同空陣列）
	 * @return array<int, Assignment> 該章節排序後的作業列表
	 * @throws AssignmentException 參數不合法（400）、部分作業不存在（404）、寫入失敗（500）。
	 */
	public static function sort( array $args ): array {
		$chapter_id = self::require_chapter_id( $args );
		$raw_ids    = self::get_sort_ids( $args );

		if ( ! $raw_ids ) {
			return Query::list_by_chapter( $chapter_id );
		}

		$assignments = self::get_sort_assignments( $raw_ids, $chapter_id );
		self::write_menu_orders( \array_keys( $assignments ) );

		return Query::list_by_chapter( $chapter_id );
	}

	/**
	 * 驗證並正規化有提供的欄位（只處理 $args 中存在的 key）
	 *
	 * 驗證順序：title → instructions → yes / no 欄位 → max_files → due_at → menu_order，
	 * 遇到第一個不合法的欄位即拋出。
	 *
	 * @param array<string, mixed> $args 原始參數
	 * @return array<string, string|int> 正規化後的欄位
	 * @throws AssignmentException 欄位格式不合法（400）。
	 */
	private static function validate_fields( array $args ): array {
		$fields = [];

		if ( \array_key_exists( 'title', $args ) ) {
			$fields['title'] = self::validate_title( $args['title'] );
		}

		if ( \array_key_exists( 'instructions', $args ) ) {
			$fields['instructions'] = self::validate_instructions( $args['instructions'] );
		}

		foreach ( self::YES_NO_FIELDS as $key ) {
			if ( \array_key_exists( $key, $args ) ) {
				$fields[ $key ] = self::to_yes_no( $args[ $key ] );
			}
		}

		if ( \array_key_exists( 'max_files', $args ) ) {
			$fields['max_files'] = self::validate_max_files( $args['max_files'] );
		}

		if ( \array_key_exists( 'due_at', $args ) ) {
			$fields['due_at'] = self::validate_due_at( $args['due_at'] );
		}

		if ( \array_key_exists( 'menu_order', $args ) ) {
			$fields['menu_order'] = self::validate_menu_order( $args['menu_order'] );
		}

		return $fields;
	}

	/**
	 * 驗證 title：先 sanitize_text_field 再驗長度（1 至 200 字）
	 *
	 * @param mixed $value 原始值
	 * @return string sanitize 後的標題
	 * @throws AssignmentException 長度不合法（400）。
	 */
	private static function validate_title( mixed $value ): string {
		$title  = \is_scalar( $value ) ? \sanitize_text_field( (string) $value ) : '';
		$length = \mb_strlen( $title );
		if ( $length < 1 || $length > self::TITLE_MAX_LENGTH ) {
			throw AssignmentException::invalid_param( \__( 'title must be between 1 and 200 characters', 'power-course' ) );
		}

		return $title;
	}

	/**
	 * 驗證 instructions：null 視同空字串；保留原始 HTML，由 WordPress 依 unfiltered_html 權限決定是否 kses
	 *
	 * @param mixed $value 原始值
	 * @return string 作業說明
	 * @throws AssignmentException 非字串或超過 20000 字（400）。
	 */
	private static function validate_instructions( mixed $value ): string {
		$instructions = null === $value ? '' : $value;
		if ( ! \is_string( $instructions ) || \mb_strlen( $instructions ) > self::INSTRUCTIONS_MAX_LENGTH ) {
			throw AssignmentException::invalid_param( \__( 'instructions must not exceed 20000 characters', 'power-course' ) );
		}

		return $instructions;
	}

	/**
	 * 正規化 yes / no 欄位：沿用 WooCommerce 的布林字串慣例（yes / true / 1 / true 布林 → yes，其餘 → no）
	 *
	 * @param mixed $value 原始值
	 * @return string yes | no
	 */
	private static function to_yes_no( mixed $value ): string {
		return match ( true ) {
			\is_bool( $value )   => \wc_bool_to_string( $value ),
			\is_scalar( $value ) => \wc_bool_to_string( (string) $value ),
			default              => 'no',
		};
	}

	/**
	 * 驗證 max_files：1 至 20 的整數
	 *
	 * @param mixed $value 原始值
	 * @return int 附件數量上限
	 * @throws AssignmentException 非整數或超出範圍（400）。
	 */
	private static function validate_max_files( mixed $value ): int {
		$max_files = self::to_int( $value );
		if ( null === $max_files || $max_files < self::MAX_FILES_MIN || $max_files > self::MAX_FILES_MAX ) {
			throw AssignmentException::invalid_param( \__( 'max_files must be an integer between 1 and 20', 'power-course' ) );
		}

		return $max_files;
	}

	/**
	 * 驗證 due_at：0（無截止）或 10 位 Unix timestamp
	 *
	 * @param mixed $value 原始值
	 * @return int 截止時間
	 * @throws AssignmentException 非整數、非 0 且不是 10 位（400）。
	 */
	private static function validate_due_at( mixed $value ): int {
		$due_at = self::to_int( $value );
		if ( null === $due_at || ( 0 !== $due_at && ( $due_at < self::DUE_AT_MIN || $due_at > self::DUE_AT_MAX ) ) ) {
			throw AssignmentException::invalid_param( \__( 'due_at must be 0 or a 10-digit Unix timestamp', 'power-course' ) );
		}

		return $due_at;
	}

	/**
	 * 驗證 menu_order：大於等於 0 的整數
	 *
	 * @param mixed $value 原始值
	 * @return int 排序鍵
	 * @throws AssignmentException 非整數或為負數（400）。
	 */
	private static function validate_menu_order( mixed $value ): int {
		$menu_order = self::to_int( $value );
		if ( null === $menu_order || $menu_order < 0 ) {
			throw AssignmentException::invalid_param( \__( 'menu_order must be an integer greater than or equal to 0', 'power-course' ) );
		}

		return $menu_order;
	}

	/**
	 * 至少需允許文字或檔案其中一種繳交方式
	 *
	 * @param string $allow_text 合併後的 allow_text
	 * @param string $allow_file 合併後的 allow_file
	 * @return void
	 * @throws AssignmentException 兩者皆為 no（400）。
	 */
	private static function assert_submission_types( string $allow_text, string $allow_file ): void {
		if ( 'yes' !== $allow_text && 'yes' !== $allow_file ) {
			throw AssignmentException::invalid_param( \__( 'At least one submission type (text or file) must be allowed', 'power-course' ) );
		}
	}

	/**
	 * 將正規化欄位轉為 wp_insert_post / wp_update_post 參數
	 *
	 * 欄位 title / instructions / menu_order 寫入 wp_posts 原生欄位，其餘欄位放進 meta_input；
	 * 有指定章節時一併寫入 post_parent 與 parent_course_id。
	 *
	 * @param array<string, string|int> $fields     validate_fields() 正規化後的欄位
	 * @param int|null                  $chapter_id 要掛載的章節 ID；null = 不變更所屬章節
	 * @return array<string, mixed> 尚未 wp_slash 的 postarr（不含 ID / post_type / post_status）
	 * @throws AssignmentException 章節不存在（404）。
	 */
	private static function to_postarr( array $fields, ?int $chapter_id ): array {
		$postarr    = [];
		$meta_input = [];

		if ( null !== $chapter_id ) {
			$meta_input['parent_course_id'] = self::resolve_course_id( $chapter_id );
			$postarr['post_parent']         = $chapter_id;
		}

		foreach ( $fields as $key => $value ) {
			if ( isset( self::POST_FIELD_MAP[ $key ] ) ) {
				$postarr[ self::POST_FIELD_MAP[ $key ] ] = $value;
				continue;
			}
			$meta_input[ $key ] = $value;
		}

		if ( $meta_input ) {
			$postarr['meta_input'] = $meta_input;
		}

		return $postarr;
	}

	/**
	 * 取得必填的 chapter_id（建立與排序共用）
	 *
	 * @param array<string, mixed> $args 原始參數
	 * @return int 章節 ID（正整數）
	 * @throws AssignmentException 未提供或不是正整數（400）。
	 */
	private static function require_chapter_id( array $args ): int {
		$chapter_id = self::to_int( $args['chapter_id'] ?? null );
		if ( null === $chapter_id || $chapter_id <= 0 ) {
			throw AssignmentException::invalid_param( \__( 'chapter_id is required', 'power-course' ) );
		}

		return $chapter_id;
	}

	/**
	 * 取得排序用的 ids：未提供視同空陣列、非陣列視為單筆，並檢查筆數上限
	 *
	 * @param array<string, mixed> $args 原始參數
	 * @return array<int, mixed> 尚未轉型的 ids（重新索引）
	 * @throws AssignmentException 超過 200 筆（400）。
	 */
	private static function get_sort_ids( array $args ): array {
		$raw_ids = $args['ids'] ?? [];
		if ( ! \is_array( $raw_ids ) ) {
			$raw_ids = [ $raw_ids ];
		}

		if ( \count( $raw_ids ) > self::SORT_MAX_IDS ) {
			throw AssignmentException::invalid_param( \__( 'ids must not exceed 200 items', 'power-course' ) );
		}

		return \array_values( $raw_ids );
	}

	/**
	 * 載入要排序的作業，並確認全部存在、不重複且屬於指定章節
	 *
	 * 先檢查全部 ids 存在且不重複，再檢查章節歸屬（兩種錯誤同時存在時回 404）。
	 *
	 * @param array<int, mixed> $raw_ids    get_sort_ids() 的結果（非空）
	 * @param int               $chapter_id 章節 ID
	 * @return array<int, Assignment> 以作業 ID 為 key，順序同 ids
	 * @throws AssignmentException 部分作業不存在或重複（404）、不屬於指定章節（400）。
	 */
	private static function get_sort_assignments( array $raw_ids, int $chapter_id ): array {
		$assignments = [];
		foreach ( $raw_ids as $raw_id ) {
			$id         = self::to_int( $raw_id ) ?? 0;
			$assignment = Query::find( $id );
			if ( null === $assignment || isset( $assignments[ $id ] ) ) {
				throw AssignmentException::not_found( \__( 'Some assignments do not exist', 'power-course' ) );
			}
			$assignments[ $id ] = $assignment;
		}

		foreach ( $assignments as $assignment ) {
			if ( $assignment->chapter_id !== $chapter_id ) {
				throw AssignmentException::invalid_param( \__( 'Assignments do not belong to the specified chapter', 'power-course' ) );
			}
		}

		return $assignments;
	}

	/**
	 * 依先後以 (index + 1) × 10 寫入 menu_order；任一筆失敗即中止
	 *
	 * 逐筆 wp_update_post（內部會 clean_post_cache）；只寫整數欄位，不需 wp_slash。
	 *
	 * @param array<int, int> $ids 已驗證的作業 ID，依排序先後
	 * @return void
	 * @throws AssignmentException 寫入失敗（500）。
	 */
	private static function write_menu_orders( array $ids ): void {
		foreach ( \array_values( $ids ) as $index => $id ) {
			$result = \wp_update_post(
				[
					'ID'         => $id,
					'menu_order' => ( $index + 1 ) * self::SORT_MENU_ORDER_STEP,
				],
				true
			);
			if ( \is_wp_error( $result ) ) {
				throw AssignmentException::internal( $result->get_error_message() );
			}
		}
	}

	/**
	 * 確認章節存在並解析其根課程（DEC-18）
	 *
	 * 章節存在 = post_type 為 pc_chapter、不在垃圾桶，且能解析出所屬課程。
	 *
	 * @param int $chapter_id 章節 ID
	 * @return int 根課程 ID
	 * @throws AssignmentException 章節不存在（404）。
	 */
	private static function resolve_course_id( int $chapter_id ): int {
		$chapter = $chapter_id > 0 ? \get_post( $chapter_id ) : null;
		if ( ! $chapter instanceof \WP_Post || ChapterCPT::POST_TYPE !== $chapter->post_type || 'trash' === $chapter->post_status ) {
			throw AssignmentException::not_found( \__( 'Chapter not found', 'power-course' ) );
		}

		$course_id = ChapterUtils::get_course_id( $chapter_id );
		if ( ! $course_id ) {
			throw AssignmentException::not_found( \__( 'Chapter not found', 'power-course' ) );
		}

		return $course_id;
	}

	/**
	 * 將輸入值轉為整數（接受 int 與純整數字串，字串前後空白會被忽略）
	 *
	 * @param mixed $value 輸入值
	 * @return int|null 無法視為整數時回傳 null
	 */
	private static function to_int( mixed $value ): ?int {
		if ( \is_int( $value ) ) {
			return $value;
		}
		if ( \is_string( $value ) && 1 === \preg_match( '/^-?\d+$/', \trim( $value ) ) ) {
			return (int) \trim( $value );
		}

		return null;
	}
}
