<?php
/**
 * Teacher\Core\ExtendQuery
 * 拓展 Powerhouse 的 WP_User_Query，支援講師管理的特殊查詢與 computed field。
 */

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Teacher\Core;

use J7\Powerhouse\Domains\Post\Service\MetaQueryBuilder;

/**
 * 拓展 User Query
 *
 * 支援：
 * 1. is_teacher=!yes → 非講師查詢（既有）
 * 2. teacher_course_id=<course_id> → 反查該課程的講師（新增）
 * 3. teacher_courses_count / teacher_students_count computed field（新增，
 *    透過 powerhouse/user/get_meta_keys_array filter 附加）
 */
final class ExtendQuery {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string meta_query 中用於反查課程講師的偽 key */
	private const TEACHER_COURSE_ID_CLAUSE = 'teacher_course_id';

	/** @var string Computed field：負責課程數 */
	private const META_KEY_COURSES_COUNT = 'teacher_courses_count';

	/** @var string Computed field：學員人數 */
	private const META_KEY_STUDENTS_COUNT = 'teacher_students_count';

	/** Constructor */
	public function __construct() {
		\add_filter( 'powerhouse/user/prepare_query_args/meta_query_builder', [ $this, 'extend_query_args' ], 30 );
		\add_action( 'pre_get_users', [ $this, 'resolve_teacher_course_id_to_include' ], 10 );
		\add_filter( 'powerhouse/user/get_meta_keys_array', [ $this, 'extend_meta_keys' ], 10, 2 );
	}

	/**
	 * 拓展 User Query 的 meta_query
	 *
	 * 目前處理兩件事：
	 * 1. is_teacher=!yes：轉成 OR（!=yes, NOT EXISTS），查詢非講師用戶
	 * 2. teacher_course_id=X：不在這邊處理（因為需要改 include，不是 meta_query）
	 *    — 由 resolve_teacher_course_id_to_include() 在 pre_get_users 處理
	 *    — 但需先從 meta_query 移除此 clause，避免被當成真的 user_meta 查詢
	 *
	 * @param MetaQueryBuilder $builder 查詢參數建構器
	 * @return MetaQueryBuilder
	 */
	public function extend_query_args( MetaQueryBuilder $builder ): MetaQueryBuilder {

		// 處理 is_teacher=!yes → 非講師查詢
		$clause = $builder->find( 'is_teacher' );
		if ( $clause && '!yes' === $clause->value ) {
			$builder
				->remove( 'is_teacher' )
				->add(
					[
						'key'     => 'is_teacher',
						'value'   => 'yes',
						'compare' => '!=',
					]
				)
				->add(
					[
						'key'     => 'is_teacher',
						'compare' => 'NOT EXISTS',
					]
				);
			$builder->relation = 'OR';
		}

		// 移除 teacher_course_id 偽 clause（由 pre_get_users 處理）
		if ( $builder->find( self::TEACHER_COURSE_ID_CLAUSE ) ) {
			$builder->remove( self::TEACHER_COURSE_ID_CLAUSE );
		}

		return $builder;
	}

