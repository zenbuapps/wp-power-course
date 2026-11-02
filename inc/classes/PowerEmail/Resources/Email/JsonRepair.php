<?php
/**
 * 無法解析的 Email 編輯器 JSON：檢查與修復（Issue #290）
 *
 * 只修一種確認過來源的損壞：HTML 標籤屬性的引號失去跳脫（見 EditorJsonGuard 的成因說明）。
 * 其他損壞一律列為「無法自動修復」且不改動任何資料。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email;

use J7\PowerCourse\Utils\JsonString;

/**
 * Class JsonRepair
 */
abstract class JsonRepair {

	/** @var string 修復前原始內容的備份 meta key（底線開頭：不顯示於自訂欄位；可多筆） */
	public const BACKUP_META_KEY = '_pc_email_json_backup';

	/** @var string 可自動修復 */
	public const STATE_REPAIRABLE = 'repairable';

	/** @var string 無法自動修復 */
	public const STATE_UNREPAIRABLE = 'unrepairable';

	/**
	 * 嘗試修復 JSON 字串
	 *
	 * 1. 只改 HTML 標籤內 `屬性="值"` 的引號，不做全域替換，不影響正文、網址與既有跳脫
	 * 2. 修復後必須是合法的 JSON 物件 / 陣列，否則回傳 null（例如正文也有未跳脫的引號）
	 *
	 * @param string $raw 無法解析的內容
	 * @return array{json: string, changes: array<int, array{before: string, after: string}>}|null
	 */
	public static function propose( string $raw ): ?array {
		$changes = [];

		// 刻意不用 u 修飾字：比對的都是 ASCII，壞編碼的內容也不會讓 preg 回傳 null
		$fixed = \preg_replace_callback(
			'/<[a-zA-Z][a-zA-Z0-9-]*\s[^<>]*>/',
			static function ( array $tag ) use ( &$changes ): string {
				// 必須是「空白 + 屬性名 + =」開頭，否則像 x=" 這種跨越 JSON 字串邊界的內容也會被當成屬性
				$after = (string) \preg_replace( '/(\s[a-zA-Z_:][a-zA-Z0-9_:.-]*\s*=\s*)"([^"]*)"(?=[\s\/>])/', '$1\\\\"$2\\\\"', $tag[0] );
				if ( $after !== $tag[0] ) {
					$changes[] = [
						'before' => $tag[0],
						'after'  => $after,
					];
				}
				return $after;
			},
			$raw
		);

		if ( ! \is_string( $fixed ) || [] === $changes ) {
			return null;
		}

		$decoded = json_decode( $fixed );
		if ( JSON_ERROR_NONE !== json_last_error() || ( ! \is_object( $decoded ) && ! \is_array( $decoded ) ) ) {
			return null;
		}

		return [
			'json'    => $fixed,
			'changes' => $changes,
		];
	}

	/**
	 * 列出所有內容無法解析的模板（唯讀，不改動任何資料）
	 *
	 * 直接查 DB：包含草稿、已發佈與垃圾桶；雙重編碼不算損壞（編輯器讀得懂，存檔時會自癒）。
	 *
	 * @return array<int, array{id: string, name: string, status: string, state: string, fingerprint: string, changes: array<int, array{before: string, after: string}>}>
	 */
	public static function scan(): array {
		global $wpdb;

		/** @var array<int, object{ID: string, post_title: string, post_status: string, post_excerpt: string}> $rows */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_status, post_excerpt FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' ORDER BY ID ASC",
				CPT::POST_TYPE
			)
		);

		$items = [];
		foreach ( $rows as $row ) {
			if ( ! \is_wp_error( JsonString::normalize( $row->post_excerpt, true ) ) ) {
				continue;
			}

			$proposal = self::propose( $row->post_excerpt );
			$items[]  = [
				'id'          => (string) $row->ID,
				'name'        => $row->post_title,
				'status'      => $row->post_status,
				'state'       => null === $proposal ? self::STATE_UNREPAIRABLE : self::STATE_REPAIRABLE,
				'fingerprint' => self::fingerprint( $row->post_excerpt ),
				'changes'     => null === $proposal ? [] : $proposal['changes'],
			];
		}

		return $items;
	}

	/**
	 * 修復單一模板
	 *
	 * 只更新 post_excerpt：刻意用 $wpdb 直寫，不經 wp_update_post——
	 * 正文 HTML、修改日期、meta、排程與 hook 都維持原狀，寄信行為不受影響。
	 *
	 * @param int    $id          模板 ID
	 * @param string $fingerprint 預覽當下內容的指紋：內容已變動就拒絕，避免修到管理者沒看過的版本
	 * @return true|\WP_Error
	 */
	public static function repair( int $id, string $fingerprint ): bool|\WP_Error {
		global $wpdb;

		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'email_not_found', __( 'The specified email template was not found.', 'power-course' ), [ 'status' => 404 ] );
		}

		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $id ) );

		if ( ! \hash_equals( self::fingerprint( $raw ), $fingerprint ) ) {
			return new \WP_Error( 'email_content_changed', __( 'This email has changed since the preview. Please check again.', 'power-course' ), [ 'status' => 409 ] );
		}

		if ( ! \is_wp_error( JsonString::normalize( $raw, true ) ) ) {
			return new \WP_Error( 'email_content_valid', __( 'This email content can already be parsed. No repair is needed.', 'power-course' ), [ 'status' => 409 ] );
		}

		$proposal = self::propose( $raw );
		if ( null === $proposal ) {
			return new \WP_Error( 'email_content_unrepairable', __( 'This email content cannot be repaired automatically. No data was changed.', 'power-course' ), [ 'status' => 422 ] );
		}

		// 先備份，備份失敗就不動資料
		$backup = [
			'post_excerpt' => $raw,
			'repaired_at'  => \time(),
			'repaired_by'  => \get_current_user_id(),
		];
		$backup_id = \add_post_meta( $id, self::BACKUP_META_KEY, \wp_slash( $backup ) );
		if ( ! $backup_id ) {
			return new \WP_Error( 'email_backup_failed', __( 'Failed to back up the email content. No data was changed.', 'power-course' ), [ 'status' => 500 ] );
		}

		// WHERE 帶上原內容：比對指紋之後若被別的請求改掉，這裡更新 0 筆，不會覆寫新內容
		$updated = $wpdb->update(
			$wpdb->posts,
			[ 'post_excerpt' => $proposal['json'] ],
			[
				'ID'           => $id,
				'post_excerpt' => $raw,
			]
		);
		if ( ! $updated ) {
			\delete_metadata_by_mid( 'post', (int) $backup_id );

			if ( false === $updated ) {
				return new \WP_Error( 'email_repair_failed', __( 'Failed to repair the email content.', 'power-course' ), [ 'status' => 500 ] );
			}

			return new \WP_Error( 'email_content_changed', __( 'This email has changed since the preview. Please check again.', 'power-course' ), [ 'status' => 409 ] );
		}

		\clean_post_cache( $id );

		return true;
	}

	/**
	 * 內容指紋
	 *
	 * @param string $raw 內容
	 * @return string
	 */
	private static function fingerprint( string $raw ): string {
		return \hash( 'sha256', $raw );
	}
}
