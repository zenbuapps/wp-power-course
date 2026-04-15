<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Resources\Student\Service;

/**
 * Query 查詢學員
 * */
final class Query {

	/** @var array<int> 學員 ID */
	public array $user_ids = [];

	/** @var string 查詢條件 */
	private string $where;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $args 查詢參數
	 * @throws \Exception 如果 meta_value 為空
	 *  */
	public function __construct( private array $args ) {
		$default_args = [
			'search_columns' => [ 'ID', 'user_login', 'user_email', 'user_nicename', 'display_name' ],
			'posts_per_page' => 20,
			'order'          => 'DESC',
			'offset'         => 0,
			'paged'          => 1,
			'count_total'    => true,
			'meta_key'       => 'avl_course_ids',
			'meta_value'     => '',
		];

		$args = \wp_parse_args(
			$args,
			$default_args,
			);

		if ( ! $args['meta_value'] ) {
			throw new \Exception('meta_value 不能為空，找不到 course_id');
		}

		// 如果 $args['meta_value'] 有包含 ! 開頭，就用反查詢
		if (\str_starts_with( (string) $args['meta_value'], '!')) {
			$reverse   = true;
			$course_id = substr($args['meta_value'], 1);
		} else {
			$reverse   = false;
			$course_id = $args['meta_value'];
		}

		global $wpdb;

		// 判斷是否需要 JOIN usermeta 搜尋姓名欄位
		$needs_name_search = ! empty( $args['search'] )
		&& in_array( $args['search_field'] ?? 'default', [ 'name', 'default' ], true );

		if (!$reverse) {
			$sql = $wpdb->prepare(
			'SELECT u.ID FROM %1$s u INNER JOIN %2$s um ON u.ID = um.user_id',
			$wpdb->users,
			$wpdb->usermeta,
			);
		} else {
			$sql = $wpdb->prepare(
			'SELECT u.ID FROM %1$s u ',
			$wpdb->users,
			);
		}

		// 搜尋 name 模式時，LEFT JOIN usermeta 擴充搜尋 billing/WP meta 姓名
		if ( $needs_name_search ) {
			$sql .= $wpdb->prepare(
				' LEFT JOIN %1$s um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = "first_name"'
				. ' LEFT JOIN %1$s um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = "last_name"'
				. ' LEFT JOIN %1$s um_bfn ON u.ID = um_bfn.user_id AND um_bfn.meta_key = "billing_first_name"'
				. ' LEFT JOIN %1$s um_bln ON u.ID = um_bln.user_id AND um_bln.meta_key = "billing_last_name"',
				$wpdb->usermeta,
			);
		}

		if (!$reverse) {
			$where = $wpdb->prepare(
			" WHERE um.meta_key = '%1\$s'
			AND um.meta_value = '%2\$s'",
			$args['meta_key'],
			$args['meta_value']
			);
		} else {
			$where = $wpdb->prepare(
			" WHERE u.ID NOT IN ( SELECT DISTINCT u.ID FROM %1\$s u LEFT JOIN %2\$s um ON u.ID = um.user_id WHERE um.meta_key = '%3\$s' AND um.meta_value = '%4\$s'
			) ",
				$wpdb->users,
				$wpdb->usermeta,
				$args['meta_key'],
				$course_id
				);
		}

		if (!empty($args['search'])) {
			$search_value = $args['search'];
			// 姓名 meta 搜尋條件（billing_first_name, billing_last_name, first_name, last_name）
			$name_meta_search = "um_fn.meta_value LIKE '%{$search_value}%'"
			. " OR um_ln.meta_value LIKE '%{$search_value}%'"
			. " OR um_bfn.meta_value LIKE '%{$search_value}%'"
			. " OR um_bln.meta_value LIKE '%{$search_value}%'"
			. " OR CONCAT(COALESCE(um_bln.meta_value, ''), COALESCE(um_bfn.meta_value, '')) LIKE '%{$search_value}%'"
			. " OR CONCAT(COALESCE(um_ln.meta_value, ''), COALESCE(um_fn.meta_value, '')) LIKE '%{$search_value}%'";

			$where .= ' AND (';
			$where .= match ($args['search_field']) {
				'email'=> "u.user_email LIKE '%{$search_value}%'",
				'name'=> "u.user_login LIKE '%{$search_value}%' OR u.user_nicename LIKE '%{$search_value}%' OR u.display_name LIKE '%{$search_value}%' OR {$name_meta_search}",
				'id' => \is_numeric($search_value) ? "u.ID = {$search_value}" : '',
				default => "u.user_login LIKE '%{$search_value}%' OR u.user_nicename LIKE '%{$search_value}%' OR u.display_name LIKE '%{$search_value}%' OR u.user_email LIKE '%{$search_value}%'" . ( \is_numeric($search_value) ? " OR u.ID = {$search_value}" : '' ) . " OR {$name_meta_search}",
			};
			$where .= ')';
		}

		$sql .= $where;
		$sql .= $wpdb->prepare(
			' ORDER BY %1$s DESC ',
			$reverse ? 'u.ID' : 'um.umeta_id'
			);
		if ('-1' !== (string) $args['posts_per_page']) {
			$sql .= $wpdb->prepare('LIMIT %1$d OFFSET %2$d', $args['posts_per_page'], ( ( $args['paged'] - 1 ) * $args['posts_per_page'] ));
		}

		$user_ids = $wpdb->get_col( $sql); // phpcs:ignore
		$this->user_ids = \array_unique($user_ids);
		$this->where    = $where;
	}

	/**
	 * 取得分頁資訊
	 *
	 *  @return object{total: int, total_pages: int}
	 * */
	public function get_pagination(): object {
		global $wpdb;
		// 查找總數
		$count_query = $wpdb->prepare(
					'SELECT DISTINCT COUNT(DISTINCT u.ID) FROM %1$s u INNER JOIN %2$s um ON u.ID = um.user_id',
						$wpdb->users,
						$wpdb->usermeta,
					) . $this->where;

					$total = $wpdb->get_var($count_query); // phpcs:ignore

		$total_pages = \floor( ( (int) $total )/ ( (int) $this->args['posts_per_page'] ) ) + 1;

		return (object) [
			'total'       => (int) $total,
			'total_pages' => (int) $total_pages,
		];
	}

	/**
	 * 取得學員資料
	 *
	 * @return \WP_User[]
	 * */
	public function get_users(): array {
		$users = array_map( fn( $user_id ) => \get_user_by('id', $user_id), $this->user_ids );
		$users = array_filter($users);

		return $users;
	}
}
