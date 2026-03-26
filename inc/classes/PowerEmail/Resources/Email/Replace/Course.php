<?php
/**
 * Email Course Replace
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

/**
 * Class Course
 */
abstract class Course extends ReplaceBase {

	/**
	 * 前綴
	 *
	 * @var string
	 */
	public static $prefix = 'course_';

	/**
	 * @var array<string, string> 使用者資料取代字串的 Schema
	 */
	public static $schema = [
		'name'          => '課程名稱',
		'id'            => '課程 ID',
		'regular_price' => '課程價格',
		'sale_price'    => '課程促銷價格',
		'slug'          => '課程 Slug',
		'image_url'     => '課程圖片 URL',
		'permalink'     => '課程永久連結',
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

		if (!$course_id) {
			return $html;
		}

		$course = \wc_get_product($course_id);
		if (!$course) {
			return $html;
		}

		$permalink     = \get_permalink( $course->get_id() );
		$image_url     = \get_the_post_thumbnail_url( $course->get_id(), 'full' );
		$schema_values = [];

		foreach ( self::$schema as $key => $value ) {

			if ( 'image_url' === $key ) {
				$schema_values[] = $image_url;
				continue;
			}
			if ( 'permalink' === $key ) {
				$schema_values[] = $permalink;
				continue;
			}

			$method          = "get_{$key}";
			$schema_values[] = $course->$method();
		}

		$schema_keys = array_map( fn( $key ) => '{' . self::$prefix . $key . '}', array_keys( self::$schema ) );

		$formatted_html = str_replace( $schema_keys, $schema_values, $html );
		return $formatted_html;
	}
}
