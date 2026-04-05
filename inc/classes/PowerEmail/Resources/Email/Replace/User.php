<?php
/**
 * Email User Replace
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

use J7\PowerCourse\Utils\User as UserUtils;

/**
 * Class User
 */
abstract class User extends ReplaceBase {

	/**
	 * @var array<string, string> 使用者資料取代字串的 Schema
	 */
	public static $schema = [
		'display_name' => '用戶的顯示名稱（套用 Fallback Chain：billing → WP meta → 原始 display_name）',
		'user_email'   => '用戶的電子郵件',
		'ID'           => '用戶的ID',
		'user_login'   => '用戶的帳號',
	];

	/**
	 * 取得取代字串後的 HTML
	 *
	 * {display_name} 套用 Fallback Chain 邏輯：
	 * ① billing_last_name + billing_first_name
	 * ② last_name + first_name
	 * ③ WordPress 原始 display_name
	 *
	 * @param string $html HTML
	 * @param int    $user_id 用戶 ID
	 * @param int    $course_id 課程 ID
	 * @param int    $chapter_id 章節 ID
	 * @return string 格式化後的 HTML
	 */
	public static function replace_string( $html, $user_id, $course_id, $chapter_id ): string {
		$user = \get_user_by( 'ID', $user_id );
		if (!$user) {
			return $html;
		}

		$schema_keys   = array_map( fn( $key ) => '{' . $key . '}', array_keys( self::$schema ) );
		$schema_values = [];
		foreach ( self::$schema as $key => $value ) {
			if ( 'display_name' === $key ) {
				// {display_name} 覆蓋為 Fallback Chain 結果
				$schema_values[] = UserUtils::get_formatted_name( $user->ID );
			} else {
				$schema_values[] = (string) $user->get( $key );
			}
		}

		$formatted_html = str_replace( $schema_keys, $schema_values, $html );

		return $formatted_html;
	}
}
