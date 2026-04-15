<?php
/**
 * ExportAllCSV 全域學員匯出 CSV
 *
 * @package J7\PowerCourse
 */

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Student\Service;

use J7\Powerhouse\Utils\ExportCSV as ExportCSVBase;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\User as UserUtils;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;

/**
 * ExportAllCSV 匯出全域學員 CSV
 * 匯出所有符合篩選條件的學員 × 課程組合
 *
 * 使用方式：
 * 1. 建構時傳入篩選參數（search, avl_course_ids, include）
 * 2. 呼叫 export() 觸發 CSV 檔案下載
 * 3. 呼叫靜態方法 get_export_count() 取得預估匯出列數
 */
final class ExportAllCSV extends ExportCSVBase {

	/** @var string 檔案名稱 */
	protected string $filename = '';

	/** @var array<object{user_id: int, last_name: string, first_name: string, display_name: string, user_email: string, user_registered: string, course_name: string, course_id: int, progress: string, expire_date_label: string, is_expired: string, subscription_id: int|string}> 資料 */
	protected array $rows;

	/** @var array<string, string> 欄位名稱，預設會從 $row 身上拿屬性 */
	protected array $columns = [];

	/** @var string 搜尋關鍵字 */
	private string $search;

	/** @var array<string> 課程 ID 篩選 */
	private array $avl_course_ids;

	/** @var array<string> 指定用戶 ID */
	private array $include;

	/**
	 * Constructor
	 *
	 * @param string        $search         搜尋關鍵字。
	 * @param array<string> $avl_course_ids 課程 ID 篩選。
	 * @param array<string> $include        指定用戶 ID。
	 */
	public function __construct( string $search = '', array $avl_course_ids = [], array $include = [] ) {
		$this->search         = $search;
		$this->avl_course_ids = array_filter($avl_course_ids);
		$this->include        = array_filter($include);

		$this->filename = __( 'All students', 'power-course' );
		$this->rows     = $this->get_rows();

		$this->columns = [
			'user_id'           => __( 'Student ID', 'power-course' ),
			'last_name'         => __( 'Last name', 'power-course' ),
			'first_name'        => __( 'First name', 'power-course' ),
			'display_name'      => __( 'Display name', 'power-course' ),
			'user_email'        => __( 'Student email', 'power-course' ),
			'user_registered'   => __( 'Student registration date', 'power-course' ),
			'course_name'       => __( 'Course name', 'power-course' ),
			'course_id'         => __( 'Course ID', 'power-course' ),
			'progress'          => __( 'Watch progress', 'power-course' ),
			'expire_date_label' => __( 'Expire date', 'power-course' ),
			'is_expired'        => __( 'Is expired', 'power-course' ),
			'subscription_id'   => __( 'Subscription ID', 'power-course' ),
		];
	}

