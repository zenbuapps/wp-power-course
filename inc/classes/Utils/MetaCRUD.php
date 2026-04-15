<?php
/**
 * 對 AVL Coursemeta / ChapterMeta 等 table 的 CRUD 抽象
 * AVLCourse = 用戶可以上的課程，額外資訊就是 AVLCourseMeta
 * AVLChapter = 章節，額外資訊就是 AVLChapterMeta
 * TODO 新增到 wp-utils
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

/**
 * Class AVLCourseMeta
 */
abstract class MetaCRUD {

	/**
	 * 對應的 table name
	 *
	 * @var string
	 */
	public static string $table_name = '';

	/**
	 * Adds a meta value for a specific course and user in the AVL Course Meta class.
	 *
	 * @param int    $post_id The ID of the course.
	 * @param int    $user_id The ID of the user.
	 * @param string $meta_key The key of the meta value.
	 * @param mixed  $meta_value The value of the meta data.
	 * @param bool   $unique Optional. Whether the same key should not be added. Default is false.
	 * @return int|false The ID of the newly added meta data, or false on failure.
	 */
	public static function add( int $post_id, int $user_id, string $meta_key, mixed $meta_value, ?bool $unique = false ): int|false {
		global $wpdb;

		$table_name = $wpdb->prefix . static::$table_name;

		$data = [
			'post_id'    => $post_id,
			'user_id'    => $user_id,
			'meta_key'   => $meta_key,
			'meta_value' => \maybe_serialize( $meta_value ),
		];

		if (!$unique) {
			return $wpdb->insert(
				$table_name,
				$data,
				[ '%d', '%d', '%s', '%s' ]
			);
		} else {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT meta_id FROM %1\$s WHERE post_id = %2\$d AND user_id = %3\$d AND meta_key = '%4\$s'",
					$table_name,
					$post_id,
					$user_id,
					$meta_key
				)
			);

