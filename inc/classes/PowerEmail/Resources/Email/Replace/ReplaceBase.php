<?php
/**
 * ReplaceBase
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

/**
 * ReplaceBase
 */
abstract class ReplaceBase {


	/**
	 * 前綴
	 *
	 * @var string
	 */
	public static $prefix;

	/**
	 * @var array<string, string> 使用者資料取代字串的 Schema
	 */
	public static $schema;

	/**
	 * 取得 Schema 帶有前綴的陣列
	 *
	 * @return array<string, string> Schema 帶有前綴的陣列
	 */
	final public static function get_schemas(): array {
		$schemas_with_prefix = [];
		foreach ( static::$schema as $key => $value ) {
			$schemas_with_prefix[ static::$prefix . $key ] = $value;
		}
		return $schemas_with_prefix;
	}
}
