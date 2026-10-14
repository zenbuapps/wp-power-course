<?php
/**
 * 作業討論串 Service（Issue #131 第二波）
 *
 * 對應 specs/features/assignment/查詢作業討論串.feature、發送作業討論訊息.feature 與計劃 §7.1 / §7.2：
 *   讀：FIND SUBMISSION → OWNERSHIP（只有學員端）→ MARK READ（對方訊息）→ LOAD → to_view( viewer )
 *   發：FIND SUBMISSION → OWNERSHIP（只有學員端）→ VALIDATE BODY → INSERT → to_view( sender )
 *
 * 檢查順序固定為 404 → 403 → 400（資源 → ownership → body，DEC-W2-03）。
 * 不檢查課程觀看權、線性觀看（D29），也不檢查所屬作業是否在垃圾桶（只要求繳交存在，§10 S-05）。
 * 發訊息不寄信、不寫學員日誌，也不把任何訊息標已讀（DEC-W2-04、DEC-W2-08）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Model\Message as MessageModel;
use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;

/**
 * Class Thread
 */
final class Thread {

	/** 訊息內容長度上限（字元數，不是 bytes） */
	private const MAX_BODY_LENGTH = 10000;

	/**
	 * 學員讀取自己繳交的討論串（先把老師訊息標已讀再讀取）
	 *
	 * @param int $submission_id 繳交 ID
	 * @param int $user_id       學員 user ID
	 * @return array<int, array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}>
	 * @throws AssignmentException 繳交不存在（404）、非本人繳交（403）、讀取失敗（500）時。
	 */
	public static function list_for_student( int $submission_id, int $user_id ): array {
		self::resolve_owned_submission( $submission_id, $user_id );

		return self::load( $submission_id, MessageModel::SENDER_STUDENT );
	}

	/**
	 * 管理員讀取任一繳交的討論串（先把學員訊息標已讀再讀取）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array<int, array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}>
	 * @throws AssignmentException 繳交不存在（404）、讀取失敗（500）時。
	 */
	public static function list_for_teacher( int $submission_id ): array {
		self::resolve_submission( $submission_id );

		return self::load( $submission_id, MessageModel::SENDER_TEACHER );
	}

	/**
	 * 學員在自己繳交的討論串發送訊息（sender_email 不記錄）
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param int                  $user_id       學員 user ID
	 * @param array<string, mixed> $args          request body（body）
	 * @return array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}
	 * @throws AssignmentException 繳交不存在（404）、非本人繳交（403）、body 不合法（400）、寫入失敗（500）時。
	 */
	public static function post_as_student( int $submission_id, int $user_id, array $args ): array {
		self::resolve_owned_submission( $submission_id, $user_id );
		$body = self::validate_body( $args );

		return self::post( $submission_id, MessageModel::SENDER_STUDENT, $user_id, null, $body );
	}

	/**
	 * 管理員以老師身分在任一繳交的討論串發送訊息（保存管理員 email 快照）
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param int                  $admin_id      發送的管理員 user ID
	 * @param array<string, mixed> $args          request body（body）
	 * @return array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}
	 * @throws AssignmentException 繳交不存在（404）、body 不合法（400）、寫入失敗（500）時。
	 */
	public static function post_as_teacher( int $submission_id, int $admin_id, array $args ): array {
		self::resolve_submission( $submission_id );
		$body = self::validate_body( $args );

		return self::post( $submission_id, MessageModel::SENDER_TEACHER, $admin_id, self::email_snapshot( $admin_id ), $body );
	}

	/**
	 * 取得繳交（只要求存在，不檢查作業狀態）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return SubmissionModel
	 * @throws AssignmentException 繳交不存在時（404）。
	 */
	private static function resolve_submission( int $submission_id ): SubmissionModel {
		$submission = $submission_id > 0 ? SubmissionRepository::find( $submission_id ) : null;
		if ( null === $submission ) {
			throw AssignmentException::not_found( \__( 'Submission not found', 'power-course' ) );
		}

		return $submission;
	}

	/**
	 * 取得學員本人的繳交（先查存在，再查 ownership）
	 *
	 * @param int $submission_id 繳交 ID
	 * @param int $user_id       學員 user ID
	 * @return SubmissionModel
	 * @throws AssignmentException 繳交不存在（404）、非本人繳交（403）時。
	 */
	private static function resolve_owned_submission( int $submission_id, int $user_id ): SubmissionModel {
		$submission = self::resolve_submission( $submission_id );
		if ( $user_id <= 0 || $submission->user_id !== $user_id ) {
			throw AssignmentException::forbidden( \__( 'You do not have permission to access this submission', 'power-course' ) );
		}

		return $submission;
	}

