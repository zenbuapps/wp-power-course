<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Resources\Student\Service;

use J7\Powerhouse\Utils\ExportCSV as ExportCSVBase;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\User as UserUtils;
use J7\PowerCourse\Resources\Course\ExpireDate;
use J7\Powerhouse\Utils\Base as PowerhouseUtils;

/**
 * ExportCSV 匯出 CSV
 * 使用方式
 * 1. 定義 $rows 資料源
 * 2. 定義 $filename, $columns
 * 3. 呼叫 export()
 * */
final class ExportCSV extends ExportCSVBase {

	/** @var string 檔案名稱 */
	protected string $filename;

	/** @var array<object{user_id: int, last_name: string, first_name: string, display_name: string, user_email: string, user_registered: string, course_name: string, course_id: int, progress: string, expire_date_label: string, is_expired: string, subscription_id: int|string}> 資料 */
	protected array $rows;

	/** @var array<string, string> 欄位名稱，預設會從 $row 身上拿屬性 */
	protected array $columns = [];

	/** @var string 課程名稱 */
	private string $course_name;

	/**
	 * Constructor
	 *
	 * @param int $course_id 課程 ID
	 */
	public function __construct( private int $course_id ) {
		$this->course_name = \get_the_title($this->course_id);
		$this->filename    = sprintf(
			/* translators: %s: 課程名稱 */
			__( '%s students', 'power-course' ),
			$this->course_name
		);

		$this->rows = $this->get_rows();

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
	 * 取得學員資料
	 *
	 * @return array<object{user_id: int, last_name: string, first_name: string, display_name: string, user_email: string, user_registered: string, course_name: string, course_id: int, progress: string, expire_date_label: string, is_expired: string, subscription_id: int|string}>
	 * */
	private function get_rows(): array {
		try {

			$query = new Query(
			[
				'posts_per_page' => -1,
				'meta_key'       => 'avl_course_ids',
				'meta_value'     => $this->course_id,
			]
			);

			$users = $query->get_users();

			$rows = [];

			PowerhouseUtils::batch_process(
			$users,
			function ( $user ) use ( &$rows ) {
				$expire_date = ExpireDate::instance($this->course_id, $user->ID);

				$rows[] = (object) [
					'user_id'           => $user->ID,
					'last_name'         => UserUtils::get_last_name( $user->ID ),
					'first_name'        => UserUtils::get_first_name( $user->ID ),
					'display_name'      => $user->display_name,
					'user_email'        => $user->user_email,
					'user_registered'   => $user->user_registered,
					'course_name'       => $this->course_name,
					'course_id'         => $this->course_id,
					'progress'          => CourseUtils::get_course_progress( $this->course_id, $user->ID ) . '%',
					'expire_date_label' => $expire_date->expire_date_label,
					'is_expired'        => $expire_date->is_expired ? __( 'Yes', 'power-course' ) : __( 'No', 'power-course' ),
					'subscription_id'   => $expire_date->subscription_id ?? '',
				];
			}
			);

			return $rows;
		} catch (\Throwable $th) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					/* translators: 1: 課程 ID, 2: 錯誤訊息 */
					__( 'Failed to export students CSV for course #%1$d, %2$s', 'power-course' ),
					$this->course_id,
					$th->getMessage()
				),
				'error'
			);
			return [];
		}
	}
}
