<?php
/**
 * Assignment Message Model（Issue #131 第二波）
 *
 * 資料表 pc_assignment_messages 單列的 DTO。
 * 對外輸出只有 to_view()（api.yml AssignmentMessage 白名單 6 鍵，DEC-W2-06）；
 * 刻意不載入 sender_id / sender_email，結構上就不可能把發送者資訊帶到回應裡。
 *
 * sender_type 一律用 SENDER_STUDENT / SENDER_TEACHER 常數，
 * 寫入端（Service\Thread）與統計端（MessageRepository::thread_summary）共用同一組字串（R-W2-19）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Model;

/**
 * Class Message
 */
final class Message {

	/** 學員發送的訊息 */
	public const SENDER_STUDENT = 'student';

	/** 管理員（討論串中的「老師」）發送的訊息 */
	public const SENDER_TEACHER = 'teacher';

	/**
	 * Constructor
	 *
	 * @param int         $id            訊息 ID
	 * @param int         $submission_id 所屬繳交 ID
	 * @param string      $sender_type   SENDER_STUDENT | SENDER_TEACHER
	 * @param string      $body          訊息內容（原樣保存，不 trim、不 sanitize）
	 * @param string|null $read_at       接收方讀取時間（站台時區；null = 尚未讀）
	 * @param string      $created_at    建立時間（站台時區）
	 */
	public function __construct(
		public int $id,
		public int $submission_id,
		public string $sender_type,
		public string $body,
		public ?string $read_at,
		public string $created_at
	) {}

	/**
	 * 以資料表列建立 Model（sender_id / sender_email 即使在列中也不載入）
	 *
	 * @param array<string, mixed> $row pc_assignment_messages 單列（ARRAY_A）
	 * @return self
	 */
	public static function from_row( array $row ): self {
		return new self(
			self::to_int( $row['id'] ?? 0 ),
			self::to_int( $row['submission_id'] ?? 0 ),
			self::to_string( $row['sender_type'] ?? '' ),
			self::to_string( $row['body'] ?? '' ),
			self::to_nullable_string( $row['read_at'] ?? null ),
			self::to_string( $row['created_at'] ?? '' )
		);
	}

	/**
	 * 對外輸出（api.yml AssignmentMessage）
	 *
	 * 欄位 mine 只看 sender_type 是否等於查詢端（學員端 = student、管理端 = teacher），不看 sender_id：
	 * 任一位管理員查看時，老師訊息都是「自己這一方」發的（DEC-W2-04）。
	 *
	 * @param string $viewer 查詢端，SENDER_STUDENT | SENDER_TEACHER
	 * @return array{id:int, sender_type:string, body:string, read_at:?string, created_at:string, mine:bool}
	 */
	public function to_view( string $viewer ): array {
		return [
			'id'          => $this->id,
			'sender_type' => $this->sender_type,
			'body'        => $this->body,
			'read_at'     => $this->read_at,
			'created_at'  => $this->created_at,
			'mine'        => $this->sender_type === $viewer,
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
