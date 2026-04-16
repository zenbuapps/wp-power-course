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
	 * @var array<string, string> 使用者資料取代字串的 Schema（value 為英文 label，實際顯示請透過 get_localized_schemas()）
	 */
	public static $schema = [
		'title' => 'Chapter name',
	];

	/**
	 * 取得已翻譯的 Schema（含前綴 key + 翻譯後的 label）
	 *
	 * @return array<string, string>
	 */
	public static function get_localized_schemas(): array {
		return [
			self::$prefix . 'title' => \__( 'Chapter name', 'power-course' ),
		];
	}

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
