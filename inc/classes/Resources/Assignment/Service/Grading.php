<?php
/**
 * 作業批改服務（Issue #131 P4）
 *
 * 對應 specs/features/assignment/批改作業.feature（寄信本身留到 P5）與計劃 §6.3：
 *   ADMIN → FIND → VALIDATE → UPDATE → 副作用 A（pass 自動完成章節）→ 副作用 B（pass / fail 觸發 graded action）→ RESPONSE
 *
 * 兩個副作用都包在 catch ( \Throwable ) 內只記 Plugin::logger（D23）：
 * 章節連動或寄信失敗都不得影響批改結果本身。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Assignment as AssignmentModel;
use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;
use J7\PowerCourse\Resources\Chapter\Service\Progress as ChapterProgress;

/**
 * Class Grading
 */
final class Grading {

	/** 批改完成（pass / fail）後觸發的 action，P5 的 PowerEmail 監聽此 action 排信 */
	public const GRADED_ACTION = 'power_course_assignment_graded';

	/** @var array<int, string> 合法的批改狀態 */
	private const ALLOWED_STATUSES = [ 'pending', 'pass', 'fail' ];

	/** @var array<int, string> 會觸發批改通知的狀態（pending 改回待批改不寄送） */
	private const NOTIFIABLE_STATUSES = [ 'pass', 'fail' ];

	/** 評語字數上限 */
	private const MAX_FEEDBACK_LENGTH = 10000;

	/**
	 * 批改單筆繳交
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param array<string, mixed> $args          批改參數（status 必填、feedback 選填）
	 * @param int                  $operator_id   批改者（目前管理員）user ID
	 * @return SubmissionModel 批改後的繳交
	 * @throws AssignmentException 繳交不存在 / 所屬作業在垃圾桶（404）、參數不合法（400）、寫入失敗（500）。
	 */
	public static function grade( int $submission_id, array $args, int $operator_id ): SubmissionModel {
		$submission = SubmissionRepository::find( $submission_id );
		if ( null === $submission ) {
			throw AssignmentException::not_found( self::not_found_message() );
		}

		// 所屬作業在垃圾桶（或已不存在）時，視同繳交紀錄不存在
		$assignment = Query::find( $submission->assignment_id );
		if ( null === $assignment ) {
			throw AssignmentException::not_found( self::not_found_message() );
		}

		$status   = self::validate_status( $args );
		$feedback = self::validate_feedback( $args );
		$now      = (string) \wp_date( 'Y-m-d H:i:s', Clock::now() );

		try {
			SubmissionRepository::update_grade( $submission->id, $status, $feedback, $operator_id, $now );
		} catch ( \Throwable $e ) {
			Plugin::logger( '寫入批改結果失敗：' . $e->getMessage(), 'error', [ 'submission_id' => $submission->id ] );
			throw AssignmentException::internal( \__( 'Failed to save grading result', 'power-course' ) );
		}

		$graded = SubmissionRepository::find( $submission->id );
		if ( null === $graded ) {
			throw AssignmentException::internal( \__( 'Failed to save grading result', 'power-course' ) );
		}

		self::maybe_finish_chapter( $status, $assignment, $graded->user_id );
		self::maybe_notify( $status, $graded, $assignment );

		return $graded;
	}

	/**
	 * 驗證 status（必須為 pending、pass、fail 之一）
	 *
	 * @param array<string, mixed> $args 批改參數
	 * @return string
	 * @throws AssignmentException 參數 status 不合法時（400）。
	 */
	private static function validate_status( array $args ): string {
		$status = \array_key_exists( 'status', $args ) && \is_scalar( $args['status'] ) ? (string) $args['status'] : '';

		if ( ! \in_array( $status, self::ALLOWED_STATUSES, true ) ) {
			throw AssignmentException::invalid_param( \__( 'status must be one of pending, pass, fail', 'power-course' ) );
		}

		return $status;
	}

	/**
	 * 驗證 feedback（未提供或空字串一律清空；不可超過 10000 字）
	 *
	 * 用 array_key_exists 而非 ??：要能區分「沒帶 feedback」與「帶了 null」，兩者都代表清空。
	 *
	 * @param array<string, mixed> $args 批改參數
	 * @return string|null 清空時為 null
	 * @throws AssignmentException 評語過長時（400）。
	 */
	private static function validate_feedback( array $args ): ?string {
		if ( ! \array_key_exists( 'feedback', $args ) || ! \is_scalar( $args['feedback'] ) ) {
			return null;
		}

		$feedback = \trim( (string) $args['feedback'] );
		if ( '' === $feedback ) {
			return null;
		}

		if ( \mb_strlen( $feedback ) > self::MAX_FEEDBACK_LENGTH ) {
			throw AssignmentException::invalid_param( \__( 'feedback must not exceed 10000 characters', 'power-course' ) );
		}

		return $feedback;
	}

	/**
	 * 副作用 A：批改為 pass 且章節尚未完成時，自動標記章節完成（D21 / R-18）
	 *
	 * 先問 is_finished() 再 mark_finished()，避免覆寫既有完成時間與重複觸發
	 * power_course_chapter_finished；改判為 fail / pending 不撤銷完成狀態。
	 * mark_finished() 找不到課程時會丟 RuntimeException，一律在此吞掉只記 log。
	 *
	 * @param string          $status     批改後狀態
	 * @param AssignmentModel $assignment 作業
	 * @param int             $user_id    學員 user ID
	 */
	private static function maybe_finish_chapter( string $status, AssignmentModel $assignment, int $user_id ): void {
		if ( 'pass' !== $status || $assignment->chapter_id <= 0 || $user_id <= 0 ) {
			return;
		}

		try {
			if ( ChapterProgress::is_finished( $assignment->chapter_id, $user_id ) ) {
				return;
			}

			ChapterProgress::mark_finished( $assignment->chapter_id, $user_id );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'批改通過後自動完成章節失敗：' . $e->getMessage(),
				'warning',
				[
					'chapter_id' => $assignment->chapter_id,
					'user_id'    => $user_id,
				]
			);
		}
	}

	/**
	 * 副作用 B：批改為 pass / fail 時觸發 GRADED_ACTION（改回 pending 不觸發）
	 *
	 * P5 的 PowerEmail\...\Trigger\At 監聽此 action 排送批改通知；
	 * 任一 listener 丟例外都不得影響批改結果，故整段 do_action 包 try / catch。
	 *
	 * @param string          $status     批改後狀態
	 * @param SubmissionModel $submission 批改後的繳交
	 * @param AssignmentModel $assignment 作業
	 */
	private static function maybe_notify( string $status, SubmissionModel $submission, AssignmentModel $assignment ): void {
		if ( ! \in_array( $status, self::NOTIFIABLE_STATUSES, true ) ) {
			return;
		}

		try {
			\do_action(
				self::GRADED_ACTION,
				$submission->id,
				$submission->user_id,
				$assignment->id,
				$assignment->course_id,
				$status
			);
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'批改通知副作用失敗：' . $e->getMessage(),
				'warning',
				[
					'submission_id' => $submission->id,
					'status'        => $status,
				]
			);
		}
	}

	/**
	 * 「繳交紀錄不存在」訊息（繳交不存在與所屬作業在垃圾桶共用，不洩漏作業狀態）
	 *
	 * @return string 已翻譯的錯誤訊息
	 */
	private static function not_found_message(): string {
		return \__( 'Submission not found', 'power-course' );
	}
}