	/**
	 * 解析 teacher_course_id 查詢 → 反查課程講師 → 設定 include
	 *
	 * 流程：
	 * 1. 從 REST request query var 偵測 teacher_course_id
	 * 2. 若有，查該課程 post meta 'teacher_ids' 的所有值（多筆 meta 格式）
	 * 3. 將查到的 user_id 陣列設進 $query->query_vars['include']
	 *
	 * 注意：因 Powerhouse prepare_query_args 只暴露 MetaQueryBuilder，
	 * 我們改從 $_GET 直接讀取 teacher_course_id 參數（REST nonce 由 WP 處理）。
	 *
	 * @param \WP_User_Query $query WP_User_Query instance
	 * @return void
	 */
	public function resolve_teacher_course_id_to_include( \WP_User_Query $query ): void {
		// REST 請求下 $_GET 已含 query params；nonce 由 WP REST API 處理
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['teacher_course_id'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_course_id = \sanitize_text_field( \wp_unslash( (string) $_GET['teacher_course_id'] ) );

		$course_id = \absint( $raw_course_id );
		if ( ! $course_id ) {
			return;
		}

		try {
			$teacher_ids = $this->get_teacher_ids_by_course( $course_id );
		} catch ( \Throwable $th ) {
			$this->log_warning( 'resolve_teacher_course_id_to_include failed', $th );
			return;
		}

		if ( empty( $teacher_ids ) ) {
			// 找不到講師 → 設 include 為不存在的 ID，保證回傳空
			$query->query_vars['include'] = [ 0 ];
			return;
		}

		// 若既有 include 有值，取交集；否則直接設
		/** @var array<int|string> $existing_include */
		$existing_include = (array) ( $query->query_vars['include'] ?? [] );
		if ( ! empty( $existing_include ) ) {
			$existing_ints                = array_map( static fn( $id ): int => (int) $id, $existing_include );
			$query->query_vars['include'] = array_values( array_intersect( $existing_ints, $teacher_ids ) );
		} else {
			$query->query_vars['include'] = $teacher_ids;
		}
	}

	/**
	 * 附加講師 computed field 到 meta_keys_array
	 *
	 * 由 Powerhouse CRUD::get_meta_keys_array 的 filter 觸發；
	 * 僅在 meta_keys_array 有 teacher_courses_count / teacher_students_count
	 * 兩個 key（但值為空，因為 user_meta 沒真正存）時，才運算並填入。
	 *
	 * @param array<string, mixed> $meta_keys_array 已有的 meta key-value 陣列
	 * @param \WP_User             $user            被查詢的用戶
	 * @return array<string, mixed>
	 */
	public function extend_meta_keys( array $meta_keys_array, \WP_User $user ): array {

		if ( array_key_exists( self::META_KEY_COURSES_COUNT, $meta_keys_array ) ) {
			$meta_keys_array[ self::META_KEY_COURSES_COUNT ] = $this->safe_count_courses( (int) $user->ID );
		}

		if ( array_key_exists( self::META_KEY_STUDENTS_COUNT, $meta_keys_array ) ) {
			$meta_keys_array[ self::META_KEY_STUDENTS_COUNT ] = $this->safe_count_students( (int) $user->ID );
		}

		return $meta_keys_array;
	}

	/**
	 * 安全計算講師負責課程數（異常 fallback 0）
	 *
	 * @param int $user_id 講師 user_id
	 * @return int
	 */
	private function safe_count_courses( int $user_id ): int {
		try {
			return count( $this->get_course_ids_by_teacher( $user_id ) );
		} catch ( \Throwable $th ) {
			$this->log_warning( 'safe_count_courses failed', $th );
			return 0;
		}
	}

	/**
	 * 安全計算講師班級學員人數（去重跨課程；異常 fallback 0）
	 *
	 * @param int $user_id 講師 user_id
	 * @return int
	 */
	private function safe_count_students( int $user_id ): int {
		try {
			$course_ids = $this->get_course_ids_by_teacher( $user_id );
			if ( empty( $course_ids ) ) {
				return 0;
			}

			return $this->count_distinct_students_across_courses( $course_ids );
		} catch ( \Throwable $th ) {
			$this->log_warning( 'safe_count_students failed', $th );
			return 0;
		}
	}

	/**
	 * 查課程講師列表（post meta 'teacher_ids' 多筆 meta）
	 *
	 * @param int $course_id 課程 ID
	 * @return array<int> 講師 user_id 陣列
	 */
	private function get_teacher_ids_by_course( int $course_id ): array {
		$raw = \get_post_meta( $course_id, 'teacher_ids', false );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		/** @var array<int> $ids */
		$ids = array_values(
			array_filter(
				array_map( static fn( $value ): int => is_scalar( $value ) ? \absint( $value ) : 0, $raw ),
				static fn( int $id ): bool => $id > 0
			)
		);

		return $ids;
	}

	/**
	 * 查講師負責的課程 ID 列表
	 *
	 * 查詢 postmeta 表：meta_key='teacher_ids' AND meta_value=<user_id>
	 * post 須為 publish 狀態且為 WC 商品（課程定義：product + _is_course=yes meta）
	 *
	 * @param int $user_id 講師 user_id
	 * @return array<int> 課程 ID 陣列
	 */
	private function get_course_ids_by_teacher( int $user_id ): array {
		global $wpdb;

		/** @var array<string>|null $rows */
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				INNER JOIN {$wpdb->postmeta} pm_course ON pm_course.post_id = pm.post_id
				WHERE pm.meta_key = %s
				AND pm.meta_value = %s
				AND pm_course.meta_key = %s
				AND pm_course.meta_value = %s
				AND p.post_status = %s
				AND p.post_type = %s",
				'teacher_ids',
				(string) $user_id,
				'_is_course',
				'yes',
				'publish',
				'product'
			)
		);

		if ( ! is_array( $rows ) ) {
			return [];
		}

		/** @var array<int> $ids */
		$ids = array_values(
			array_filter(
				array_map( static fn( $row ): int => (int) $row, $rows ),
				static fn( int $id ): bool => $id > 0
			)
		);

		return $ids;
	}

	/**
	 * 計算多個課程的去重學員人數
	 *
	 * 使用 WC usermeta 的 avl_course_ids（每個課程一筆 meta）做反查。
	 *
	 * @param array<int> $course_ids 課程 ID 陣列
	 * @return int
	 */
	private function count_distinct_students_across_courses( array $course_ids ): int {
		global $wpdb;

		if ( empty( $course_ids ) ) {
			return 0;
		}

		// course_ids 來自內部 get_course_ids_by_teacher()（已 int 過濾），
		// 用 placeholder 再確保一次 SQL 安全。
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$sql     = "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
			WHERE meta_key = %s AND meta_value IN ({$placeholders})";
		$prepared = $wpdb->prepare( $sql, array_merge( [ 'avl_course_ids' ], array_map( 'strval', $course_ids ) ) );
		/** @var string|null $count */
		$count = $wpdb->get_var( $prepared );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * 記錄警告（WP_DEBUG 時才 log）
	 *
	 * @param string     $message 錯誤訊息
	 * @param \Throwable $th      例外
	 * @return void
	 */
	private function log_warning( string $message, \Throwable $th ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( sprintf( '[power-course/Teacher/ExtendQuery] %s: %s', $message, $th->getMessage() ) );
	}
}
