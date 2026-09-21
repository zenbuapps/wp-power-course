<?php
/**
 * 作業繳交 Service（Issue #131）
 *
 * 對應 specs/features/assignment/繳交作業.feature，驗證順序照計劃 §6.1：
 *   登入（permission_callback）→ 作業存在且 publish → 課程觀看權 → 線性觀看章節解鎖
 *   → 至少文字或附件 → allow_text → allow_file → required_text → required_file
 *   → 附件數 ≤ max_files → 文字 ≤ 50000 → 附件存在且為本人上傳 → UPSERT。
 *
 * 作業不存在 / 在垃圾桶一律回「尚未報名此課程」，不洩漏作業是否存在（DEC-12）。
 * 截止時間不阻擋繳交；繳交也不改變章節完成狀態。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Assignment as AssignmentModel;
use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;
use J7\PowerCourse\Utils\LinearViewing;

/**
 * Class Submission
 */
final class Submission {

	/** 文字內容長度上限（字元數） */
	public const MAX_TEXT_LENGTH = 50000;

	/**
	 * 繳交作業（首次建立或重交覆寫）
	 *
	 * @param int                  $assignment_id 作業 ID
	 * @param int                  $user_id       學員 user ID
	 * @param array<string, mixed> $args          request body（text_body、attachment_ids）
	 * @return array<string, mixed> 繳交紀錄（api.yml AssignmentSubmission）
	 * @throws AssignmentException 前置狀態 / 參數不符（400 / 403）或寫入失敗（500）時。
	 */
	public static function submit( int $assignment_id, int $user_id, array $args ): array {
		$assignment = self::resolve_assignment( $assignment_id, $user_id );
		self::assert_chapter_unlocked( $assignment, $user_id );

		$text_body      = self::normalize_text( $args );
		$attachment_ids = self::normalize_attachment_ids( $args );

		self::assert_shape( $assignment, $text_body, $attachment_ids );

		$names       = self::resolve_owned_attachments( $attachment_ids, $user_id );
		$attachments = self::build_attachment_refs( $attachment_ids, $names );

		$submission = self::persist( $assignment_id, $user_id, $text_body, $attachments );
		$submission->fill_attachment_names( $names );

		return $submission->to_array();
	}

	/**
	 * 寫入繳交（UPSERT）並讀回寫入結果
	 *
	 * @param int                                                           $assignment_id 作業 ID
	 * @param int                                                           $user_id       學員 user ID
	 * @param string                                                        $text_body     去除前後空白後的文字內容（空字串代表未填，寫入 NULL）
	 * @param array<int, array{attachment_id: int, file_name: string|null}> $attachments   附件參照
	 * @return SubmissionModel 寫入後的繳交
	 * @throws AssignmentException 寫入或讀回失敗時（500）。
	 */
	private static function persist( int $assignment_id, int $user_id, string $text_body, array $attachments ): SubmissionModel {
		$now = (string) \wp_date( 'Y-m-d H:i:s', Clock::now() );

		try {
			SubmissionRepository::upsert( $assignment_id, $user_id, '' === $text_body ? null : $text_body, $attachments, $now );
		} catch ( \RuntimeException $e ) {
			Plugin::logger(
				'Assignment submission upsert failed',
				'error',
				[
					'assignment_id' => $assignment_id,
					'user_id'       => $user_id,
					'error'         => $e->getMessage(),
				]
			);
			throw AssignmentException::internal( \__( 'Failed to save submission', 'power-course' ) );
		}

		$submission = SubmissionRepository::find_by_owner( $assignment_id, $user_id );
		if ( null === $submission ) {
			throw AssignmentException::internal( \__( 'Failed to save submission', 'power-course' ) );
		}

		return $submission;
	}

	/**
	 * 取得可繳交的作業：必須存在且為 publish，且學員對其課程有有效觀看權
	 *
	 * @param int $assignment_id 作業 ID
	 * @param int $user_id       學員 user ID
	 * @return AssignmentModel
	 * @throws AssignmentException 作業不存在 / 在垃圾桶 / 無觀看權時（403 尚未報名此課程，DEC-12）。
	 */
	private static function resolve_assignment( int $assignment_id, int $user_id ): AssignmentModel {
		$assignment = Query::find( $assignment_id );
		if ( null === $assignment ) {
			throw AssignmentException::access_denied( AssignmentUtils::not_enrolled_message() );
		}

		AssignmentUtils::assert_course_access( $assignment->course_id, $user_id );

		return $assignment;
	}