	/**
	 * 驗證訊息內容：必須是字串且長度 1–10000 字（DEC-W2-05）
	 *
	 * 不 trim（只有空白也合法）、不 sanitize（HTML 原樣保存，輸出端負責跳脫）；
	 * 沒帶、null、陣列、數字一律回同一句訊息。
	 *
	 * @param array<string, mixed> $args request body
	 * @return string 原樣的訊息內容
	 * @throws AssignmentException 訊息內容不合法時（400）。
	 */
	private static function validate_body( array $args ): string {
		$body = \array_key_exists( 'body', $args ) ? $args['body'] : null;

		if ( ! \is_string( $body ) ) {
			throw AssignmentException::invalid_param( self::invalid_body_message() );
		}

		$length = \mb_strlen( $body );
		if ( $length < 1 || $length > self::MAX_BODY_LENGTH ) {
			throw AssignmentException::invalid_param( self::invalid_body_message() );
		}

		return $body;
	}

	/**
	 * 先把對方發送的未讀訊息標已讀，再讀取整串（DEC-W2-04）
	 *
	 * 標已讀是附帶狀態：失敗只記 log，照常回傳列表（後果是徽章晚一點歸零，§8）。
	 *
	 * @param int    $submission_id 繳交 ID
	 * @param string $viewer        查詢端，MessageModel::SENDER_*
	 * @return array<int, array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}>
	 * @throws AssignmentException 讀取失敗時（500）。
	 */
	private static function load( int $submission_id, string $viewer ): array {
		$counterpart = MessageModel::SENDER_STUDENT === $viewer ? MessageModel::SENDER_TEACHER : MessageModel::SENDER_STUDENT;

		try {
			MessageRepository::mark_read( $submission_id, $counterpart, (string) \wp_date( 'Y-m-d H:i:s', Clock::now() ) );
		} catch ( \RuntimeException $e ) {
			Plugin::logger(
				'Assignment messages mark read failed',
				'error',
				[
					'submission_id' => $submission_id,
					'error'         => $e->getMessage(),
				]
			);
		}

		try {
			$messages = MessageRepository::list_by_submission( $submission_id );
		} catch ( \RuntimeException $e ) {
			Plugin::logger(
				'Assignment messages load failed',
				'error',
				[
					'submission_id' => $submission_id,
					'error'         => $e->getMessage(),
				]
			);
			throw AssignmentException::internal( \__( 'Failed to load discussion', 'power-course' ) );
		}

		return \array_map(
			static fn( MessageModel $message ): array => $message->to_view( $viewer ),
			$messages
		);
	}

	/**
	 * 寫入一則訊息並讀回（回應以發送端為查詢者，mine = true、read_at = null）
	 *
	 * @param int         $submission_id 繳交 ID
	 * @param string      $sender_type   MessageModel::SENDER_*
	 * @param int         $sender_id     發送者 user ID
	 * @param string|null $sender_email  發送者 email 快照（學員為 null）
	 * @param string      $body          已驗證的訊息內容
	 * @return array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}
	 * @throws AssignmentException 寫入或讀回失敗時（500）。
	 */
	private static function post( int $submission_id, string $sender_type, int $sender_id, ?string $sender_email, string $body ): array {
		$now = (string) \wp_date( 'Y-m-d H:i:s', Clock::now() );

		try {
			$message_id = MessageRepository::insert( $submission_id, $sender_type, $sender_id, $sender_email, $body, $now );
		} catch ( \RuntimeException $e ) {
			Plugin::logger(
				'Assignment message insert failed',
				'error',
				[
					'submission_id' => $submission_id,
					'sender_type'   => $sender_type,
					'error'         => $e->getMessage(),
				]
			);
			throw AssignmentException::internal( \__( 'Failed to send message', 'power-course' ) );
		}

		$message = MessageRepository::find( $message_id );
		if ( null === $message ) {
			throw AssignmentException::internal( \__( 'Failed to send message', 'power-course' ) );
		}

		return $message->to_view( $sender_type );
	}

	/**
	 * 管理員 email 快照（寫入 sender_email，對外永不輸出）
	 *
	 * @param int $admin_id 管理員 user ID
	 * @return string|null 找不到使用者或 email 為空時為 null
	 */
	private static function email_snapshot( int $admin_id ): ?string {
		$user = \get_userdata( $admin_id );
		if ( ! $user instanceof \WP_User || '' === $user->user_email ) {
			return null;
		}

		return $user->user_email;
	}

	/**
	 * 「訊息內容長度必須為 1 至 10000 字」訊息
	 *
	 * @return string 已翻譯的錯誤訊息
	 */
	private static function invalid_body_message(): string {
		return \__( 'Message must be between 1 and 10000 characters', 'power-course' );
	}
}