			if ($exists) {
				return $wpdb->update(
					$table_name,
					[ 'meta_value' => \maybe_serialize( $meta_value ) ],
					[ 'meta_id' => $exists ],
					[ '%s' ],
					[ '%d' ]
				);
			} else {
				return $wpdb->insert(
					$table_name,
					$data,
					[ '%d', '%d', '%s', '%s' ]
				);
			}
		}
	}

	/**
	 * Updates the meta value for a specific course and user in the AVL course meta table.
	 *
	 * @param int    $post_id   The ID of the course.
	 * @param int    $user_id     The ID of the user.
	 * @param string $meta_key    The meta key.
	 * @param mixed  $meta_value  The meta value.
	 * @param mixed  $prev_value  Optional. The previous value to update. Default is null.
	 *
	 * @return int|false The number of rows affected on success, or false on failure.
	 */
	public static function update( int $post_id, int $user_id, string $meta_key, mixed $meta_value, mixed $prev_value = null ): int|false {

		global $wpdb;

		$table_name = $wpdb->prefix . static::$table_name;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT meta_id FROM %1\$s WHERE post_id = %2\$d AND user_id = %3\$d AND meta_key = '%4\$s'",
				$table_name,
				$post_id,
				$user_id,
				$meta_key
			)
		);

		if (!$exists) {
			return $wpdb->insert(
				$table_name,
				[
					'post_id'    => $post_id,
					'user_id'    => $user_id,
					'meta_key'   => $meta_key,
					'meta_value' => \maybe_serialize( $meta_value ),
				],
				[ '%d', '%d', '%s', '%s' ]
			);
		}

		if (!$prev_value) {
			return $wpdb->update(
				$table_name,
				[ // data
					'meta_value' => \maybe_serialize( $meta_value ),
				],
				[ // where
					'post_id'  => $post_id,
					'user_id'  => $user_id,
					'meta_key' => $meta_key,
				],
				[ // format
					'%s',
				],
				[ // where format
					'%d',
					'%d',
					'%s',
				]
			);
		} else {
			return $wpdb->update(
				$table_name,
				[ // data
					'meta_value' => \maybe_serialize( $meta_value ),
				],
				[ // where
					'post_id'    => $post_id,
					'user_id'    => $user_id,
					'meta_key'   => $meta_key,
					'meta_value' => \maybe_serialize( $prev_value ),
				],
				[ // format
					'%s',
				],
				[ // where format
					'%d',
					'%d',
					'%s',
					'%s',
				]
			);

		}
	}

	/**
	 * Retrieves the available course meta for a specific course and user.
	 *
	 * @param int    $post_id The ID of the course.
	 * @param int    $user_id   The ID of the user.
	 * @param string $meta_key  The meta key to retrieve.
	 * @param bool   $single    Optional. Whether to return a single value or an array of values. Default is false.
	 *
	 * @return mixed The course meta value(s).
	 */
	public static function get( int $post_id, int $user_id, string $meta_key, ?bool $single = false ) {
		global $wpdb;

		$table_name = $wpdb->prefix . static::$table_name;

		if (empty($meta_key)) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT meta_key, meta_value FROM %1$s WHERE post_id = %2$d AND user_id = %3$d',
					$table_name,
					$post_id,
					$user_id
				)
			);
			return \wp_list_pluck($results, 'meta_value', 'meta_key');
		}

		$meta_value = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM %1\$s WHERE post_id = %2\$d AND user_id = %3\$d AND meta_key = '%4\$s'",
				$table_name,
				$post_id,
				$user_id,
				$meta_key
			)
		);

		if ($single) {
			if (empty($meta_value)) {
				return '';
			}
			return \maybe_unserialize($meta_value[0]);
		} else {
			return array_map('maybe_unserialize', $meta_value);
		}
	}


	/**
	 * 查詢 AVL 的 metatable
	 *
	 * @param array<string>             $select 要查詢的欄位
	 * @param array<string, string|int> $where 要查詢的條件
	 * @return array<int, mixed>
	 */
	public static function query( array $select, array $where ): array {
		global $wpdb;

		$select_sql  = implode(', ', $select);
		$where_array = [];
		foreach ($where as $key => $value) {
			$where_array[] = "{$key} = '{$value}'";
		}
		$where_sql = implode(' AND ', $where_array);

		$table_name = $wpdb->prefix . static::$table_name;
		$results    = $wpdb->get_results(
			stripslashes( // phpcs:ignore
			$wpdb->prepare(
				'SELECT %1$s FROM %2$s WHERE %3$s',
				$select_sql,
				$table_name,
				$where_sql,
			)
		)
			);

		return $results;
	}

	/**
	 * Deletes the available course meta for a specific course and user.
	 *
	 * @param int         $post_id   The ID of the course.
	 * @param int         $user_id     The ID of the user.
	 * @param string|null $meta_key    Optional. The meta key to delete. Default is null.
	 * @param mixed       $meta_value  Optional. The meta value to delete. Default is an empty string.
	 *
	 * @return int|false 移除的數量, or false on error.
	 */
	public static function delete( int $post_id, int $user_id, string|null $meta_key = null, mixed $meta_value = '' ): int|false {
		global $wpdb;
		$table_name = $wpdb->prefix . static::$table_name;

		if (null === $meta_key) {
			return $wpdb->delete(
				$table_name,
				[
					'post_id' => $post_id,
					'user_id' => $user_id,
				],
				[ '%d', '%d' ]
			);
		}

		if (!empty($meta_value)) {
			return $wpdb->delete(
				$table_name,
				[
					'post_id'    => $post_id,
					'user_id'    => $user_id,
					'meta_key'   => $meta_key,
					'meta_value' => \maybe_serialize($meta_value),
				],
				[
					'%d',
					'%d',
					'%s',
					'%s',
				]
			);
		} else {
			return $wpdb->delete(
				$table_name,
				[
					'post_id'  => $post_id,
					'user_id'  => $user_id,
					'meta_key' => $meta_key,
				],
				[
					'%d',
					'%d',
					'%s',
				]
			);
		}
	}
}