	/**
	 * 線性觀看：課程啟用且學員未豁免時，作業所屬章節必須已解鎖
	 *
	 * 沿用既有 LinearViewing 的最遠進度解鎖邏輯與既有鎖定訊息（D27），
	 * 寫法比照 Resources\Chapter\Core\Api::post_toggle_finish_chapters_with_id_callback()。
	 *
	 * @param AssignmentModel $assignment 作業
	 * @param int             $user_id    學員 user ID
	 * @throws AssignmentException 章節尚未解鎖時（403）。
	 */
	private static function assert_chapter_unlocked( AssignmentModel $assignment, int $user_id ): void {
		$course_id = $assignment->course_id;

		if ( ! LinearViewing::is_enabled( $course_id ) ) {
			return;
		}
		if ( LinearViewing::is_exempt( $user_id ) ) {
			return;
		}
		if ( ! LinearViewing::is_chapter_locked( $assignment->chapter_id, $course_id, $user_id ) ) {
			return;
		}

		throw AssignmentException::access_denied(
			\__( 'This chapter is not yet unlocked. Please complete the previous chapters first.', 'power-course' )
		);
	}

	/**
	 * 內容形狀驗證
	 *
	 * 四道守門的呼叫順序即計劃 §6.1 的固定驗證順序，不可調換
	 * （測試斷言的是「第一個不符的條件」對應的錯誤訊息）：
	 *   1. 至少有文字或附件
	 *   2. 題型是否接受該種內容（allow_text / allow_file）
	 *   3. 題型要求的內容是否齊備（required_text / required_file）
	 *   4. 附件數與文字長度上限
	 *
	 * @param AssignmentModel $assignment     作業
	 * @param string          $text_body      去除前後空白後的文字內容
	 * @param array<int, int> $attachment_ids 去重後的附件 ID
	 * @throws AssignmentException 不符題型設定 / 附件上限 / 文字長度時（400）。
	 */
	private static function assert_shape( AssignmentModel $assignment, string $text_body, array $attachment_ids ): void {
		$has_text  = '' !== $text_body;
		$has_files = (bool) $attachment_ids;

		self::assert_has_content( $has_text, $has_files );
		self::assert_content_kinds_allowed( $assignment, $has_text, $has_files );
		self::assert_required_content_present( $assignment, $has_text, $has_files );
		self::assert_within_limits( $assignment, $text_body, $attachment_ids );
	}

	/**
	 * 守門 1：文字與附件不可同時為空
	 *
	 * @param bool $has_text  是否有文字內容
	 * @param bool $has_files 是否有附件
	 * @throws AssignmentException 兩者皆空時（400）。
	 */
	private static function assert_has_content( bool $has_text, bool $has_files ): void {
		if ( ! $has_text && ! $has_files ) {
			throw AssignmentException::invalid_param( \__( 'Please provide text or attachments', 'power-course' ) );
		}
	}

	/**
	 * 守門 2：題型是否接受送來的內容種類（allow_text / allow_file）
	 *
	 * @param AssignmentModel $assignment 作業
	 * @param bool            $has_text   是否有文字內容
	 * @param bool            $has_files  是否有附件
	 * @throws AssignmentException 送出題型不接受的內容時（400）。
	 */
	private static function assert_content_kinds_allowed( AssignmentModel $assignment, bool $has_text, bool $has_files ): void {
		if ( $has_text && 'yes' !== $assignment->allow_text ) {
			throw AssignmentException::invalid_param( \__( 'This assignment does not accept text submissions', 'power-course' ) );
		}
		if ( $has_files && 'yes' !== $assignment->allow_file ) {
			throw AssignmentException::invalid_param( \__( 'This assignment does not accept file submissions', 'power-course' ) );
		}
	}

	/**
	 * 守門 3：題型要求的內容是否齊備（required_text / required_file）
	 *
	 * @param AssignmentModel $assignment 作業
	 * @param bool            $has_text   是否有文字內容
	 * @param bool            $has_files  是否有附件
	 * @throws AssignmentException 缺少必填內容時（400）。
	 */
	private static function assert_required_content_present( AssignmentModel $assignment, bool $has_text, bool $has_files ): void {
		if ( 'yes' === $assignment->required_text && ! $has_text ) {
			throw AssignmentException::invalid_param( \__( 'This assignment requires text content', 'power-course' ) );
		}
		if ( 'yes' === $assignment->required_file && ! $has_files ) {
			throw AssignmentException::invalid_param( \__( 'This assignment requires a file upload', 'power-course' ) );
		}
	}

