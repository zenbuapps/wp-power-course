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
		$this->filename    = "{$this->course_name}學員名單";

		$this->rows = $this->get_rows();

		$this->columns = [
			'user_id'           => '學員 ID',
			'last_name'         => '姓',
			'first_name'        => '名',
			'display_name'      => '顯示名稱',
			'user_email'        => '學員 Email',
			'user_registered'   => '學員註冊時間',
			'course_name'       => '課程名稱',
			'course_id'         => '課程 ID',
			'progress'          => '學習進度',
			'expire_date_label' => '觀看期限',
			'is_expired'        => '是否過期',
			'subscription_id'   => '訂閱 ID',
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
					'is_expired'        => $expire_date->is_expired ? '是' : '否',
					'subscription_id'   => $expire_date->subscription_id ?? '',
				];
			}
			);

			return $rows;
		} catch (\Throwable $th) {
			\J7\WpUtils\Classes\WC::logger(
				"課程 #{$this->course_id} 學員 CSV 匯出失敗，{$th->getMessage()}",
				'error'
			);
			return [];
		}
	}
}
