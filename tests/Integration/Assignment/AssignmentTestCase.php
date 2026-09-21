<?php
/**
 * 作業（Assignment）整合測試共用基底
 *
 * 提供 P2～P6 共用的 Given/When/Then helper：
 *   - 站台時區固定為 Asia/Taipei，並提供 set_now() 讓「現在時間為」的 Given 步驟可注入
 *     Utils\Clock 讀取的 power_course_assignment_now filter（DEC-02）
 *   - create_assignment()：以 wp_insert_post + meta 建立測試作業（不經 REST，對齊 aggregate-given）
 *   - seed_submission() / seed_attachment() / seed_message()：直接 $wpdb->insert 三張自訂表
 *   - dispatch()：一律走 rest_get_server()->dispatch()，權限測試才會真的經過 permission_callback
 *   - capture_mail()：以 pre_wp_mail 攔截寄信，不依賴 wp_mail filter（那個 filter 過的是參數不是回傳值）
 *   - run_scheduled_actions()：取出 Action Scheduler 的 pending action 逐筆執行後標記完成
 *   - assert_assignment_fields() / assert_assignment_not_exists()：讀 wp_posts + postmeta 斷言作業欄位
 *   - assert_submission_exists() / assert_submission_not_exists()：讀 pc_assignment_submissions 斷言
 *   - assert_message_exists() / assert_message_not_exists()：讀 pc_assignment_messages 斷言
 *   - assert_attachment_exists()：讀 pc_assignment_attachments 斷言
 *   - assert_permission_denied() / assert_not_logged_in() / assert_operation_failed_response()：
 *     REST 錯誤回應斷言（DEC-01：權限不足 = 403 rest_forbidden，必須登入 = 401 rest_not_logged_in）
 *   - assert_success_response() / assert_response_includes() / assert_response_list_ids()：
 *     REST 成功回應斷言
 *   - create_email_template()（P5）：建立 pe_email 郵件模板（trigger_at + condition.course_ids/sending）
 *   - assert_mail_sent_to() / assert_mail_content_contains()（P5）：比對 capture_mail() 攔截到的收件人 / 內容
 *   - get_next_scheduled_action_timestamp()（P5）：取得 Action Scheduler 指定 hook 最早 pending 排程時間，
 *     用於驗證「立即寄送、不套用模板延遲設定」（DEC-10）
 *
 * ─── 為什麼本檔不會讓所有測試一起 fatal ─────────────────────────
 * 本檔不在 class 宣告層級（extends / implements / 常數預設值）引用任何尚未存在的
 * Assignment production 符號；唯一用到的 production 符號
 * （J7\PowerCourse\Resources\Chapter\Utils\Utils、J7\PowerCourse\Plugin）皆為既有類別，
 * P1 已將三個 ASSIGNMENT_*_TABLE_NAME 常數與三個 create_assignment_*_table() 合入 master，
 * 所以此處的 use 與方法呼叫都安全。'pc_assignment' post_type 一律用字面字串，不依賴
 * 尚未實作的 Core\CPT 類別，子測試類別可各自在需要時才引用 Core\CPT（方法內），
 * 讓紅燈原因精準落在該測試本身，而不是整批栽在共用基底上。
 * ────────────────────────────────────────────────────────────
 *
 * @group assignment
 * @group issue-131
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;

/**
 * Class AssignmentTestCase
 * 作業相關整合測試共用基底（abstract，不以 Test.php 結尾）
 */
abstract class AssignmentTestCase extends TestCase {

	/** 作業 CPT 的 post_type（字面字串，DEC-05；不依賴尚未實作的 Core\CPT） */
	protected const ASSIGNMENT_POST_TYPE = 'pc_assignment';

	/** @var string 測試前的 timezone_string，tear_down 還原 */
	private string $original_timezone_string = '';

	/** @var string 測試前的 gmt_offset，tear_down 還原 */
	private string $original_gmt_offset = '';

	/** @var callable|null set_now() 掛上的 power_course_assignment_now filter callback */
	private $now_filter = null;

	/** @var array<int, array{to: mixed, subject: string, message: string, headers: mixed, attachments: mixed}> capture_mail() 攔截到的寄信紀錄 */
	protected array $captured_mails = [];

	/** @var bool capture_mail() 是否已掛上 pre_wp_mail filter */
	private bool $mail_captured = false;

	/** @var array<int, string> seed_attachment() 建立的暫存實體檔案路徑，tear_down 時刪除 */
	private array $seeded_attachment_files = [];

	/** @var string|null use_temp_storage_dir() 建立的暫存儲存目錄（絕對路徑，結尾帶 /），tear_down 時遞迴刪除（P3） */
	private ?string $temp_storage_dir = null;

	/** @var callable|null use_temp_storage_dir() 掛上的 power_course_assignment_storage_dir filter callback（P3） */
	private $storage_dir_filter = null;

	/**
	 * 初始化依賴（本基底不需要 repos/services，子類別視需要覆寫）
	 */
	protected function configure_dependencies(): void {
	}