	/**
	 * 取得預估匯出列數（靜態方法，供 count API 使用）
	 *
	 * @param string        $search         搜尋關鍵字。
	 * @param array<string> $avl_course_ids 課程 ID 篩選。
	 * @param array<string> $include        指定用戶 ID。
	 * @return int
	 */
	public static function get_export_count( string $search = '', array $avl_course_ids = [], array $include = [] ): int {
		global $wpdb;

		$avl_course_ids = array_filter($avl_course_ids);
		$include        = array_filter($include);

		$sql = "SELECT COUNT(DISTINCT um.umeta_id) FROM {$wpdb->users} u"
		. " INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'avl_course_ids'";

		$needs_name_search = ! empty( $search );

		if ( $needs_name_search ) {
			$sql .= " LEFT JOIN {$wpdb->usermeta} um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = 'first_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = 'last_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_bfn ON u.ID = um_bfn.user_id AND um_bfn.meta_key = 'billing_first_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_bln ON u.ID = um_bln.user_id AND um_bln.meta_key = 'billing_last_name'";
		}

		$where = ' WHERE 1=1';
		$where .= self::build_where_conditions( $search, $avl_course_ids, $include );

		$sql .= $where;

		$count = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * 取得學員資料
	 *
	 * @return array<object{user_id: int, last_name: string, first_name: string, display_name: string, user_email: string, user_registered: string, course_name: string, course_id: int, progress: string, expire_date_label: string, is_expired: string, subscription_id: int|string}>
	 */
	private function get_rows(): array {
		try {
			$user_ids = $this->get_user_ids();

			if ( empty( $user_ids ) ) {
				return [];
			}

			$rows = [];

			PowerhouseUtils::batch_process(
				$user_ids,
				function ( $user_id ) use ( &$rows ) {
					$user = \get_user_by( 'id', (int) $user_id );
					if ( ! $user ) {
						return;
					}

					// 取得此用戶的所有課程
					$user_courses_raw = \get_user_meta( $user->ID, 'avl_course_ids' );
					$user_courses     = [];
					if ( \is_array( $user_courses_raw ) ) {
						foreach ( $user_courses_raw as $course_value ) {
							$user_courses[] = is_scalar( $course_value ) ? (string) $course_value : '';
						}
					}

					// 若有課程篩選，取交集
					if ( ! empty( $this->avl_course_ids ) ) {
						$user_courses = array_intersect( $user_courses, $this->avl_course_ids );
					}

					foreach ( $user_courses as $course_id ) {
						$course_id   = (int) $course_id;
						$expire_date = ExpireDate::instance( $course_id, $user->ID );

						$rows[] = (object) [
							'user_id'           => $user->ID,
							'last_name'         => UserUtils::get_last_name( $user->ID ),
							'first_name'        => UserUtils::get_first_name( $user->ID ),
							'display_name'      => $user->display_name,
							'user_email'        => $user->user_email,
							'user_registered'   => $user->user_registered,
							'course_name'       => \get_the_title( $course_id ),
							'course_id'         => $course_id,
							'progress'          => CourseUtils::get_course_progress( $course_id, $user->ID ) . '%',
							'expire_date_label' => $expire_date->expire_date_label,
							'is_expired'        => $expire_date->is_expired ? __( 'Yes', 'power-course' ) : __( 'No', 'power-course' ),
							'subscription_id'   => $expire_date->subscription_id ?? '',
						];
					}
				}
			);

			return $rows;
		} catch ( \Throwable $th ) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					/* translators: %s: 錯誤訊息 */
					__( 'Failed to export all students CSV, %s', 'power-course' ),
					$th->getMessage()
				),
				'error'
			);
			return [];
		}
	}

	/**
	 * 取得符合篩選條件的用戶 ID
	 *
	 * @return array<int>
	 */
	private function get_user_ids(): array {
		global $wpdb;

		$sql = "SELECT DISTINCT u.ID FROM {$wpdb->users} u"
		. " INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id AND um.meta_key = 'avl_course_ids'";

		$needs_name_search = ! empty( $this->search );

		if ( $needs_name_search ) {
			$sql .= " LEFT JOIN {$wpdb->usermeta} um_fn ON u.ID = um_fn.user_id AND um_fn.meta_key = 'first_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_ln ON u.ID = um_ln.user_id AND um_ln.meta_key = 'last_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_bfn ON u.ID = um_bfn.user_id AND um_bfn.meta_key = 'billing_first_name'"
			. " LEFT JOIN {$wpdb->usermeta} um_bln ON u.ID = um_bln.user_id AND um_bln.meta_key = 'billing_last_name'";
		}

		$where = ' WHERE 1=1';
		$where .= self::build_where_conditions( $this->search, $this->avl_course_ids, $this->include );

		$sql .= $where;
		$sql .= ' ORDER BY u.ID DESC';

		$user_ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', $user_ids );
	}

	/**
	 * 建構 WHERE 條件子句
	 *
	 * @param string        $search         搜尋關鍵字。
	 * @param array<string> $avl_course_ids 課程 ID 篩選。
	 * @param array<string> $include        指定用戶 ID。
	 * @return string
	 */
	private static function build_where_conditions( string $search, array $avl_course_ids, array $include ): string {
		global $wpdb;

		$where = '';

		// 課程 ID 篩選
		if ( ! empty( $avl_course_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $avl_course_ids ), '%s' ) );
			$where       .= $wpdb->prepare( " AND um.meta_value IN ({$placeholders})", ...$avl_course_ids ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// 指定用戶 ID 篩選
		if ( ! empty( $include ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $include ), '%d' ) );
			$where       .= $wpdb->prepare( " AND u.ID IN ({$placeholders})", ...array_map( 'intval', $include ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		// 搜尋篩選
		if ( ! empty( $search ) ) {
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= $wpdb->prepare(
				' AND (u.user_login LIKE %s OR u.user_nicename LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s'
				. ' OR um_fn.meta_value LIKE %s OR um_ln.meta_value LIKE %s'
				. ' OR um_bfn.meta_value LIKE %s OR um_bln.meta_value LIKE %s'
				. " OR CONCAT(COALESCE(um_bln.meta_value, ''), COALESCE(um_bfn.meta_value, '')) LIKE %s"
				. " OR CONCAT(COALESCE(um_ln.meta_value, ''), COALESCE(um_fn.meta_value, '')) LIKE %s"
				. ( \is_numeric( $search ) ? ' OR u.ID = %d' : '' )
				. ')',
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				...( \is_numeric( $search ) ? [ (int) $search ] : [] )
			);
		}

		return $where;
	}
}
