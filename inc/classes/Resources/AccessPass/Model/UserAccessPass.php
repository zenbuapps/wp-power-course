<?php
/**
 * UserAccessPass Model（Issue #252）
 *
 * 對應 pc_user_access_pass 資料表的單列 DTO。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Model;

/**
 * Class UserAccessPass
 * 使用者持有課程權限包的關係（compute-on-read 的權限來源）。
 */
final class UserAccessPass {

	/**
	 * Constructor
	 *
	 * @param int         $id              主鍵
	 * @param int         $user_id         學員 WordPress user ID
	 * @param int         $pass_id         權限包 ID（pc_access_pass CPT post_id）
	 * @param int|null    $source_order_id 取得來源 WC 訂單 ID（追溯用）
	 * @param string|null $expire_date     到期表達式：null/"0"=永久；10 位 timestamp=限時；"subscription_{id}"=跟隨訂閱
	 * @param string|null $granted_at      取得時間（Y-m-d H:i:s）
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $user_id,
		public readonly int $pass_id,
		public readonly ?int $source_order_id,
		public readonly ?string $expire_date,
		public readonly ?string $granted_at
	) {}

	/**
	 * 從資料庫列物件建立 Model 實例
	 *
	 * @param object $row 資料庫列物件
	 * @return self
	 */
	public static function from_row( object $row ): self {
		return new self(
			id: (int) ( $row->id ?? 0 ),
			user_id: (int) ( $row->user_id ?? 0 ),
			pass_id: (int) ( $row->pass_id ?? 0 ),
			source_order_id: isset( $row->source_order_id ) ? (int) $row->source_order_id : null,
			expire_date: isset( $row->expire_date ) ? (string) $row->expire_date : null,
			granted_at: isset( $row->granted_at ) ? (string) $row->granted_at : null
		);
	}
}