	/**
	 * 守門 4：附件數量上限與文字長度上限
	 *
	 * @param AssignmentModel $assignment     作業
	 * @param string          $text_body      去除前後空白後的文字內容
	 * @param array<int, int> $attachment_ids 去重後的附件 ID
	 * @throws AssignmentException 超過附件數或文字長度上限時（400）。
	 */
	private static function assert_within_limits( AssignmentModel $assignment, string $text_body, array $attachment_ids ): void {
		if ( \count( $attachment_ids ) > $assignment->max_files ) {
			throw AssignmentException::invalid_param( self::too_many_attachments_message( $assignment->max_files ) );
		}
		if ( \mb_strlen( $text_body ) > self::MAX_TEXT_LENGTH ) {
			throw AssignmentException::invalid_param( \__( 'Text content must not exceed 50000 characters', 'power-course' ) );
		}
	}

	/**
	 * 附件數量超過上限的訊息
	 *
	 * @param int $max_files 附件數量上限
	 * @return string
	 */
	private static function too_many_attachments_message( int $max_files ): string {
		return \sprintf(
			/* translators: %d: 附件數量上限 */
			\_n(
				'Too many attachments (maximum %d file)',
				'Too many attachments (maximum %d files)',
				$max_files,
				'power-course'
			),
			$max_files
		);
	}

	/**
	 * 附件歸屬驗證：每個附件都必須存在且為學員本人上傳（一次查完，不逐筆查）
	 *
	 * @param array<int, int> $attachment_ids 去重後的附件 ID
	 * @param int             $user_id        學員 user ID
	 * @return array<int, string> 附件 ID => 原始檔名
	 * @throws AssignmentException 有附件不存在或非本人上傳時（400）。
	 */
	private static function resolve_owned_attachments( array $attachment_ids, int $user_id ): array {
		if ( ! $attachment_ids ) {
			return [];
		}

		$names = AttachmentRepository::get_names_owned_by( $attachment_ids, $user_id );

		if ( \count( $names ) !== \count( $attachment_ids ) ) {
			throw AssignmentException::invalid_param( \__( 'Attachment does not exist or cannot be used', 'power-course' ) );
		}

		return $names;
	}

	/**
	 * 組出寫入 attachments 欄位的參照陣列（保留呼叫端傳入的順序）
	 *
	 * @param array<int, int>    $attachment_ids 去重後的附件 ID
	 * @param array<int, string> $names          附件 ID => 原始檔名
	 * @return array<int, array{attachment_id: int, file_name: string|null}>
	 */
	private static function build_attachment_refs( array $attachment_ids, array $names ): array {
		return \array_map(
			static function ( int $attachment_id ) use ( $names ): array {
				return [
					'attachment_id' => $attachment_id,
					'file_name'     => \array_key_exists( $attachment_id, $names ) ? $names[ $attachment_id ] : null,
				];
			},
			$attachment_ids
		);
	}

	/**
	 * 取出並去除前後空白的文字內容
	 *
	 * @param array<string, mixed> $args request body
	 * @return string
	 */
	private static function normalize_text( array $args ): string {
		$raw = \array_key_exists( 'text_body', $args ) ? $args['text_body'] : '';

		return \is_scalar( $raw ) ? \trim( (string) $raw ) : '';
	}

	/**
	 * 取出附件 ID 陣列：轉正整數、去重、重排索引（計劃 §6.1「以去重後計數」）
	 *
	 * @param array<string, mixed> $args request body
	 * @return array<int, int>
	 */
	private static function normalize_attachment_ids( array $args ): array {
		$raw = \array_key_exists( 'attachment_ids', $args ) ? $args['attachment_ids'] : [];

		/** @var array<int|string, mixed> $raw_list */
		$raw_list = \is_array( $raw ) ? $raw : (array) $raw;

		$ids = [];
		foreach ( $raw_list as $value ) {
			$id = \is_numeric( $value ) ? (int) $value : 0;
			if ( $id > 0 && ! \in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
