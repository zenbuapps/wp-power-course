<?php
/**
 * Datetime 工具
 * 統一處理時間字串的時區轉換（Issue #233）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * Class Datetime
 * 時間相關工具（通用，可跨模組 reuse）。
 */
abstract class Datetime {

	/**
	 * 把 GMT/UTC 時間字串轉成 WordPress 設定時區（格式維持 Y-m-d H:i:s）
	 *
	 * 背景：wp_users.user_registered 由 WP 核心以 UTC 寫入（wp_insert_user → gmdate）。
	 * 各輸出點若直接輸出原值，在主機 PHP date.timezone = UTC、WP 設定為 Asia/Taipei 時，
	 * 顯示時間會少 8 小時，與同畫面其他已轉時區的欄位互相矛盾（Issue #233）。
	 *
	 * - get_date_from_gmt() 依 wp_timezone()（讀 timezone_string / gmt_offset option）轉換，
	 *   與 PHP date.timezone 無關。
	 * - 僅以單一參數呼叫，沿用預設輸出格式 Y-m-d H:i:s（不套站台日期/時間格式）。
	 * - 空字串 / 0000-00-00 等異常值原樣回傳，避免 get_date_from_gmt('') → 1970-01-01 08:00:00 誤導值。
	 *
	 * @param string|null $gmt DB 內的 UTC 時間字串（如 wp_users.user_registered）。
	 * @return string 站台時區字串；空 / 異常值原樣回傳。
	 */
	public static function to_site_timezone( ?string $gmt ): string {
		$gmt = (string) $gmt;
		if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			return $gmt;
		}
		return (string) \get_date_from_gmt( $gmt ); // 預設輸出 'Y-m-d H:i:s'。
	}
}