	/**
	 * 設定（每個測試前執行）
	 * 固定站台時區為 Asia/Taipei，讓 feature 中的字面時間視為站台時區
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_timezone_string = (string) \get_option( 'timezone_string' );
		$this->original_gmt_offset      = (string) \get_option( 'gmt_offset' );

		\update_option( 'timezone_string', 'Asia/Taipei' );
		\update_option( 'gmt_offset', '' );

		$this->captured_mails = [];
	}

	/**
	 * 清理（每個測試後執行）
	 * 還原時區設定、移除 set_now() / capture_mail() 掛上的 filter、刪除測試建立的暫存檔案
	 */
	public function tear_down(): void {
		\update_option( 'timezone_string', $this->original_timezone_string );
		\update_option( 'gmt_offset', $this->original_gmt_offset );

		if ( null !== $this->now_filter ) {
			\remove_filter( 'power_course_assignment_now', $this->now_filter );
			$this->now_filter = null;
		}

		if ( $this->mail_captured ) {
			\remove_filter( 'pre_wp_mail', [ $this, 'record_mail' ] );
			$this->mail_captured = false;
		}

		foreach ( $this->seeded_attachment_files as $file ) {
			if ( \is_string( $file ) && '' !== $file && \file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				\unlink( $file );
			}
		}
		$this->seeded_attachment_files = [];

		// P3：移除 use_temp_storage_dir() 掛上的 filter 並遞迴刪除暫存目錄
		if ( null !== $this->storage_dir_filter ) {
			\remove_filter( 'power_course_assignment_storage_dir', $this->storage_dir_filter );
			$this->storage_dir_filter = null;
		}
		if ( null !== $this->temp_storage_dir ) {
			$this->remove_dir_recursive( $this->temp_storage_dir );
			$this->temp_storage_dir = null;
		}

		parent::tear_down();
	}

	// ========== 儲存目錄（DEC-09，P3 上傳測試用） ==========

	/**
	 * 建立測試專用的暫存儲存目錄，並掛上 power_course_assignment_storage_dir filter
	 * 導向該目錄，避免上傳測試寫入真實的 wp_upload_dir()（DEC-09）
	 *
	 * @return string 暫存儲存目錄絕對路徑（結尾帶 /）
	 */
	protected function use_temp_storage_dir(): string {
		if ( null !== $this->temp_storage_dir ) {
			return $this->temp_storage_dir;
		}

		$dir = \rtrim( (string) \sys_get_temp_dir(), '/\\' ) . '/pc-assignment-test-' . \uniqid() . '/';
		\wp_mkdir_p( $dir );

		$this->temp_storage_dir = $dir;

		$this->storage_dir_filter = static function () use ( $dir ): string {
			return $dir;
		};
		\add_filter( 'power_course_assignment_storage_dir', $this->storage_dir_filter );

		return $dir;
	}

