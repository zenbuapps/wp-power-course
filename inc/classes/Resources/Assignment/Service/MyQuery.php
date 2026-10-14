<?php
/**
 * Assignment 學員端查詢服務（Issue #131）
 *
 * 對應 specs/features/assignment/查詢學員作業.feature。
 * 入口一律先驗觀看權；解析不到課程（章節不存在 / 作業不存在 / 在垃圾桶）
 * 一律回「尚未報名此課程」，不洩漏作業是否存在（DEC-12）。
 * 回應形狀為 api.yml MyAssignmentItem：{ assignment, my_submission|null }。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Resources\Assignment\Model\Assignment as AssignmentModel;
use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class MyQuery
 */
final class MyQuery {

	/**
	 * 學員端作業列表（chapter_id 或 course_id 至少一個）
	 *
	 * 同時提供 chapter_id 與 course_id 時以 chapter_id 為準（與管理端 Query::list() 一致）。
	 *
	 * @param array<string, mixed> $params  查詢參數（chapter_id / course_id）
	 * @param int                  $user_id 學員 user ID
	 * @return array<int, array{assignment: array<string, mixed>, my_submission: array<string, mixed>|null}>
	 * @throws AssignmentException 未指定 chapter_id 也未指定 course_id（400）、無觀看權（403）。
	 */
	public static function list( array $params, int $user_id ): array {
		$chapter_id = self::to_positive_int( $params['chapter_id'] ?? null );
		if ( null !== $chapter_id ) {
			AssignmentUtils::assert_course_access( (int) ( ChapterUtils::get_course_id( $chapter_id ) ?? 0 ), $user_id );

			return self::to_items( Query::list_by_chapter( $chapter_id ), $user_id );
		}

		$course_id = self::to_positive_int( $params['course_id'] ?? null );
		if ( null !== $course_id ) {
			AssignmentUtils::assert_course_access( $course_id, $user_id );

			return self::to_items( Query::list_by_course( $course_id ), $user_id );
		}

		throw AssignmentException::invalid_param( \__( 'Either chapter_id or course_id is required', 'power-course' ) );
	}

	/**
	 * 學員端單一作業（含自己的繳交）
	 *
	 * @param int $assignment_id 作業 ID
	 * @param int $user_id       學員 user ID
	 * @return array{assignment: array<string, mixed>, my_submission: array<string, mixed>|null}
	 * @throws AssignmentException 作業不存在 / 在垃圾桶 / 無觀看權時（403 尚未報名此課程，DEC-12）。
	 */
	public static function get( int $assignment_id, int $user_id ): array {
		$assignment = Query::find( $assignment_id );
		if ( null === $assignment ) {
			throw AssignmentException::access_denied( AssignmentUtils::not_enrolled_message() );
		}

		AssignmentUtils::assert_course_access( $assignment->course_id, $user_id );

		$items = self::to_items( [ $assignment ], $user_id );

		return $items[0];
	}

	/**
	 * 作業 Model 陣列 → MyAssignmentItem 陣列（附上學員自己的繳交）
	 *
	 * 繳交與附件檔名各一次查詢查完，不在迴圈內逐筆查。
	 *
	 * @param array<int, AssignmentModel> $assignments 作業 Model 陣列（已排序）
	 * @param int                         $user_id     學員 user ID
	 * @return array<int, array{assignment: array<string, mixed>, my_submission: array<string, mixed>|null}>
	 */
	private static function to_items( array $assignments, int $user_id ): array {
		$assignment_ids = \array_map(
			static fn( AssignmentModel $assignment ): int => $assignment->id,
			$assignments
		);

		$submissions = SubmissionRepository::find_many_by_owner( $assignment_ids, $user_id );
		self::fill_attachment_names( $submissions );

		$items = [];
		foreach ( $assignments as $assignment ) {
			$submission = $submissions[ $assignment->id ] ?? null;

			$items[] = [
				'assignment'    => $assignment->to_array(),
				'my_submission' => $submission?->to_array(),
			];
		}

		return $items;
	}

	/**
	 * 一次補齊所有繳交的附件原始檔名（attachments JSON 可能只存 attachment_id）
	 *
	 * @param array<int, SubmissionModel> $submissions 繳交 Model 陣列
	 */
	private static function fill_attachment_names( array $submissions ): void {
		$attachment_ids = [];
		foreach ( $submissions as $submission ) {
			foreach ( $submission->get_attachment_ids() as $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		if ( ! $attachment_ids ) {
			return;
		}

		$names = AttachmentRepository::get_names( $attachment_ids );
		foreach ( $submissions as $submission ) {
			$submission->fill_attachment_names( $names );
		}
	}

	/**
	 * 將輸入值轉為正整數
	 *
	 * @param mixed $value 輸入值（int 或純數字字串）
	 * @return int|null 非正整數時回傳 null
	 */
	private static function to_positive_int( mixed $value ): ?int {
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
