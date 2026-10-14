<?php
/**
 * Assignment Attachment Model（Issue #131）
 *
 * 資料表 pc_assignment_attachments 單列的 DTO。
 * 對外輸出只有 to_meta_array()（api.yml AssignmentAttachmentMeta），永遠不含 stored_path 或 URL（DEC-09）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Model;

/**
 * Class Attachment
 */
final class Attachment {

	/**
	 * Constructor
	 *
	 * @param int    $id            附件 ID
	 * @param int    $user_id       上傳者 user ID
	 * @param string $original_name 原始檔名（UTF-8）
	 * @param string $stored_path   受保護目錄內的儲存路徑（僅供伺服器端解析，不得輸出）
	 * @param string $mime_type     標準 MIME 類型
	 * @param int    $file_size     檔案大小（bytes）
	 * @param string $created_at    建立時間（站台時區）
	 */
	public function __construct(
		public int $id,
		public int $user_id,
		public string $original_name,
		public string $stored_path,
		public string $mime_type,
		public int $file_size,
		public string $created_at
	) {}

	/**
	 * 以資料表列建立 Model
	 *
	 * @param array<string, mixed> $row pc_assignment_attachments 單列（ARRAY_A）
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			self::to_int( $row['id'] ?? 0 ),
			self::to_int( $row['user_id'] ?? 0 ),
			self::to_string( $row['original_name'] ?? '' ),
			self::to_string( $row['stored_path'] ?? '' ),
			self::to_string( $row['mime_type'] ?? '' ),
			self::to_int( $row['file_size'] ?? 0 ),
			self::to_string( $row['created_at'] ?? '' )
		);
	}

	/**
	 * 上傳回應（api.yml AssignmentAttachmentMeta）
	 *
	 * @return array{attachment_id: int, file_name: string, mime_type: string, file_size: int}
	 */
	public function to_meta_array(): array {
		return [
			'attachment_id' => $this->id,
			'file_name'     => $this->original_name,
			'mime_type'     => $this->mime_type,
			'file_size'     => $this->file_size,
		];
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
	 * 資料庫值轉 string
	 *
	 * @param mixed $value 資料庫值
	 * @return string
	 */
	private static function to_string( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}
}
