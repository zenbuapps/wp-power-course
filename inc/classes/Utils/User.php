<?php
/**
 * User
 * TODO 移動到 Resources 底下
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * Class User
 */
abstract class User {

	/**
	 * 取得用戶的格式化名稱
	 *
	 * Fallback Chain:
	 * ① billing_last_name + billing_first_name（WooCommerce 帳單姓名）
	 * ② last_name + first_name（WordPress 用戶 meta）
	 * ③ display_name（WordPress 公開顯示名稱）
	 *
	 * @param int $user_id 用戶 ID
	 * @return string 格式化後的名稱
	 */
	public static function get_formatted_name( int $user_id ): string {
		$user = \get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return '';
		}

		// ① 優先取 WooCommerce billing 姓名
		$billing_last  = (string) \get_user_meta( $user_id, 'billing_last_name', true );
		$billing_first = (string) \get_user_meta( $user_id, 'billing_first_name', true );

		if ( '' !== $billing_last || '' !== $billing_first ) {
			return $billing_last . $billing_first;
		}

		// ② 其次取 WordPress 用戶 meta 姓名
		$wp_last  = (string) \get_user_meta( $user_id, 'last_name', true );
		$wp_first = (string) \get_user_meta( $user_id, 'first_name', true );

		if ( '' !== $wp_last || '' !== $wp_first ) {
			return $wp_last . $wp_first;
		}

		// ③ 最終 fallback 到 display_name
		return $user->display_name;
	}

	/**
	 * 取得用戶的姓（遵循 Fallback Chain：billing → WP meta）
	 *
	 * @param int $user_id 用戶 ID
	 * @return string 姓
	 */
	public static function get_last_name( int $user_id ): string {
		$user = \get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return '';
		}

		$billing_last = (string) \get_user_meta( $user_id, 'billing_last_name', true );
		if ( '' !== $billing_last ) {
			return $billing_last;
		}

		return (string) \get_user_meta( $user_id, 'last_name', true );
	}

	/**
	 * 取得用戶的名（遵循 Fallback Chain：billing → WP meta）
	 *
	 * @param int $user_id 用戶 ID
	 * @return string 名
	 */
	public static function get_first_name( int $user_id ): string {
		$user = \get_user_by( 'ID', $user_id );
		if ( ! $user ) {
			return '';
		}

		$billing_first = (string) \get_user_meta( $user_id, 'billing_first_name', true );
		if ( '' !== $billing_first ) {
			return $billing_first;
		}

		return (string) \get_user_meta( $user_id, 'first_name', true );
	}

	/**
	 * 匯出 CSV 用的帳單 / 運送 meta key 清單（Issue #238 F8 / Q5=B）
	 *
	 * 順序即 CSV 欄位順序：帳單 11 欄 + 運送 9 欄。
	 *
	 * @var array<string>
	 */
	public const EXPORT_ADDRESS_META_KEYS = [
		'billing_first_name',
		'billing_last_name',
		'billing_email',
		'billing_phone',
		'billing_company',
		'billing_country',
		'billing_state',
		'billing_city',
		'billing_postcode',
		'billing_address_1',
		'billing_address_2',
		'shipping_first_name',
		'shipping_last_name',
		'shipping_company',
		'shipping_country',
		'shipping_state',
		'shipping_city',
		'shipping_postcode',
		'shipping_address_1',
		'shipping_address_2',
	];

	/**
	 * 取得使用者的 billing_* / shipping_* meta 值（供匯出 CSV 使用，Issue #238 F8）
	 *
	 * @param int $user_id 使用者 ID。
	 * @return array<string, string> 以欄位 key 為鍵的 meta 值（缺值回空字串）。
	 */
	public static function get_address_meta_for_export( int $user_id ): array {
		$values = [];
		foreach ( self::EXPORT_ADDRESS_META_KEYS as $key ) {
			$meta_value     = \get_user_meta( $user_id, $key, true );
			$values[ $key ] = is_scalar( $meta_value ) ? (string) $meta_value : '';
		}

		return $values;
	}

	/**
	 * 取得課程的學生數量
	 *
	 * @param int $course_id 課程ID
	 * @return int
	 * @throws \Exception 課程ID為空時
	 */
	public static function count_student( int $course_id ): int {

		if ( !$course_id ) {
			throw new \Exception('Course ID is required');
		}

		global $wpdb;

		// 查找總數
		$total = $wpdb->get_var(
			$wpdb->prepare(
			'SELECT DISTINCT COUNT(DISTINCT u.ID) FROM %1$s u INNER JOIN %2$s um ON u.ID = um.user_id WHERE um.meta_key = "avl_course_ids" AND um.meta_value = "%3$s"',
			$wpdb->users,
			$wpdb->usermeta,
			(string) $course_id
		)); // phpcs:ignore

		return (int) $total;
	}
}
