<?php
/**
 * Email Chapter Replace
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

/**
 * Class Chapter
 */
abstract class Chapter extends ReplaceBase {

	/**
	 * 前綴
	 *
	 * @var string
	 */
	public static $prefix = 'chapter_';

	/**
	 * @var array<string, string> 使用者資料取代字串的 Schema
	 */
	public static $schema = [
		'title' => '章節名稱',
	];

	/**
	 * 取得取代字串後的 HTML
	 *
	 * @param string $html HTML
	 * @param int    $user_id 用戶 ID
	 * @param int    $course_id 課程 ID
	 * @param int    $chapter_id 章節 ID
	 * @return string 格式化後的 HTML
	 */
	public static function replace_string( $html, $user_id, $course_id, $chapter_id ): string {
		if (!$chapter_id) {
			return $html;
		}

		$chapter = \get_post( $chapter_id );
		if (!$chapter) {
			return $html;
		}

		$schema_values = [];

		foreach ( self::$schema as $key => $value ) {
			$post_key        = 'post_' . $key;
			$schema_values[] = $chapter->$post_key;
		}

		$schema_keys    = array_map( fn( $key ) => '{' . self::$prefix . $key . '}', array_keys( self::$schema ) );
		$formatted_html = str_replace( $schema_keys, $schema_values, $html );
		return $formatted_html;
	}
}
