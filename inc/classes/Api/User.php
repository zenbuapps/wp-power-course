<?php

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\File;
use J7\WpUtils\Classes\UniqueArray;
use J7\PowerCourse\Resources\Course\Service\AddStudent;

/** Class Api */
final class User extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	const BATCH_SIZE = 50;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected $apis = [
		[
			'endpoint'            => 'users',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'users/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'users/add-teachers', // 設定為講師
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'users/remove-teachers', // 解除講師身分
			'method'              => 'post',
			'permission_callback' => null,
		],
		[
			'endpoint'            => 'users/upload-students', // CSV 新增學員
			'method'              => 'post',
			'permission_callback' => null,
		],
	];

	/** Constructor*/
	public function __construct() {
		parent::__construct();
		\add_action( 'pc_batch_add_students_task', [ $this, 'process_batch_add_students' ], 10, 4 );
	}

	/**
	 * Log 到 WC Logger 的指定檔名
	 *
	 * @param string               $message 訊息
	 * @param string               $level 等級
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	protected static function log( string $message, $level = 'info', array $args = [] ): void {
		\J7\WpUtils\Classes\WC::logger(
			$message,
			$level,
			$args,
			'power_course_csv_upload_students'
			);
	}

	/**
	 * 新增用戶
	 *
	 * @param \WP_REST_Request $request 包含新增用戶所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function post_users_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array{data: array<string, mixed>, meta_data: array<string, mixed>} $separated */
		$separated = WP::separator( $body_params, 'user' );
		/** @var array{ID?: int, user_pass?: string, user_login?: string, user_nicename?: string, user_url?: string, user_email?: string, display_name?: string, nickname?: string} $data */
		$data      = $separated['data'];
		/** @var array<string, mixed> $meta_data */
		$meta_data = $separated['meta_data'];

		$user_id = \wp_insert_user( $data );

		if (\is_wp_error($user_id)) {
			return new \WP_REST_Response(
			[
				'code'    => 'create_user_error',
				'message' => $user_id->get_error_message(),
				'data'    => null,
			],
			400
			);
		}

		foreach ( $meta_data as $key => $value ) {
			\update_user_meta($user_id, $key, $value );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'post_user_success',
				'message' => '修改成功',
				'data'    => [
					'id' => (string) $user_id,
				],
			],
			200
			);
	}




	/**
	 * Post user callback
	 * 修改 user
	 * 用 form-data 方式送出
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_users_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$user_id     = (int) $request['id'];
		$body_params = $request->get_body_params();
		$file_params = $request->get_file_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array{data: array<string, mixed>, meta_data: array<string, mixed>} $separated */
		$separated = WP::separator( $body_params, 'user', $file_params['files'] ?? null );
		/** @var array<string, mixed> $data */
		$data      = $separated['data'];
		/** @var array<string, mixed> $meta_data */
		$meta_data = $separated['meta_data'];

		$data['ID'] = $user_id;
		unset($meta_data['id']);

		$update_user_result = \wp_update_user( $data );

		$update_success = \is_numeric($update_user_result);

		foreach ( $meta_data as $key => $value ) {
			\update_user_meta($user_id, $key, $value );
		}

		return new \WP_REST_Response(
			[
				'code'    => $update_success ? 'post_user_success' : 'post_user_error',
				'message' => $update_success ? '修改成功' : '修改失敗',
				'data'    => [
					'id'                 => (string) $user_id,
					'update_user_result' => $update_user_result,
				],
			],
			$update_success ? 200 : 400
			);
	}

	/**
	 * 處理批次將用戶設定為講師的請求。
	 *
	 * @param \WP_REST_Request $request REST請求對象，包含需要處理的用戶ID。
	 * @return \WP_REST_Response 返回REST響應對象，包含操作結果的狀態碼和訊息。
	 */
	public function post_users_add_teachers_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		/** @var array<int|string> $user_ids */
		$user_ids = $body_params['user_ids'] ?? [];

		foreach ( $user_ids as $user_id ) {
			\update_user_meta( (int) $user_id, 'is_teacher', 'yes' );
		}

		return new \WP_REST_Response(
		[
			'code'    =>'update_users_to_teachers_success',
			'message' =>'批次將用戶轉為講師成功',
			'data'    => [
				'user_ids' => \implode(',', $user_ids),
			],
		],
		200
		);
	}

	/**
	 * 將指定用戶批次移除講師身分
	 *
	 * @param \WP_REST_Request $request 包含用戶ID的REST請求。
	 * @return \WP_REST_Response 包含操作結果的響應對象。
	 */
	public function post_users_remove_teachers_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		/** @var array<int|string> $user_ids */
		$user_ids = $body_params['user_ids'] ?? [];

		$update_success = false;
		foreach ( $user_ids as $user_id ) {
			$update_success = (bool) \delete_user_meta( (int) $user_id, 'is_teacher' );
			if (!$update_success) {
				break;
			}
		}

		return new \WP_REST_Response(
		[
			'code'    => $update_success ? 'remove_teachers_success' : 'remove_teachers_failed',
			'message' => $update_success ? '批次移除講師成功' : '批次移除講師失敗',
			'data'    => [
				'user_ids' => \implode(',', $user_ids),
			],
		],
		$update_success ? 200 : 400
		);
	}

	/**
	 * 上傳學員
	 *
	 * @param \WP_REST_Request $request 包含上傳學員資料的REST請求對象。
	 * @return \WP_REST_Response 包含操作結果的REST響應對象。
	 */
	public function post_users_upload_students_callback( \WP_REST_Request $request ): \WP_REST_Response {

		try {

			$file_params = $request->get_file_params();
			/**
			 * @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file
			 */
			$file = $file_params['files'];

			// 上傳到媒體庫
			$file_content = file_get_contents($file['tmp_name']);
			if ($file_content === false) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => '讀取上傳檔案失敗',
					'data'    => $file,
				],
				400
				);
			}
			$upload = \wp_upload_bits($file['name'], null, (string) $file_content);

			if ($upload['error'] !== false) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => '上傳學員失敗',
					'data'    => $file,
				],
				400
				);
			}

			$allowed_mime_types = [ 'text/csv', 'application/csv', 'text/comma-separated-values', 'application/excel', 'application/vnd.ms-excel', 'application/vnd.msexcel' ];
			// 限制檔案類型
			$wp_filetype = \wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mime_types);
			$attachment  = [
				'post_mime_type' => $wp_filetype['type'],
				'post_title'     => \sanitize_file_name($file['name']),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			/** @var int|\WP_Error $attachment_id */
			$attachment_id = \wp_insert_attachment($attachment, $upload['file']);

			if (\is_wp_error($attachment_id)) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => '上傳學員失敗 ' . $attachment_id->get_error_message(),
					'data'    => $file,
				],
				400
				);
			}

			// --- START 將 email_content 寫入到 txt 檔，不然太大傳參會 exception START ---
			$email_content_file_name    = 'pc_batch_upload_email_content_' . time() . '.txt';
			$email_content_file_content = sprintf("csv 匯入學員開始，每批次 %1\$d 筆 \n\n\n", self::BATCH_SIZE);

			// 獲取 WordPress 上傳目錄的路徑
			$upload_dir  = \wp_upload_dir();
			$upload_path = $upload_dir['path'];

			// 創建文字檔的完整路徑
			$email_content_file_path = "{$upload_path}/{$email_content_file_name}";

			// 初始化 WP_Filesystem
			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				\WP_Filesystem();
			}

			// 寫入文字檔內容
			$wp_filesystem->put_contents($email_content_file_path, $email_content_file_content, FS_CHMOD_FILE);

			// --- END 將 email content 寫入到 txt 檔，不然太大傳參會 exception END ---

			$action_id = \as_enqueue_async_action( 'pc_batch_add_students_task', [ $attachment_id, 0, self::BATCH_SIZE, $email_content_file_path ], 'power_course_batch_add_students' );

			// 寫入 DB
			return new \WP_REST_Response(
			[
				'code'    => 'upload_students_success',
				'message' => '已排程上傳學員成功，結果將用 EMAIL 通知',
				'data'    => [
					'action_id' => $action_id,
					'url'       => \admin_url('admin.php?page=wc-status&tab=action-scheduler&s=pc_batch_add_students_task'),
				],
			]
			);
		} catch (\Throwable $th) {
			return new \WP_REST_Response(
				[
					'code'    => 'upload_students_failed',
					'message' => '上傳學員失敗: ' . $th->getMessage(),
					'data'    => null,
				],
				400
			);
		}
	}

	/**
	 * 經過實測，大約連續創建 32個用戶，系統就會資源不足
	 * 每批數量建議100人
	 *
	 * @param int    $attachment_id 附件 ID
	 * @param int    $batch 批次
	 * @param int    $batch_size 每批數量
	 * @param string $email_content_file_path Email content file path
	 * @return void
	 */
	public function process_batch_add_students( int $attachment_id, int $batch, int $batch_size, string $email_content_file_path = '' ): void {
		$file = File::get_file_by_id($attachment_id);
		// 獲取當前批次的資料
		$current_batch_rows = File::parse_csv_streaming($file, $batch, $batch_size);
		$is_last_batch      = $batch === 0 ? count($current_batch_rows) < $batch_size - 1 : count($current_batch_rows) < $batch_size; // -1 是要扣掉標題欄
		// 去除重複

		$unique_array_instance = new UniqueArray($current_batch_rows);
		$unique_rows           = $unique_array_instance->get_list();
		$email_content         = '';
		$add_student           = new AddStudent();
		foreach ($unique_rows as $csv_row) {
			$email       = $csv_row[0];
			$course_id   = $csv_row[1];
			$expire_date = $csv_row[2] ?? 0;
			if (!\is_email($email) || !$course_id || !\is_numeric($course_id)) {
				continue;
			}

			$user = \get_user_by('email', $email);

			// ----- ▼ 判斷用戶存不存在，不存在就創建 ----- //

			if (!$user) {
				// 如果用戶不存在，要創建用戶，並且計送 EMAIL 設置密碼

				$username = $email;
				$password = \wp_generate_password(12);
				$user_id  = \wp_create_user($username, $password, $email);
				if (\is_wp_error($user_id)) {
					self::log("創建用戶失敗: {$user_id->get_error_message()}，用戶名稱: {$username}，用戶信箱: {$email}", 'error');
					continue;
				}

				// 發送重新設置密碼的信
				$result = \retrieve_password($username);

				if (true !== $result) {
					self::log("發送重新設置密碼的信失敗: {$result->get_error_message()}，用戶名稱: {$username}，用戶信箱: {$email}", 'error');
					continue;
				}
			} else {
				// 如果用戶已經存在，要先取得用戶 ID
				$user_id = (int) $user->ID;
				// 原本用戶已經可以上課，那就一樣覆蓋課程時間
			}

			// 處理 $expire_date，如果 是 subscription_ 開頭, 0, timestamp，則不需要處理
			if (!str_starts_with( (string) $expire_date, 'subscription_') && !\is_numeric($expire_date)) {
				$expire_date = WP::wp_strtotime($expire_date) ?? 0;
			}
			if (is_numeric($expire_date)) {
				$expire_date = (int) $expire_date;
			}

			$add_student->add_item( (int) $user_id, (int) $course_id, $expire_date, null );

			$email_content .= "用戶 #{$user_id} 獲得課程 #{$course_id} 權限，到期日 {$expire_date} \n\n";
		}

		$add_student->do_action();

		// ----- ▼ 寫入 log 以及 email 文字檔 ----- //
		$attachment_url = \wp_get_attachment_url($attachment_id);
		self::log($is_last_batch ? "附件 #{$attachment_id} {$attachment_url} csv 匯入 已經是最後一批，發信，結束" : "附件 #{$attachment_id} {$attachment_url} csv 匯入 還沒到最後一批，繼續", 'info');

		// 初始化 WP_Filesystem
		global $wp_filesystem;
		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		// 讀取原本檔案內容
		$old_content = $wp_filesystem->get_contents($email_content_file_path);

		// 新內容
		$new_content = $old_content . $email_content;

		// 寫入文字檔內容
		$wp_filesystem->put_contents($email_content_file_path, $new_content, FS_CHMOD_FILE);

		// 如果還有下一批資料,安排下一次執行
		if ( !$is_last_batch ) {
			$action_id = \as_enqueue_async_action(
			'pc_batch_add_students_task',
			[ $attachment_id, $batch + 1, $batch_size, $email_content_file_path ],
			'power_course_batch_add_students',
			);
			self::log("附件 #{$attachment_id} {$attachment_url} csv 匯入 下次排程 {$action_id}", 'info');
		} else {

			$upload_dir = \wp_upload_dir();

			// 標準化路徑
			$basedir   = \wp_normalize_path($upload_dir['basedir']);
			$file_path = \wp_normalize_path($email_content_file_path);

			// 取得相對路徑
			$relative_path = ltrim(str_replace($basedir, '', $file_path), '/');

			// 組合成 URL
			$file_url = \trailingslashit($upload_dir['baseurl']) . $relative_path;

			// 如果已經沒有下一批資料, 就發送 EMAIL
			$admin_email = (string) \get_option('admin_email');
			\wp_mail(
			$admin_email,
			sprintf('csv 匯入學員結果，共 %1$d 筆，共 %2$d 批次，每批次 %3$d 筆', ( $batch ) * $batch_size + count($current_batch_rows), $batch + 1, $batch_size),
			'<a href="' . $file_url . '">下載學員課程權限明細</a>',
			[
				'Content-Type: text/html; charset=UTF-8',
			]
			);
		}
	}
}
