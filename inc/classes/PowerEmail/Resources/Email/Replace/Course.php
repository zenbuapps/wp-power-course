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
	 * @var array<string, string> 使用者資料取代字串的 Schema（value 為英文 label，實際顯示請透過 get_localized_schemas()）
	 */
	public static $schema = [
		'name'          => 'Course name',
		'id'            => 'Course ID',
		'regular_price' => 'Course price',
		'sale_price'    => 'Course sale price',
		'slug'          => 'Course slug',
		'image_url'     => 'Course image URL',
		'permalink'     => 'Course permalink',
	];

	/**
	 * 取得已翻譯的 Schema（含前綴 key + 翻譯後的 label）
	 *
	 * @return array<string, string>
	 */
	public static function get_localized_schemas(): array {
		return [
			self::$prefix . 'name'          => \__( 'Course name', 'power-course' ),
			self::$prefix . 'id'            => \__( 'Course ID', 'power-course' ),
			self::$prefix . 'regular_price' => \__( 'Course price', 'power-course' ),
			self::$prefix . 'sale_price'    => \__( 'Course sale price', 'power-course' ),
			self::$prefix . 'slug'          => \__( 'Course slug', 'power-course' ),
			self::$prefix . 'image_url'     => \__( 'Course image URL', 'power-course' ),
			self::$prefix . 'permalink'     => \__( 'Course permalink', 'power-course' ),
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