	/**
	 * 遞迴刪除目錄（use_temp_storage_dir() 的 tear_down 用）
	 *
	 * @param string $dir 目錄絕對路徑
	 */
	private function remove_dir_recursive( string $dir ): void {
		if ( '' === $dir || ! \is_dir( $dir ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$items = @\scandir( $dir ) ?: [];
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( \is_dir( $path ) ) {
				$this->remove_dir_recursive( $path );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@\unlink( $path );
			}
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		@\rmdir( $dir );
	}

	// ========== 時間（DEC-02） ==========

	/**
	 * 設定「現在時間」，以站台時區換算成 timestamp 後掛上 power_course_assignment_now filter
	 *
	 * @param string $datetime 站台時區時間字串，格式 'Y-m-d H:i:s'
	 * @return int 換算後的 Unix timestamp
	 */
	protected function set_now( string $datetime ): int {
		$timestamp = ( new \DateTimeImmutable( $datetime, \wp_timezone() ) )->getTimestamp();

		if ( null !== $this->now_filter ) {
			\remove_filter( 'power_course_assignment_now', $this->now_filter );
		}

		$this->now_filter = static function () use ( $timestamp ): int {
			return $timestamp;
		};
		\add_filter( 'power_course_assignment_now', $this->now_filter );

		return $timestamp;
	}

	// ========== 資料建立（aggregate-given） ==========

	/**
	 * 建立測試作業（wp_insert_post + meta，parent_course_id 由章節解析）
	 *
	 * @param int                  $chapter_id 所屬章節 ID（pc_chapter，任意層級）
	 * @param array<string, mixed> $args 覆蓋預設值：title, instructions, allow_text, allow_file,
	 *                                   required_text, required_file, max_files, due_at, menu_order, post_status
	 * @return int 作業（post）ID
	 */
	protected function create_assignment( int $chapter_id, array $args = [] ): int {
		$defaults = [
			'title'         => '測試作業',
			'instructions'  => '',
			'allow_text'    => 'yes',
			'allow_file'    => 'no',
			'required_text' => 'no',
			'required_file' => 'no',
			'max_files'     => 1,
			'due_at'        => 0,
			'menu_order'    => 0,
			'post_status'   => 'publish',
		];
		$args = \wp_parse_args( $args, $defaults );

		$parent_course_id = $chapter_id ? ( ChapterUtils::get_course_id( $chapter_id ) ?? 0 ) : 0;

		$post_id = \wp_insert_post(
			\wp_slash(
				[
					'post_type'    => self::ASSIGNMENT_POST_TYPE,
					'post_title'   => (string) $args['title'],
					'post_content' => (string) $args['instructions'],
					'post_status'  => (string) $args['post_status'],
					'post_parent'  => $chapter_id,
					'menu_order'   => (int) $args['menu_order'],
				]
			)
		);

		if ( \is_wp_error( $post_id ) ) {
			throw new \RuntimeException( '建立測試作業失敗：' . $post_id->get_error_message() );
		}

		$post_id = (int) $post_id;

		\update_post_meta( $post_id, 'parent_course_id', $parent_course_id );
		\update_post_meta( $post_id, 'allow_text', (string) $args['allow_text'] );
		\update_post_meta( $post_id, 'allow_file', (string) $args['allow_file'] );
		\update_post_meta( $post_id, 'required_text', (string) $args['required_text'] );
		\update_post_meta( $post_id, 'required_file', (string) $args['required_file'] );
		\update_post_meta( $post_id, 'max_files', (int) $args['max_files'] );
		\update_post_meta( $post_id, 'due_at', (int) $args['due_at'] );

		return $post_id;
	}

	/**
	 * 直接寫入一筆作業繳交（$wpdb->insert，不經 Service）
	 *
	 * @param array<string, mixed> $args assignment_id, user_id, text_body, attachment_ids(int[]),
	 *                                    status, feedback, submitted_at, graded_by, graded_at
	 * @return int 繳交 ID
	 */
	protected function seed_submission( array $args ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		$attachment_ids = (array) ( $args['attachment_ids'] ?? [] );
		$attachments    = \array_map(
			static function ( $attachment_id ): array {
				return [
					'attachment_id' => (int) $attachment_id,
					'file_name'     => null,
				];
			},
			$attachment_ids
		);

		$now = (string) ( $args['submitted_at'] ?? \wp_date( 'Y-m-d H:i:s' ) );

		$wpdb->insert(
			$table,
			[
				'assignment_id' => (int) $args['assignment_id'],
				'user_id'       => (int) $args['user_id'],
				'text_body'     => $args['text_body'] ?? null,
				'attachments'   => (string) \wp_json_encode( \array_values( $attachments ) ),
				'submitted_at'  => $now,
				'status'        => (string) ( $args['status'] ?? 'pending' ),
				'feedback'      => ( isset( $args['feedback'] ) && '' !== $args['feedback'] ) ? (string) $args['feedback'] : null,
				'graded_by'     => isset( $args['graded_by'] ) && '' !== $args['graded_by'] ? (int) $args['graded_by'] : null,
				'graded_at'     => ( isset( $args['graded_at'] ) && '' !== $args['graded_at'] ) ? (string) $args['graded_at'] : null,
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * 直接寫入一筆作業附件（$wpdb->insert）
	 *
	 * @param array<string, mixed> $args user_id, original_name, mime_type, file_size, stored_path, created_at
	 * @param bool                 $with_file 是否實際在暫存目錄建立實體檔案（tear_down 時刪除）
	 * @return int 附件 ID
	 */
	protected function seed_attachment( array $args, bool $with_file = true ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_ATTACHMENTS_TABLE_NAME;

		$stored_path = (string) ( $args['stored_path'] ?? '' );

		if ( '' === $stored_path ) {
			$stored_path = (string) \wp_tempnam( 'pc-assignment-attachment-' );
			if ( $with_file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				\file_put_contents( $stored_path, 'power-course test attachment' );
				$this->seeded_attachment_files[] = $stored_path;
			} else {
				// 刻意不建立實體檔案（測試「實體檔案已不存在」情境）；tempnam 已建立空檔案，需先移除
				if ( \file_exists( $stored_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					\unlink( $stored_path );
				}
			}
		}

		$now = (string) ( $args['created_at'] ?? \wp_date( 'Y-m-d H:i:s' ) );

		$row = [
			'user_id'       => (int) $args['user_id'],
			'original_name' => (string) ( $args['original_name'] ?? '作業附件.png' ),
			'stored_path'   => $stored_path,
			'mime_type'     => (string) ( $args['mime_type'] ?? 'image/png' ),
			'file_size'     => (int) ( $args['file_size'] ?? 1024 ),
			'created_at'    => $now,
		];

		// P3（R-16 邊界測試用）：允許指定明確的 id（如 700 / 7000），
		// 避免用 ALTER TABLE ... AUTO_INCREMENT 操控（DDL 會在 InnoDB 造成隱性 COMMIT，
		// 破壞 WP_UnitTestCase 的交易回滾隔離）。未指定時沿用原本的 auto-increment 行為。
		if ( isset( $args['id'] ) && '' !== $args['id'] ) {
			$row['id'] = (int) $args['id'];
			$wpdb->insert( $table, $row );
			return (int) $args['id'];
		}

		$wpdb->insert( $table, $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * 直接寫入一筆作業討論訊息（$wpdb->insert）
	 *
	 * @param array<string, mixed> $args submission_id, sender_type, sender_id, sender_email, body,
	 *                                    read_at, created_at, deleted_at
	 * @return int 訊息 ID
	 */
	protected function seed_message( array $args ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;

		$now = (string) ( $args['created_at'] ?? \wp_date( 'Y-m-d H:i:s' ) );

		$wpdb->insert(
			$table,
			[
				'submission_id' => (int) $args['submission_id'],
				'sender_type'   => (string) ( $args['sender_type'] ?? 'student' ),
				'sender_id'     => isset( $args['sender_id'] ) && '' !== $args['sender_id'] ? (int) $args['sender_id'] : null,
				'sender_email'  => ( isset( $args['sender_email'] ) && '' !== $args['sender_email'] ) ? (string) $args['sender_email'] : null,
				'body'          => (string) ( $args['body'] ?? '' ),
				'read_at'       => ( isset( $args['read_at'] ) && '' !== $args['read_at'] ) ? (string) $args['read_at'] : null,
				'created_at'    => $now,
				'deleted_at'    => ( isset( $args['deleted_at'] ) && '' !== $args['deleted_at'] ) ? (string) $args['deleted_at'] : null,
			]
		);

		return (int) $wpdb->insert_id;
	}

	// ========== REST dispatch（When） ==========

	/**
	 * 觸發 REST 請求，一律走 rest_get_server()->dispatch()
	 * 權限測試必須走這條（直呼 callback 會跳過 permission_callback，等於沒測）
	 *
	 * @param string                   $method REST method（GET/POST/PUT/DELETE）
	 * @param string                   $route 路徑（含 namespace，如 /power-course/assignments）
	 * @param array<string, mixed>     $params 一般參數（GET query / POST body 欄位）
	 * @param array<string, mixed>|null $json_body 有值時改以 application/json body 送出
	 * @return \WP_REST_Response
	 */
	protected function dispatch( string $method, string $route, array $params = [], ?array $json_body = null ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		if ( null !== $json_body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) \wp_json_encode( $json_body ) );
		}

		return \rest_get_server()->dispatch( $request );
	}

	// ========== 寄信攔截 ==========

	/**
	 * 掛上 pre_wp_mail 攔截，記錄收件人 / 主旨 / 內容並短路（不真的寄信）
	 * 不使用 wp_mail filter：該 filter 過的是 $atts 參數陣列，不是回傳值，短路不了寄信
	 */
	protected function capture_mail(): void {
		if ( $this->mail_captured ) {
			return;
		}
		\add_filter( 'pre_wp_mail', [ $this, 'record_mail' ], 10, 2 );
		$this->mail_captured = true;
	}

	/**
	 * pre_wp_mail callback，記錄寄信參數
	 *
	 * @param mixed                $null 預設回傳值（null = 讓 wp_mail 繼續跑）
	 * @param array<string, mixed> $atts wp_mail() 的參數陣列
	 * @return bool 短路 wp_mail，回傳 true 視為寄信成功
	 */
	public function record_mail( $null, array $atts ): bool {
		$this->captured_mails[] = [
			'to'          => $atts['to'] ?? null,
			'subject'     => (string) ( $atts['subject'] ?? '' ),
			'message'     => (string) ( $atts['message'] ?? '' ),
			'headers'     => $atts['headers'] ?? '',
			'attachments' => $atts['attachments'] ?? [],
		];

		return true;
	}

	// ========== Action Scheduler ==========

	/**
	 * 取出指定 hook 的 pending scheduled action，逐筆 do_action_ref_array 後標記完成
	 *
	 * @param string $hook Action Scheduler hook 名稱
	 */
	protected function run_scheduled_actions( string $hook ): void {
		if ( ! \function_exists( 'as_get_scheduled_actions' ) || ! \class_exists( '\ActionScheduler' ) ) {
			return;
		}

		/** @var array<int, int|string> $action_ids */
		$action_ids = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);

		if ( ! $action_ids ) {
			return;
		}

		$store = \ActionScheduler::store();

		foreach ( $action_ids as $action_id ) {
			$action = $store->fetch_action( (string) $action_id );
			\do_action_ref_array( $hook, $action->get_args() );
			$store->mark_complete( (string) $action_id );
		}
	}

	// ========== 斷言：作業（aggregate-then） ==========

	/**
	 * 讀取作業目前的欄位快照
	 *
	 * @param int $assignment_id 作業 ID
	 * @return array<string, mixed>|null
	 */
	protected function get_assignment_fields( int $assignment_id ): ?array {
		$post = \get_post( $assignment_id );
		if ( ! ( $post instanceof \WP_Post ) ) {
			return null;
		}

		return [
			'post_parent'      => (int) $post->post_parent,
			'parent_course_id' => (int) \get_post_meta( $assignment_id, 'parent_course_id', true ),
			'title'            => $post->post_title,
			'instructions'     => $post->post_content,
			'allow_text'       => (string) \get_post_meta( $assignment_id, 'allow_text', true ),
			'allow_file'       => (string) \get_post_meta( $assignment_id, 'allow_file', true ),
			'required_text'    => (string) \get_post_meta( $assignment_id, 'required_text', true ),
			'required_file'    => (string) \get_post_meta( $assignment_id, 'required_file', true ),
			'max_files'        => (int) \get_post_meta( $assignment_id, 'max_files', true ),
			'due_at'           => (int) \get_post_meta( $assignment_id, 'due_at', true ),
			'menu_order'       => (int) $post->menu_order,
			'post_status'      => $post->post_status,
		];
	}

	/**
	 * 斷言作業欄位符合期望（只檢查 $expected 有提供的欄位）
	 *
	 * @param int                  $assignment_id 作業 ID
	 * @param array<string, mixed> $expected 期望欄位
	 */
	protected function assert_assignment_fields( int $assignment_id, array $expected ): void {
		$actual = $this->get_assignment_fields( $assignment_id );
		$this->assertIsArray( $actual, "作業 {$assignment_id} 應存在" );

		$int_fields = [ 'post_parent', 'parent_course_id', 'max_files', 'due_at', 'menu_order' ];

		foreach ( $expected as $field => $value ) {
			$this->assertArrayHasKey( $field, $actual, "未知的作業欄位 {$field}" );
			if ( \in_array( $field, $int_fields, true ) ) {
				$this->assertSame( (int) $value, $actual[ $field ], "作業 {$assignment_id} 的 {$field} 不符" );
			} else {
				$this->assertSame( (string) $value, (string) $actual[ $field ], "作業 {$assignment_id} 的 {$field} 不符" );
			}
		}
	}

	/**
	 * 斷言作業不存在（永久刪除後）
	 *
	 * @param int $assignment_id 作業 ID
	 */
	protected function assert_assignment_not_exists( int $assignment_id ): void {
		$this->assertNull( \get_post( $assignment_id ), "作業 {$assignment_id} 應不存在" );
	}

	// ========== 斷言：作業繳交 ==========

	/**
	 * 讀取一筆作業繳交
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array<string, mixed>|null
	 */
	protected function get_submission_row( int $submission_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $submission_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * 斷言作業繳交存在且欄位符合期望
	 *
	 * @param int                  $submission_id 繳交 ID
	 * @param array<string, mixed> $expected 期望欄位（不含 attachment_ids，需另行解析 attachments JSON）
	 */
	protected function assert_submission_exists( int $submission_id, array $expected = [] ): void {
		$row = $this->get_submission_row( $submission_id );
		$this->assertIsArray( $row, "作業繳交 {$submission_id} 應存在" );

		foreach ( $expected as $field => $value ) {
			$this->assertArrayHasKey( $field, $row, "未知的繳交欄位 {$field}" );
			$this->assertSame( (string) $value, (string) $row[ $field ], "作業繳交 {$submission_id} 的 {$field} 不符" );
		}
	}

	/**
	 * 斷言作業繳交不存在
	 *
	 * @param int $submission_id 繳交 ID
	 */
	protected function assert_submission_not_exists( int $submission_id ): void {
		$this->assertNull( $this->get_submission_row( $submission_id ), "作業繳交 {$submission_id} 應不存在" );
	}

	// ========== 斷言：作業討論訊息 ==========

	/**
	 * 讀取一筆作業討論訊息
	 *
	 * @param int $message_id 訊息 ID
	 * @return array<string, mixed>|null
	 */
	protected function get_message_row( int $message_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $message_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * 斷言作業討論訊息存在
	 *
	 * @param int $message_id 訊息 ID
	 */
	protected function assert_message_exists( int $message_id ): void {
		$this->assertIsArray( $this->get_message_row( $message_id ), "作業討論訊息 {$message_id} 應仍存在" );
	}

	/**
	 * 斷言作業討論訊息不存在
	 *
	 * @param int $message_id 訊息 ID
	 */
	protected function assert_message_not_exists( int $message_id ): void {
		$this->assertNull( $this->get_message_row( $message_id ), "作業討論訊息 {$message_id} 應不存在" );
	}

	// ========== 斷言：作業附件 ==========

	/**
	 * 讀取一筆作業附件
	 *
	 * @param int $attachment_id 附件 ID
	 * @return array<string, mixed>|null
	 */
	protected function get_attachment_row( int $attachment_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_ATTACHMENTS_TABLE_NAME;

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE id = %d", $attachment_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * 斷言作業附件仍存在（紀錄列，不檢查實體檔案）
	 *
	 * @param int $attachment_id 附件 ID
	 */
	protected function assert_attachment_exists( int $attachment_id ): void {
		$this->assertIsArray( $this->get_attachment_row( $attachment_id ), "作業附件 {$attachment_id} 應仍存在" );
	}

	// ========== 斷言：REST 錯誤回應 ==========

	/**
	 * 斷言「權限不足」（DEC-01：403 rest_forbidden）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_permission_denied( \WP_REST_Response $response ): void {
		$this->assertSame( 403, $response->get_status(), '非管理員操作應回應 403' );
		$data = $response->get_data();
		$this->assertSame( 'rest_forbidden', \is_array( $data ) ? ( $data['code'] ?? null ) : null, '錯誤代碼應為 rest_forbidden' );
	}

	/**
	 * 斷言「必須登入」（DEC-01：401 rest_not_logged_in）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_not_logged_in( \WP_REST_Response $response ): void {
		$this->assertSame( 401, $response->get_status(), '未登入操作應回應 401' );
		$data = $response->get_data();
		$this->assertSame( 'rest_not_logged_in', \is_array( $data ) ? ( $data['code'] ?? null ) : null, '錯誤代碼應為 rest_not_logged_in' );
	}

	/**
	 * 斷言操作失敗，錯誤為指定 zh_TW 訊息（對照 §4.1 msgid 對照表）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @param string            $message 期望的 zh_TW 錯誤訊息（feature 文字，不可用 __() 包裝期望值）
	 * @param int               $status 期望的 HTTP 狀態碼
	 * @param string            $code 期望的錯誤代碼
	 */
	protected function assert_operation_failed_response( \WP_REST_Response $response, string $message, int $status, string $code ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為錯誤陣列' );
		$this->assertSame(
			$status,
			$response->get_status(),
			\sprintf( '錯誤 HTTP 狀態不符（實際回應：%s）', (string) \wp_json_encode( $data ) )
		);
		$this->assertSame( $code, $data['code'] ?? null, '錯誤代碼不符' );
		$this->assertSame(
			$message,
			$data['message'] ?? null,
			\sprintf( '錯誤訊息不符（實際回應：%s）', (string) \wp_json_encode( $data ) )
		);
	}

	// ========== 斷言：REST 成功回應 ==========

	/**
	 * 斷言 REST 回應成功
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @param int               $status 期望的 HTTP 狀態碼，預設 200
	 */
	protected function assert_success_response( \WP_REST_Response $response, int $status = 200 ): void {
		$this->assertSame(
			$status,
			$response->get_status(),
			\sprintf( '預期成功（%d），實際為 %d（回應：%s）', $status, $response->get_status(), (string) \wp_json_encode( $response->get_data() ) )
		);
	}

	/**
	 * 斷言回應（單一物件）包含指定欄位與值
	 *
	 * @param \WP_REST_Response    $response REST 回應
	 * @param array<string, mixed> $expected 期望欄位
	 */
	protected function assert_response_includes( \WP_REST_Response $response, array $expected ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為物件陣列' );

		foreach ( $expected as $field => $value ) {
			$this->assertArrayHasKey( $field, $data, "回應缺少欄位 {$field}" );
			$this->assertSame( $value, $data[ $field ], "回應欄位 {$field} 不符" );
		}
	}

	/**
	 * 取出回應的列表資料（陣列）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_response_list( \WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為列表' );

		/** @var array<int, array<string, mixed>> $data */
		return $data;
	}

	/**
	 * 斷言回應列表的 id 依序符合期望
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @param array<int, int>   $expected_ids 期望的 id 順序
	 */
	protected function assert_response_list_ids( \WP_REST_Response $response, array $expected_ids ): void {
		$list       = $this->get_response_list( $response );
		$actual_ids = \array_map(
			static function ( array $item ): int {
				return (int) ( $item['id'] ?? 0 );
			},
			$list
		);

		$this->assertSame( $expected_ids, $actual_ids, '回應列表的 id 順序不符' );
	}

	// ========== 靜態檢查：nocache_headers()（P3 起，供學員端 / 下載端點共用） ==========

	/**
	 * 斷言指定類別方法的「第一個語句」就是 \nocache_headers()（.claude/rules/wordpress.rule.md）
	 *
	 * 與 AssignmentApiContractTest 內原本只認 Core\Api 的私有版本邏輯相同，差別是本方法可指定
	 * 任意 class，供 P3 起新增的學員端（Core\MyApi）與下載端點（Core\Api）路由共用，
	 * 不需要複製整段 Reflection + token_get_all 邏輯。
	 *
	 * @param string $class  callback 所在類別 FQCN
	 * @param string $method callback method 名稱
	 */
	protected function assert_callback_starts_with_nocache_headers_for( string $class, string $method ): void {
		if ( ! \class_exists( $class ) ) {
			$this->fail( "{$class} 尚未實作，callback {$method}() 無法檢查 nocache_headers()" );
		}
		if ( ! \method_exists( $class, $method ) ) {
			$this->fail( "{$class}::{$method}() 尚未實作" );
		}

		$ref = new \ReflectionMethod( $class, $method );

		$file  = (string) $ref->getFileName();
		$lines = (array) \file( $file );
		$src   = \implode(
			'',
			\array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 )
		);

		$tokens     = \token_get_all( '<?php ' . $src );
		$in_body    = false;
		$first_stmt = null;

		foreach ( $tokens as $token ) {
			if ( ! \is_array( $token ) ) {
				if ( '{' === $token ) {
					$in_body = true;
				}
				continue;
			}

			if ( ! $in_body ) {
				continue;
			}

			if ( \in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$first_stmt = $token[1];
			break;
		}

		$this->assertSame(
			'nocache_headers',
			\ltrim( (string) $first_stmt, '\\' ),
			\sprintf(
				'%s::%s() 的第一個語句必須是 \nocache_headers()（Issue #216 / .claude/rules/wordpress.rule.md）',
				$class,
				$method
			)
		);
	}

	// ========== P4 擴充：{items,total} 形狀回應（繳交列表等分頁端點） ==========

	/**
	 * 取得 {items, total} 形狀回應的 items 陣列
	 * P2/P3 的作業列表回應是扁平陣列（見 get_response_list()），
	 * P4 起繳交列表需要總數，改用 {items, total} 包裝（對齊 AccessPass::/{id}/users 既有慣例）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_response_items( \WP_REST_Response $response ): array {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為陣列' );
		$this->assertArrayHasKey( 'items', $data, '回應應包含 items 欄位' );
		$this->assertIsArray( $data['items'], 'items 應為陣列' );

		/** @var array<int, array<string, mixed>> $items */
		$items = $data['items'];
		return $items;
	}

	/**
	 * 斷言 {items, total} 回應的 items 依序符合期望的 id 清單
	 *
	 * @param \WP_REST_Response $response     REST 回應
	 * @param array<int, int>   $expected_ids 期望的 id 順序
	 */
	protected function assert_response_item_ids( \WP_REST_Response $response, array $expected_ids ): void {
		$items      = $this->get_response_items( $response );
		$actual_ids = \array_map(
			static function ( array $item ): int {
				return (int) ( $item['id'] ?? 0 );
			},
			$items
		);

		$this->assertSame( $expected_ids, $actual_ids, '回應 items 的 id 順序不符' );
	}

	/**
	 * 斷言 {items, total} 回應的 total 欄位符合期望
	 *
	 * @param \WP_REST_Response $response       REST 回應
	 * @param int               $expected_total 期望總數
	 */
	protected function assert_response_total( \WP_REST_Response $response, int $expected_total ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為陣列' );
		$this->assertArrayHasKey( 'total', $data, '回應應包含 total 欄位' );
		$this->assertSame( $expected_total, (int) $data['total'], '回應 total 不符' );
	}

	// ========== P4 擴充：巢狀路徑斷言（學員背景資料等 user./course./stats./progress. 結構） ==========

	/**
	 * 以 dot path（如 "user.id"）取出巢狀陣列中的值，任一層不存在時回傳 null
	 *
	 * @param array<string, mixed> $data 巢狀陣列
	 * @param string                $path dot path
	 * @return mixed
	 */
	private static function dot_get( array $data, string $path ): mixed {
		$cursor = $data;
		foreach ( \explode( '.', $path ) as $segment ) {
			if ( ! \is_array( $cursor ) || ! \array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * 斷言回應包含指定的巢狀欄位（dot path，如 "user.id" / "progress.percent"）
	 *
	 * @param \WP_REST_Response    $response REST 回應
	 * @param array<string, mixed> $expected 期望欄位，key 為 dot path
	 */
	protected function assert_response_includes_nested( \WP_REST_Response $response, array $expected ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data, '回應應為物件陣列' );

		foreach ( $expected as $path => $value ) {
			$actual = self::dot_get( $data, $path );
			$this->assertSame( $value, $actual, "回應欄位 {$path} 不符（實際回應：" . (string) \wp_json_encode( $data ) . '）' );
		}
	}

	// ========== P4 擴充：CSV stream（Service\Csv 直接單元驗證，不經 REST） ==========

	/**
	 * 讀出 CSV stream 的完整原始內容（bytes），並關閉該 stream
	 *
	 * @param resource $stream Service\Csv 回傳的 php://temp stream
	 * @return string 原始內容（含 BOM，換行為 CRLF）
	 */
	protected function read_csv_stream( $stream ): string {
		$this->assertIsResource( $stream, 'Service\Csv 應回傳串流資源' );
		\rewind( $stream );
		$raw = (string) \stream_get_contents( $stream );
		\fclose( $stream );

		return $raw;
	}

	/**
	 * 斷言 CSV 原始內容以 UTF-8 BOM 開頭，且全文換行一律為 CRLF（不含裸 \n）
	 *
	 * @param string $raw read_csv_stream() 取得的原始內容
	 */
	protected function assert_csv_bom_and_crlf( string $raw ): void {
		$this->assertStringStartsWith( "\xEF\xBB\xBF", $raw, 'CSV 應以 UTF-8 BOM 開頭' );

		$without_crlf = \str_replace( "\r\n", '', \substr( $raw, 3 ) );
		$this->assertStringNotContainsString( "\n", $without_crlf, 'CSV 換行必須一律為 CRLF，不可含裸 \n' );
	}

	/**
	 * 將 CSV 原始內容（可能含 BOM）解析為二維陣列（第一列為標題列）
	 * 用 fgetcsv() 而非手動 split，正確處理雙引號跳脫與欄位內逗號 / 換行
	 *
	 * @param string $raw read_csv_stream() 取得的原始內容
	 * @return array<int, array<int, string>>
	 */
	protected function parse_csv_rows( string $raw ): array {
		$body = \str_starts_with( $raw, "\xEF\xBB\xBF" ) ? \substr( $raw, 3 ) : $raw;

		$stream = \fopen( 'php://temp', 'r+' );
		$this->assertIsResource( $stream, '無法開啟暫存串流解析 CSV' );
		\fwrite( $stream, $body );
		\rewind( $stream );

		$rows = [];
		while ( ( $row = \fgetcsv( $stream, 0, ',', '"', '\\' ) ) !== false ) {
			if ( [ null ] === $row ) {
				continue; // 結尾空白列
			}
			/** @var array<int, string> $row */
			$rows[] = $row;
		}
		\fclose( $stream );

		return $rows;
	}

	/**
	 * 從 parse_csv_rows() 的結果中，依標題名稱取出該欄所有資料列的值（不含標題列）
	 *
	 * @param array<int, array<int, string>> $rows   parse_csv_rows() 的結果
	 * @param string                          $header 標題名稱（第一列的其中一格）
	 * @return array<int, string>
	 */
	protected function extract_csv_column( array $rows, string $header ): array {
		$this->assertNotEmpty( $rows, 'CSV 應至少有標題列' );
		$index = \array_search( $header, $rows[0], true );
		$this->assertNotFalse( $index, "CSV 標題列缺少欄位 {$header}" );

		$values = [];
		for ( $i = 1, $count = \count( $rows ); $i < $count; $i++ ) {
			$values[] = (string) ( $rows[ $i ][ $index ] ?? '' );
		}

		return $values;
	}

	// ========== P4 擴充：直接呼叫尚未存在的 Service 靜態方法（不經 REST） ==========

	/**
	 * 呼叫（可能）尚未實作的 production 靜態方法，class / method 不存在時以清楚的
	 * $this->fail() 訊息取代未捕捉的 Fatal Error（比對既有
	 * assert_callback_starts_with_nocache_headers_for() 的 class_exists / method_exists 守門手法）。
	 *
	 * 只在直接呼叫 Service 層方法（繞開 REST）驗證回傳值本身時使用，例如 Service\Csv
	 * 回傳的 stream resource；REST dispatch() 的錯誤處理已由 ApiBase 統一接住，不需要這層。
	 *
	 * @param string            $class  production class FQCN
	 * @param string            $method 靜態方法名稱
	 * @param array<int, mixed> $args   呼叫參數
	 * @return mixed
	 */
	protected function call_static_or_fail( string $class, string $method, array $args = [] ): mixed {
		if ( ! \class_exists( $class ) ) {
			$this->fail( "{$class} 尚未實作" );
		}
		if ( ! \method_exists( $class, $method ) ) {
			$this->fail( "{$class}::{$method}() 尚未實作" );
		}

		return \call_user_func_array( [ $class, $method ], $args );
	}

	// ========== P5 擴充：PowerEmail 觸發（AssignmentGradedEmailTest / AssignmentEmailReplaceTest 共用） ==========

	/**
	 * 建立一封 pe_email 郵件模板（trigger_at + condition），供作業批改通知信測試共用
	 *
	 * 對齊既有 tests/Integration/Email/RevokeRescheduleTest.php 建立模板的寫法：
	 * trigger_at 與 condition 必須同時設定，否則 Email::get_sending_timestamp() 回傳 null。
	 *
	 * @param array<string, mixed> $args post_title、trigger_at（預設 assignment_graded）、
	 *                                    post_status（預設 publish）、post_content、
	 *                                    course_ids（condition.course_ids，空陣列 = 不限課程，DEC-10）、
	 *                                    sending（condition.sending，預設 send_now）
	 * @return int 郵件模板（pe_email）post ID
	 */
	protected function create_email_template( array $args = [] ): int {
		$defaults = [
			'post_title'   => '作業批改通知模板_' . \uniqid(),
			'trigger_at'   => 'assignment_graded',
			'post_status'  => 'publish',
			'post_content' => '{display_name} 您的作業「{assignment_title}」批改結果：{assignment_status}。{assignment_feedback}',
			'course_ids'   => [],
			'sending'      => [
				'type'  => 'send_now',
				'value' => null,
				'unit'  => null,
				'range' => null,
			],
		];
		$args = \wp_parse_args( $args, $defaults );

		$email_id = $this->factory()->post->create(
			[
				'post_title'   => (string) $args['post_title'],
				'post_type'    => EmailCPT::POST_TYPE,
				'post_status'  => (string) $args['post_status'],
				'post_content' => (string) $args['post_content'],
			]
		);

		\update_post_meta( $email_id, 'trigger_at', (string) $args['trigger_at'] );
		\update_post_meta(
			$email_id,
			'condition',
			[
				'trigger_condition' => 'each',
				'course_ids'        => (array) $args['course_ids'],
				'chapter_ids'       => [],
				'sending'           => $args['sending'],
			]
		);

		return (int) $email_id;
	}

	/**
	 * 斷言 capture_mail() 攔截到的紀錄中，收件人包含指定 email
	 *
	 * @param string $email 期望收件人 email
	 */
	protected function assert_mail_sent_to( string $email ): void {
		$this->assertNotEmpty( $this->captured_mails, "應發送郵件給 {$email}，但未攔截到任何寄信" );

		$recipients = \array_map(
			static function ( array $mail ): string {
				$to = $mail['to'];
				return \is_array( $to ) ? \implode( ',', $to ) : (string) $to;
			},
			$this->captured_mails
		);

		$found = false;
		foreach ( $recipients as $recipient ) {
			if ( false !== \strpos( $recipient, $email ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			\sprintf( '應發送郵件給 %s，實際收件人：%s', $email, \implode( '; ', $recipients ) )
		);
	}

	/**
	 * 斷言 capture_mail() 攔截到的紀錄中，至少一封信的內容包含指定字串
	 *
	 * @param string $needle 期望出現的字串
	 */
	protected function assert_mail_content_contains( string $needle ): void {
		$this->assertNotEmpty( $this->captured_mails, "應攔截到寄信紀錄，才能檢查內容是否包含「{$needle}」" );

		$contents = \array_column( $this->captured_mails, 'message' );
		$found    = false;
		foreach ( $contents as $content ) {
			if ( false !== \strpos( (string) $content, $needle ) ) {
				$found = true;
				break;
			}
		}

		$this->assertTrue(
			$found,
			\sprintf( '寄信內容應包含「%s」，實際內容：%s', $needle, \implode( "\n---\n", $contents ) )
		);
	}

	/**
	 * 取得指定 hook 目前 pending 排程中最早的預定執行 timestamp（未找到回傳 null）
	 *
	 * 用於驗證「立即寄送」語意（DEC-10）：不論模板的延遲寄送設定為何，作業類信件的排程時間
	 * 都應接近觸發當下，而非被模板的 condition.sending.type=send_later 推遲。
	 * 與 run_scheduled_actions() 不同——後者會強制執行所有 pending action（無視排定時間），
	 * 因此無法用來驗證排程本身是否真的被延後；本方法改為直接讀出排程的目標時間點。
	 *
	 * @param string $hook Action Scheduler hook 名稱
	 * @return int|null
	 */
	protected function get_next_scheduled_action_timestamp( string $hook ): ?int {
		if ( ! \function_exists( 'as_get_scheduled_actions' ) || ! \class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		/** @var array<int, int|string> $action_ids */
		$action_ids = as_get_scheduled_actions(
			[
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => -1,
			],
			'ids'
		);

		if ( ! $action_ids ) {
			return null;
		}

		$store      = \ActionScheduler::store();
		$timestamps = [];
		foreach ( $action_ids as $action_id ) {
			$action   = $store->fetch_action( (string) $action_id );
			$schedule = $action->get_schedule();
			$date     = $schedule->get_date();
			if ( $date instanceof \DateTime ) {
				$timestamps[] = $date->getTimestamp();
			}
		}

		return $timestamps ? \min( $timestamps ) : null;
	}
}
