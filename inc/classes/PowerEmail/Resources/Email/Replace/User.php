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
	 * @var array<string, string> 使用者資料取代字串的 Schema（value 為英文 label，實際顯示請透過 get_localized_schemas()）
	 */
	public static $schema = [
		'display_name' => 'User display name (with fallback chain: billing → WP meta → raw display_name)',
		'user_email'   => 'User email',
		'ID'           => 'User ID',
		'user_login'   => 'User login',
	];

	/**
	 * 取得已翻譯的 Schema（User 無前綴，直接使用 key）
	 *
	 * @return array<string, string>
	 */
	public static function get_localized_schemas(): array {
		return [
			'display_name' => \__( 'User display name (with fallback chain: billing → WP meta → raw display_name)', 'power-course' ),
			'user_email'   => \__( 'User email', 'power-course' ),
			'ID'           => \__( 'User ID', 'power-course' ),
			'user_login'   => \__( 'User login', 'power-course' ),
		];
	}

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
