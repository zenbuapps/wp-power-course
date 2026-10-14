<?php
/**
 * Assignment 管理端查詢服務（Issue #131）
 *
 * 對應 specs/features/assignment/查詢章節作業列表.feature。
 * 管理端一律只看 publish 作業（垃圾桶中的作業視為不存在）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Resources\Assignment\Core\CPT;
use J7\PowerCourse\Resources\Assignment\Model\Assignment;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class Query
 */
final class Query {

	/**
	 * 依查詢參數取得作業列表
	 *
	 * 同時提供 chapter_id 與 course_id 時以 chapter_id 為準。
	 *
	 * @param array<string, mixed> $params 查詢參數（chapter_id / course_id）
	 * @return array<int, Assignment>
	 * @throws AssignmentException 未指定 chapter_id 也未指定 course_id 時（400）。
	 */
	public static function list( array $params ): array {
		$chapter_id = self::to_positive_int( $params['chapter_id'] ?? null );
		if ( null !== $chapter_id ) {
			return self::list_by_chapter( $chapter_id );
		}

		$course_id = self::to_positive_int( $params['course_id'] ?? null );
		if ( null !== $course_id ) {
			return self::list_by_course( $course_id );
		}

		throw AssignmentException::invalid_param( \__( 'Either chapter_id or course_id is required', 'power-course' ) );
	}

	/**
	 * 取得章節的作業，依 menu_order ASC、建立時間 ASC、ID ASC 排序（DEC-13）
	 *
	 * @param int $chapter_id 章節 ID
	 * @return array<int, Assignment>
	 */
	public static function list_by_chapter( int $chapter_id ): array {
		$posts = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => $chapter_id,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'orderby'        => [
					'menu_order' => 'ASC',
					'date'       => 'ASC',
					'ID'         => 'ASC',
				],
			]
		);

		return self::to_models( $posts );
	}

	/**
	 * 取得整門課程的作業（DEC-13）
	 *
	 * 先依所屬章節在 ChapterUtils::get_flatten_post_ids() 的位置排序，
	 * 不在攤平清單中的章節（非 publish）排在最後；同章節內依 menu_order、建立時間、ID。
	 *
	 * @param int $course_id 課程 ID
	 * @return array<int, Assignment>
	 */
	public static function list_by_course( int $course_id ): array {
		$posts = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'   => 'parent_course_id',
						'value' => $course_id,
					],
				],
			]
		);

		$models    = self::to_models( $posts );
		$positions = \array_flip( ChapterUtils::get_flatten_post_ids( $course_id ) );
		$last      = \count( $positions );

		\usort(
			$models,
			static function ( Assignment $a, Assignment $b ) use ( $positions, $last ): int {
				return [
					$positions[ $a->chapter_id ] ?? $last,
					$a->menu_order,
					$a->created_at,
					$a->id,
				] <=> [
					$positions[ $b->chapter_id ] ?? $last,
					$b->menu_order,
					$b->created_at,
					$b->id,
				];
			}
		);

		return $models;
	}

	/**
	 * 查詢單一作業（publish）
	 *
	 * @param int $id 作業 ID
	 * @return Assignment|null 不存在、非 pc_assignment 或不是 publish 時回傳 null
	 */
	public static function find( int $id ): ?Assignment {
		if ( $id <= 0 ) {
			return null;
		}

		$assignment = Assignment::instance( $id );
		if ( null === $assignment || 'publish' !== $assignment->post_status ) {
			return null;
		}

		return $assignment;
	}

	/**
	 * 取得單一作業，不存在（含垃圾桶）時拋出 404
	 *
	 * @param int $id 作業 ID
	 * @return Assignment
	 * @throws AssignmentException 作業不存在（404）。
	 */
	public static function get( int $id ): Assignment {
		$assignment = self::find( $id );
		if ( null === $assignment ) {
			throw AssignmentException::not_found( \__( 'Assignment not found', 'power-course' ) );
		}

		return $assignment;
	}

	/**
	 * WP_Post 陣列轉 Model 陣列
	 *
	 * @param array<int|string, mixed> $posts get_posts() 結果
	 * @return array<int, Assignment>
	 */
	private static function to_models( array $posts ): array {
		$models = [];
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post ) {
				$models[] = Assignment::from_post( $post );
			}
		}

		return $models;
	}

	/**
	 * 將輸入值轉為正整數
	 *
	 * @param mixed $value 輸入值（int 或純數字字串）
	 * @return int|null 非正整數時回傳 null
	 */
	private static function to_positive_int( $value ): ?int {
		if ( \is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( \is_string( $value ) && 1 === \preg_match( '/^\d+$/', $value ) ) {
			$int = (int) $value;
			return $int > 0 ? $int : null;
		}

		return null;
	}
}
