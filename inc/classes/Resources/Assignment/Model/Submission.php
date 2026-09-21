<?php
/**
 * Assignment Submission Model（Issue #131）
 *
 * 資料表 pc_assignment_submissions 單列的 DTO。
 * attachments 欄位在 DB 是 JSON 字串，對外一律 decode 成 [{attachment_id, file_name}]
 * （api.yml components.schemas.AssignmentAttachmentRef）。
 * 寫入時一律用 encode_attachments() 產生固定鍵順序的 JSON，
 * 讓 SubmissionRepository::exists_with_attachment() 的 LIKE 比對可靠（R-16）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Model;

/**
 * Class Submission
 */
final class Submission {

	/** 預設狀態（待批改） */
	public const DEFAULT_STATUS = 'pending';

	/**
	 * Constructor
	 *
	 * @param int                                                           $id            繳交 ID
	 * @param int                                                           $assignment_id 作業 ID
	 * @param int                                                           $user_id       學員 user ID
	 * @param string|null                                                   $text_body     文字內容
	 * @param array<int, array{attachment_id: int, file_name: string|null}> $attachments   附件參照
	 * @param string                                                        $submitted_at  繳交時間（站台時區）
	 * @param string                                                        $status        pending | pass | fail
	 * @param string|null                                                   $feedback      批改評語
	 * @param int|null                                                      $graded_by     批改者 user ID
	 * @param string|null                                                   $graded_at     批改時間（站台時區）
	 */
	public function __construct(
		public int $id,
		public int $assignment_id,
		public int $user_id,
		public ?string $text_body,
		public array $attachments,
		public string $submitted_at,
		public string $status,
		public ?string $feedback,
		public ?int $graded_by,
		public ?string $graded_at
	) {}

	/**
	 * 以資料表列建立 Model
	 *
	 * @param array<string, mixed> $row pc_assignment_submissions 單列（ARRAY_A）
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			self::to_int( $row['id'] ?? 0 ),
			self::to_int( $row['assignment_id'] ?? 0 ),
			self::to_int( $row['user_id'] ?? 0 ),
			self::to_nullable_string( $row['text_body'] ?? null ),
			self::decode_attachments( $row['attachments'] ?? '' ),
			self::to_string( $row['submitted_at'] ?? '' ),
			self::to_string( $row['status'] ?? self::DEFAULT_STATUS ),
			self::to_nullable_string( $row['feedback'] ?? null ),
			self::to_nullable_int( $row['graded_by'] ?? null ),
			self::to_nullable_string( $row['graded_at'] ?? null )
		);
	}

	/**
	 * 輸出為 API 回應格式（api.yml components.schemas.AssignmentSubmission）
	 *
	 * @return array{id: int, assignment_id: int, user_id: int, text_body: string|null, attachments: array<int, array{attachment_id: int, file_name: string|null}>, submitted_at: string, status: string, feedback: string|null, graded_by: int|null, graded_at: string|null}
	 */
	public function to_array(): array {
		return [
			'id'            => $this->id,
			'assignment_id' => $this->assignment_id,
			'user_id'       => $this->user_id,
			'text_body'     => $this->text_body,
			'attachments'   => $this->attachments,
			'submitted_at'  => $this->submitted_at,
			'status'        => $this->status,
			'feedback'      => $this->feedback,
			'graded_by'     => $this->graded_by,
			'graded_at'     => $this->graded_at,
		];
	}

	/**
	 * 取得本筆繳交引用到的附件 ID 清單
	 *
	 * @return array<int, int>
	 */
	public function get_attachment_ids(): array {
		return \array_values(
			\array_map(
				static fn( array $ref ): int => $ref['attachment_id'],
				$this->attachments
			)
		);
	}

	/**
	 * 以附件 ID → 原始檔名對照表補齊 file_name（舊資料 / 種子資料可能為 null）
	 *
	 * @param array<int, string> $names 附件 ID => 原始檔名
	 */
	public function fill_attachment_names( array $names ): void {
		$filled = [];
		foreach ( $this->attachments as $ref ) {
			$id = $ref['attachment_id'];
			if ( ( null === $ref['file_name'] || '' === $ref['file_name'] ) && \array_key_exists( $id, $names ) ) {
				$ref['file_name'] = $names[ $id ];
			}
			$filled[] = $ref;
		}

		$this->attachments = $filled;
	}

	/**
	 * 附件參照陣列 → JSON 字串（固定鍵順序：attachment_id、file_name，R-16）
	 *
	 * @param array<int, array{attachment_id: int, file_name: string|null}> $attachments 附件參照
	 * @return string
	 */
	public static function encode_attachments( array $attachments ): string {
		$normalized = \array_map(
			static function ( array $ref ): array {
				return [
					'attachment_id' => $ref['attachment_id'],
					'file_name'     => $ref['file_name'],
				];
			},
			\array_values( $attachments )
		);

		return (string) \wp_json_encode( $normalized );
	}

	/**
	 * 將 attachments 欄位（JSON 字串）解碼為附件參照陣列
	 *
	 * @param mixed $raw DB 欄位值
	 * @return array<int, array{attachment_id: int, file_name: string|null}>
	 */
	public static function decode_attachments( mixed $raw ): array {
		if ( ! \is_string( $raw ) || '' === $raw ) {
			return [];
		}

		$decoded = \json_decode( $raw, true );
		if ( ! \is_array( $decoded ) ) {
			return [];
		}

		$refs = [];
		foreach ( $decoded as $item ) {
			if ( ! \is_array( $item ) ) {
				continue;
			}

			$attachment_id = self::to_int( $item['attachment_id'] ?? 0 );
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$refs[] = [
				'attachment_id' => $attachment_id,
				'file_name'     => self::to_nullable_string( $item['file_name'] ?? null ),
			];
		}

		return $refs;
	}

	/**
	 * 資料庫值轉 int
	 *
	 * @param mixed $value 資料庫值
	 * @return int
	 */
	private static function to_int( mixed $value ): int {
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * 資料庫值轉 int|null（null 與空字串維持 null）
	 *
	 * @param mixed $value 資料庫值
	 * @return int|null
	 */
	private static function to_nullable_int( mixed $value ): ?int {
		return \is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * 資料庫值轉 string
	 *
	 * @param mixed $value 資料庫值
	 * @return string
	 */
	private static function to_string( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * 資料庫值轉 string|null（null 維持 null）
	 *
	 * @param mixed $value 資料庫值
	 * @return string|null
	 */
	private static function to_nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		return \is_scalar( $value ) ? (string) $value : null;
	}
}
