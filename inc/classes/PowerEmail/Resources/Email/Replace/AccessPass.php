<?php
/**
 * Email AccessPass Replace
 *
 * 課程通行證到期預警信專用的變數替換。掛在 power_course_access_pass_email_* filter 上，
 * 與課程 / 章節的 filter 分開——到期預警的情境沒有 course_id / chapter_id 可談。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

use J7\PowerCourse\Resources\AccessPass\Model\AccessPass as AccessPassModel;
use J7\PowerCourse\Resources\AccessPass\Service\Scope;
use J7\PowerCourse\FrontEnd\MyAccount;

/**
 * Class AccessPass
 */
abstract class AccessPass extends ReplaceBase {

	/**
	 * 前綴
	 *
	 * @var string
	 */
	public static $prefix = 'access_pass_';

	/**
	 * @var array<string, string> 取代字串的 Schema（value 為英文 label，實際顯示請透過 get_localized_schemas()）
	 */
	public static $schema = [
		'name'           => 'Access pass name',
		'id'             => 'Access pass ID',
		'expire_date'    => 'Access pass expiration date',
		'days_remaining' => 'Days remaining',
		'course_count'   => 'Number of courses covered',
		'my_passes_url'  => 'My access passes page URL',
	];

	/**
	 * 取得已翻譯的 Schema（含前綴 key + 翻譯後的 label）
	 *
	 * @return array<string, string>
	 */
	public static function get_localized_schemas(): array {
		return [
			self::$prefix . 'name'           => \__( 'Access pass name', 'power-course' ),
			self::$prefix . 'id'             => \__( 'Access pass ID', 'power-course' ),
			self::$prefix . 'expire_date'    => \__( 'Access pass expiration date', 'power-course' ),
			self::$prefix . 'days_remaining' => \__( 'Days remaining', 'power-course' ),
			self::$prefix . 'course_count'   => \__( 'Number of courses covered', 'power-course' ),
			self::$prefix . 'my_passes_url'  => \__( 'My access passes page URL', 'power-course' ),
		];
	}

	/**
	 * 取得取代字串後的 HTML
	 *
	 * @param string $html              HTML
	 * @param int    $user_id           用戶 ID（本類別未使用，維持與其他 Replace 一致的簽名）
	 * @param int    $pass_id           通行證 post ID
	 * @param int    $expire_timestamp  到期 Unix timestamp（0 代表無到期日）
	 *
	 * @return string 格式化後的 HTML
	 */
	public static function replace_string( $html, $user_id, $pass_id, $expire_timestamp ): string {
		$pass_id = (int) $pass_id;
		if ( ! $pass_id ) {
			return $html;
		}

		$pass = AccessPassModel::instance( $pass_id );
		if ( ! $pass instanceof AccessPassModel ) {
			return $html;
		}

		$expire_timestamp = (int) $expire_timestamp;
		$days_remaining   = $expire_timestamp > 0
		? \max( 0, (int) \ceil( ( $expire_timestamp - \time() ) / \DAY_IN_SECONDS ) )
		: 0;

		$values = [
			'name'           => $pass->name,
			'id'             => (string) $pass->id,
			'expire_date'    => $expire_timestamp > 0 ? (string) \wp_date( 'Y-m-d', $expire_timestamp ) : '',
			'days_remaining' => (string) $days_remaining,
			'course_count'   => (string) Scope::count_courses( $pass ),
			'my_passes_url'  => (string) \wc_get_account_endpoint_url( MyAccount::PASSES_ENDPOINT ),
		];

		$schema_keys   = [];
		$schema_values = [];
		foreach ( self::$schema as $key => $label ) {
			$schema_keys[]   = '{' . self::$prefix . $key . '}';
			$schema_values[] = $values[ $key ] ?? '';
		}

		return \str_replace( $schema_keys, $schema_values, $html );
	}
}
