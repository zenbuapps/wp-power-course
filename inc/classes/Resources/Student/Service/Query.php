<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Resources\Student\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Utils\Datetime;

/**
 * Query 查詢學員
 * */
final class Query {

	/** @var array<string> 合法的 progress_operator 白名單 */
	public const VALID_PROGRESS_OPERATORS = [ '=', '!=', '<', '<=', '>', '>=' ];

	/** @var array<int> 學員 ID */
	public array $user_ids = [];

	/** @var string 查詢條件 */
	private string $where;

	/** @var string progress filter 的 LEFT JOIN 子句 (空字串代表無 progress filter) */
	private string $progress_join = '';

	/** @var bool 是否因 0 章節短路：true → user_ids 已在 constructor 中決定 */
	private bool $short_circuited = false;

	/** @var int 短路情境下的總數 (避免 get_pagination 跑 SQL) */
	private int $short_circuit_total = 0;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $args 查詢參數
	 * @throws \Exception 如果 meta_value 為空 / progress 參數不合法
	 */
	public function __construct( private array $args ) {
		$default_args = [
			'search_columns'    => [ 'ID', 'user_login', 'user_email', 'user_nicename', 'display_name' ],
			'posts_per_page'    => 20,
			'order'             => 'DESC',
			'offset'            => 0,
			'paged'             => 1,
			'count_total'       => true,
			'meta_key'          => 'avl_course_ids',
			'meta_value'        => '',
			'progress_operator' => null,
			'progress_value'    => null,
		];

		$args = \wp_parse_args(
			$args,
			$default_args,
		);

		if ( ! $args['meta_value'] ) {
			throw new \Exception( __( 'meta_value cannot be empty, course_id not found', 'power-course' ) );
		}

		// 如果 $args['meta_value'] 有包含 ! 開頭，就用反查詢
		if (\str_starts_with( (string) $args['meta_value'], '!')) {
			$reverse   = true;
			$course_id = (int) substr($args['meta_value'], 1);
		} else {
			$reverse   = false;
			$course_id = (int) $args['meta_value'];
		}

		// 處理 progress filter：若有提供，準備好 LEFT JOIN + WHERE 片段
		$progress_where = $this->prepare_progress_filter( $course_id, $args );

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

		// 加入 progress LEFT JOIN 子句 (若 prepare_progress_filter 已決定要加)
		$sql .= $this->progress_join;

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

		// 加入 progress 篩選 WHERE 片段
		$where .= $progress_where;

		// 若已短路（0 章節 + progress filter 已判定），直接使用先前算好的 user_ids
		if ( $this->short_circuited ) {
			$this->where = $where;
			return;
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
	 * 準備 progress filter 的 LEFT JOIN 與 WHERE 片段
	 *
	 * 依規格：
	 * - progress_operator 與 progress_value 必須同時提供（缺一視為「無 progress filter」，由 Api 層另外把關 400）
	 * - operator 白名單：=, !=, <, <=, >, >=
	 * - value 為 0-100 整數
	 * - 課程 0 章節時所有學員視為 0%（短路處理，不進 SQL）
	 *
	 * 寫入 $this->progress_join、回傳 progress WHERE 片段（以 ' AND' 開頭，或空字串）
	 *
	 * @param int                  $course_id 課程 ID
	 * @param array<string, mixed> $args 查詢參數
	 * @return string progress WHERE 片段
	 * @throws \Exception 若 progress 參數不合法
	 */
	private function prepare_progress_filter( int $course_id, array $args ): string {
		$operator = $args['progress_operator'] ?? null;
		$value    = $args['progress_value'] ?? null;

		// 兩者皆 null → 無 progress filter
		if ( null === $operator && null === $value ) {
			return '';
		}

		// 只給一邊 → 視為「無 progress filter」（Api 層應已先攔 400，這裡防呆）
		if ( null === $operator || null === $value ) {
			return '';
		}

		// 白名單 + 範圍檢查 (Api 層已驗，這裡再防呆)
		if ( ! in_array( $operator, self::VALID_PROGRESS_OPERATORS, true ) ) {
			throw new \Exception( __( 'progress_operator must be one of =, !=, <, <=, >, >=', 'power-course' ) );
		}
		$int_value = (int) $value;
		if ( $int_value < 0 || $int_value > 100 ) {
			throw new \Exception( __( 'progress_value must be an integer between 0 and 100', 'power-course' ) );
		}

		// 取得課程章節列表
		$chapter_ids = ChapterUtils::get_flatten_post_ids( $course_id );

		// 0 章節 → 所有學員進度視為 0%，用 PHP 短路判斷
		if ( empty( $chapter_ids ) ) {
			$progress_zero_passes = $this->evaluate_zero_progress( $operator, $int_value );
			if ( $progress_zero_passes ) {
				// 所有已加入課程的學員都通過 → 不加 progress WHERE 片段，正常查詢
				return '';
			}
			// 所有學員都不通過 → 短路，user_ids 直接設為空
			$this->short_circuited     = true;
			$this->user_ids            = [];
			$this->short_circuit_total = 0;
			return ' AND 0 = 1';
		}

		global $wpdb;
		$chaptermeta_table = $wpdb->prefix . Plugin::CHAPTER_TABLE_NAME;
		$total_chapters    = count( $chapter_ids );

		// 章節 ID 已是 int (get_flatten_post_ids 回 array<int>)，但仍 absint 保險
		$chapter_id_list = implode( ',', array_map( 'absint', $chapter_ids ) );

		// LEFT JOIN derived table，計算每位用戶在這門課的進度百分比
		// 使用 COUNT(DISTINCT post_id) 避免重複 finished_at row 造成 >100%
		// LEAST(..., 100) clamp 上限為 100，與 CourseUtils::get_course_progress() 行為一致
		$this->progress_join = sprintf(
			' LEFT JOIN ('
			. 'SELECT user_id, LEAST(ROUND(COUNT(DISTINCT post_id) * 100 / %d, 1), 100) AS progress '
			. 'FROM %s '
			. "WHERE meta_key = 'finished_at' AND post_id IN (%s) "
			. 'GROUP BY user_id'
			. ') p ON p.user_id = u.ID',
			$total_chapters,
			$chaptermeta_table,
			$chapter_id_list
		);

		// COALESCE(p.progress, 0)：未完成任何章節（無 row）視為 0%
		// operator 已經白名單驗證，可直接寫入字面值（不會 injection）；value 強制轉 int
		return sprintf(
			' AND COALESCE(p.progress, 0) %s %d',
			$operator,
			$int_value
		);
	}

	/**
	 * 0 章節情境下，所有學員進度 = 0%
	 * 評估 0 vs progress_value 是否通過
	 *
	 * @param string $operator 運算子
	 * @param int    $value    比較值
	 * @return bool true = 通過 (所有學員都符合)；false = 不通過 (所有學員都不符合)
	 */
	private function evaluate_zero_progress( string $operator, int $value ): bool {
		switch ( $operator ) {
			case '=':
				return 0 === $value;
			case '!=':
				return 0 !== $value;
			case '<':
				return 0 < $value;
			case '<=':
				return 0 <= $value;
			case '>':
				return 0 > $value;
			case '>=':
				return 0 >= $value;
			default:
				return false;
		}
	}

	/**
	 * 取得分頁資訊
	 *
	 *  @return object{total: int, total_pages: int}
	 * */
	public function get_pagination(): object {
		// 短路情境下，total 早已決定
		if ( $this->short_circuited ) {
			return (object) [
				'total'       => $this->short_circuit_total,
				'total_pages' => 0,
			];
		}

		global $wpdb;
		// 查找總數：包含 progress LEFT JOIN（若有）以反映篩選後筆數
		$count_query = $wpdb->prepare(
				'SELECT COUNT(DISTINCT u.ID) FROM %1$s u INNER JOIN %2$s um ON u.ID = um.user_id',
				$wpdb->users,
				$wpdb->usermeta,
				) . $this->progress_join . $this->where;

				$total = $wpdb->get_var($count_query); // phpcs:ignore

		$posts_per_page = (int) $this->args['posts_per_page'];
		if ( $posts_per_page <= 0 ) {
			$total_pages = 0;
		} else {
			// 改用 ceil 正確計算頁數（規格要求 total=2 / per_page=1 → total_pages=2，不是 3）
			$total_pages = (int) \ceil( ( (int) $total ) / $posts_per_page );
		}

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

	/**
	 * 取得單一學員詳情（含擁有課程清單）
	 * 封裝 \get_user_by() 加上 avl_course_ids meta，供 MCP student_get tool 使用
	 *
	 * @param int $user_id 學員 ID
	 * @return array{user_id: int, user_login: string, user_email: string, display_name: string, user_registered: string, first_name: string, last_name: string, avl_course_ids: array<int>}|\WP_Error
	 *         學員資料 array，或找不到時回傳 WP_Error
	 */
	public static function get( int $user_id ): array|\WP_Error {
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'student_invalid_id',
				\__( 'user_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$user = \get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'student_not_found',
				\__( '找不到指定的學員', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$raw_courses    = \get_user_meta( $user_id, 'avl_course_ids' );
		$avl_course_ids = \is_array( $raw_courses )
		? array_values( array_map( 'intval', $raw_courses ) )
		: [];

		return [
			'user_id'         => (int) $user->ID,
			'user_login'      => (string) $user->user_login,
			'user_email'      => (string) $user->user_email,
			'display_name'    => (string) $user->display_name,
			'user_registered' => Datetime::to_site_timezone( (string) $user->user_registered ),
			'first_name'      => (string) \get_user_meta( $user_id, 'first_name', true ),
			'last_name'       => (string) \get_user_meta( $user_id, 'last_name', true ),
			'avl_course_ids'  => $avl_course_ids,
		];
	}
}
