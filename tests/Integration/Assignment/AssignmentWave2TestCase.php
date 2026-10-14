<?php
/**
 * 作業（Assignment）第二波整合測試共用基底（Issue #131 第二波）
 *
 * 繼承第一波的 AssignmentTestCase（唯讀，不修改），只新增第二波（討論串 / 匯入批改結果 CSV）
 * 共用的 Given/When/Then helper：
 *   - seed_thread_fixture()：建立「查詢作業討論串」與「發送作業討論訊息」兩支 feature 的 Background
 *   - nonexistent_id()：取一個確定不存在的繳交 ID（不用字面 9999，InnoDB auto_increment 不隨交易回滾）
 *   - get_last_message_row()：依 created_at DESC, id DESC 取討論串最後一則（含軟刪除欄位原值）
 *   - count_messages()：計算某繳交的訊息列數（含已軟刪除），驗證「操作失敗時沒有寫入任何訊息」
 *   - unenroll_user_from_course()：feature 的「用戶 X 未被加入課程 N」Given
 *   - get_thread_as() / post_as()：以學員（student）或管理員（teacher）身分打討論串 REST，
 *     一律走 rest_get_server()->dispatch()，permission_callback 才會真的被執行
 *   - thread_ids() / assert_message_view_keys() / assert_no_sender_leak()：討論串回應斷言
 *
 * ─── 為什麼本檔不會讓所有測試一起 fatal ─────────────────────────
 * 本檔不在 class 宣告層級（extends / implements / 常數預設值）引用任何尚未存在的 production 符號。
 * 唯一用到的 production 符號 J7\PowerCourse\Resources\Assignment\Utils\Utils 與
 * J7\PowerCourse\Plugin 皆為第一波既有類別，且只在方法內引用。
 * 第二波新增的 Message / Thread / CsvImport / CsvParser 等類別一律不在本檔引用，
 * 讓紅燈原因精準落在各測試本身（rest_no_route / 斷言不符），而不是整批栽在共用基底上。
 * ────────────────────────────────────────────────────────────
 *
 * 時間：fixture 一律 set_now( '2026-09-17 10:00:00' )（feature Background「現在時間為」）。
 *
 * ─── W2-R1 擴充（匯入批改結果 CSV；只新增，不改上方既有方法語意）──────────────
 *   - seed_import_fixture()：匯入 feature 的 Background（500 / 501 皆 pending）
 *   - dispatch_with_file() / make_upload_file()：以 WP_REST_Request::set_file_params() 帶檔，不碰 $_FILES 全域
 *   - build_csv()：由二維陣列組 CSV 字串（第一列為表頭，含逗號 / 引號 / 換行的格自動加引號）
 *   - import_csv() / assert_import_summary() / assert_import_untouched()：呼叫 CsvImport::import() 與斷言摘要
 *   - freeze_wp_date_now()：把 wp_date( 'Y-m-d H:i:s' )（真實現在時間）固定為 feature 的「現在時間」，
 *     用來讓 Chapter\Service\Progress::mark_finished() 的 finished_at 可斷言字面值（見該方法說明）
 * 同樣不在 class 宣告層級引用 CsvImport / CsvParser（只放 FQCN 字串常數，方法內才呼叫）。
 * ────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Plugin;

/**
 * Class AssignmentWave2TestCase
 * 第二波作業整合測試共用基底（abstract，不以 Test.php 結尾）
 */
abstract class AssignmentWave2TestCase extends AssignmentTestCase {

	/** 討論串訊息對外回傳的欄位白名單（排序後；api.yml AssignmentMessage，不含 sender_id / sender_email） */
	protected const MESSAGE_VIEW_KEYS = [ 'body', 'created_at', 'id', 'mine', 'read_at', 'sender_type' ];

	/** 站台「現在時間」（feature Background） */
	protected const NOW = '2026-09-17 10:00:00';

	/** CsvImport 類別 FQCN（計劃 §4.2；只放字串，不在 class 層級引用尚未存在的符號） */
	protected const CSV_IMPORT_CLASS = 'J7\PowerCourse\Resources\Assignment\Service\CsvImport';

	/** CsvParser 類別 FQCN（計劃 §4.2） */
	protected const CSV_PARSER_CLASS = 'J7\PowerCourse\Resources\Assignment\Utils\CsvParser';

	/** 匯入檔案大小上限（10MB，bytes；api.yml importAssignmentGradesCsv / DEC-W2-13） */
	protected const IMPORT_MAX_BYTES = 10485760;

	/** @var array<string, int> seed_thread_fixture() 建立的 feature ID → 實際 ID 對照 */
	protected array $fx = [];

	/** @var array<int, string> make_upload_file() 建立的暫存檔，tear_down 時刪除 */
	private array $upload_tmp_files = [];

	/** @var callable|null freeze_wp_date_now() 掛上的 wp_date filter callback */
	private $wp_date_freeze_filter = null;

	/**
	 * 清理（每個測試後執行）：刪除 make_upload_file() 建立的暫存檔、移除 freeze_wp_date_now() 掛上的 filter
	 */
	public function tear_down(): void {
		foreach ( $this->upload_tmp_files as $file ) {
			if ( \file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				\unlink( $file );
			}
		}
		$this->upload_tmp_files = [];

		if ( null !== $this->wp_date_freeze_filter ) {
			\remove_filter( 'wp_date', $this->wp_date_freeze_filter, 10 );
			$this->wp_date_freeze_filter = null;
		}

		parent::tear_down();
	}

	// ========== Background（aggregate-given） ==========

	/**
	 * 建立第二波討論串 feature 的 Background，並把「現在時間」固定為 2026-09-17 10:00:00
	 *
	 * 'list'（查詢作業討論串）：
	 *   繳交 500（Alice）/ 501（Bob）皆 pending；Alice 已加入課程 100、expire_date 0；
	 *   訊息 600（student，未讀）、601（teacher，未讀，sender_email = admin@test.com）、
	 *   602（student，已軟刪除）。
	 * 'post'（發送作業討論訊息）：
	 *   繳交 500 為 fail + 評語「請補充型別說明」、graded_by = Admin、graded_at 2026-09-16 10:00:00；
	 *   501 pending；沒有任何訊息。
	 *
	 * 回傳 feature ID → 實際 ID 對照，鍵：
	 *   admin / alice / bob / course / chapter / assignment / sub_500 / sub_501，
	 *   'list' 另有 msg_600 / msg_601 / msg_602。
	 *
	 * @param string $variant 'list' 或 'post'
	 * @return array<string, int>
	 */
	protected function seed_thread_fixture( string $variant ): array {
		$this->assertContains( $variant, [ 'list', 'post' ], "未知的 fixture 變體：{$variant}" );

		$admin_id = $this->factory()->user->create(
			[
				'user_login'   => 'admin_w2_' . \uniqid(),
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'role'         => 'administrator',
			]
		);
		$alice_id = $this->factory()->user->create(
			[
				'user_login'   => 'alice_w2_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email'   => 'alice@test.com',
				'role'         => 'subscriber',
			]
		);
		$bob_id   = $this->factory()->user->create(
			[
				'user_login'   => 'bob_w2_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email'   => 'bob@test.com',
				'role'         => 'subscriber',
			]
		);

		$course_id     = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_id    = $this->create_chapter( $course_id, [ 'post_title' => '1-1 變數' ] );
		$assignment_id = $this->create_assignment(
			$chapter_id,
			[
				'title'         => '練習一：變數宣告',
				'allow_text'    => 'yes',
				'allow_file'    => 'no',
				'required_text' => 'no',
				'required_file' => 'no',
				'max_files'     => 1,
				'due_at'        => 0,
				'menu_order'    => 0,
				'post_status'   => 'publish',
			]
		);

		$this->enroll_user_to_course( $alice_id, $course_id, 0 );

		if ( 'list' === $variant ) {
			$sub_500_id = $this->seed_submission(
				[
					'assignment_id' => $assignment_id,
					'user_id'       => $alice_id,
					'text_body'     => 'let x = 1;',
					'status'        => 'pending',
					'submitted_at'  => '2026-09-16 09:00:00',
				]
			);
		} else {
			$sub_500_id = $this->seed_submission(
				[
					'assignment_id' => $assignment_id,
					'user_id'       => $alice_id,
					'text_body'     => 'let x = 1;',
					'status'        => 'fail',
					'feedback'      => '請補充型別說明',
					'submitted_at'  => '2026-09-16 09:00:00',
					'graded_by'     => $admin_id,
					'graded_at'     => '2026-09-16 10:00:00',
				]
			);
		}

		$sub_501_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_id,
				'user_id'       => $bob_id,
				'text_body'     => 'const y = 2;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 10:00:00',
			]
		);

		$this->fx = [
			'admin'      => $admin_id,
			'alice'      => $alice_id,
			'bob'        => $bob_id,
			'course'     => $course_id,
			'chapter'    => $chapter_id,
			'assignment' => $assignment_id,
			'sub_500'    => $sub_500_id,
			'sub_501'    => $sub_501_id,
		];

		if ( 'list' === $variant ) {
			$this->fx['msg_600'] = $this->seed_message(
				[
					'submission_id' => $sub_500_id,
					'sender_type'   => 'student',
					'sender_id'     => $alice_id,
					'body'          => '老師請問型別',
					'created_at'    => '2026-09-16 09:10:00',
				]
			);
			$this->fx['msg_601'] = $this->seed_message(
				[
					'submission_id' => $sub_500_id,
					'sender_type'   => 'teacher',
					'sender_id'     => $admin_id,
					'sender_email'  => 'admin@test.com',
					'body'          => '參考 1-1 的範例',
					'created_at'    => '2026-09-16 10:00:00',
				]
			);
			$this->fx['msg_602'] = $this->seed_message(
				[
					'submission_id' => $sub_500_id,
					'sender_type'   => 'student',
					'sender_id'     => $alice_id,
					'body'          => '打錯字請忽略',
					'created_at'    => '2026-09-16 10:10:00',
					'deleted_at'    => '2026-09-16 10:15:00',
				]
			);
		}

		$this->set_now( self::NOW );

		return $this->fx;
	}

	/**
	 * 取一個確定不存在的繳交 ID（目前 pc_assignment_submissions 最大 id + 100000）
	 *
	 * 不用字面 9999：InnoDB auto_increment 不隨交易回滾，長跑後真實 ID 可能撞到 9999。
	 *
	 * @return int
	 */
	protected function nonexistent_id(): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		$max = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery

		return $max + 100000;
	}

	/**
	 * 依 created_at DESC, id DESC 取某繳交討論串的最後一則訊息列（含已軟刪除的列，欄位為 DB 原值）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array<string, mixed>|null
	 */
	protected function get_last_message_row( int $submission_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM `{$table}` WHERE submission_id = %d ORDER BY created_at DESC, id DESC LIMIT 1", $submission_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * 計算某繳交的訊息列數（含已軟刪除的列）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return int
	 */
	protected function count_messages( int $submission_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::ASSIGNMENT_MESSAGES_TABLE_NAME;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE submission_id = %d", $submission_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * Given 用戶 X 未被加入課程 N（移除 avl_course_ids，對應 Utils\Course::is_avl() 的讀取來源）
	 *
	 * @param int $user_id   用戶 ID
	 * @param int $course_id 課程 ID
	 */
	protected function unenroll_user_from_course( int $user_id, int $course_id ): void {
		\delete_user_meta( $user_id, 'avl_course_ids', $course_id );
	}

	// ========== REST dispatch（When） ==========

	/**
	 * 管理員端討論串 route（GET / POST assignment-submissions/{id}/messages）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return string
	 */
	protected function admin_thread_route( int $submission_id ): string {
		return '/power-course/assignment-submissions/' . $submission_id . '/messages';
	}

	/**
	 * 學員端討論串 route（GET / POST my/assignment-submissions/{id}/messages）
	 *
	 * @param int $submission_id 繳交 ID
	 * @return string
	 */
	protected function my_thread_route( int $submission_id ): string {
		return '/power-course/my/assignment-submissions/' . $submission_id . '/messages';
	}

	/**
	 * 討論串查詢者（viewer）對應的使用者與 route
	 * 'student' = Alice（學員端 my/*）；'teacher' = Admin（管理員端）
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 * @param int    $submission_id 繳交 ID
	 * @return array{0: int, 1: string} [user_id, route]
	 */
	private function resolve_viewer( string $viewer, int $submission_id ): array {
		$this->assertContains( $viewer, [ 'student', 'teacher' ], "未知的 viewer：{$viewer}" );
		$this->assertNotEmpty( $this->fx, '請先呼叫 seed_thread_fixture()' );

		return 'student' === $viewer
			? [ $this->fx['alice'], $this->my_thread_route( $submission_id ) ]
			: [ $this->fx['admin'], $this->admin_thread_route( $submission_id ) ];
	}

	/**
	 * 以學員（Alice）或管理員（Admin）身分查詢討論串
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 * @param int    $submission_id 繳交 ID
	 * @return \WP_REST_Response
	 */
	protected function get_thread_as( string $viewer, int $submission_id ): \WP_REST_Response {
		[ $user_id, $route ] = $this->resolve_viewer( $viewer, $submission_id );
		\wp_set_current_user( $user_id );

		return $this->dispatch( 'GET', $route );
	}

	/**
	 * 以學員（Alice）或管理員（Admin）身分在討論串發送訊息
	 *
	 * @param string                    $viewer 'student' 或 'teacher'
	 * @param int                       $submission_id 繳交 ID
	 * @param array<string, mixed>|null $payload JSON body；null = 完全不帶 body
	 * @return \WP_REST_Response
	 */
	protected function post_as( string $viewer, int $submission_id, ?array $payload ): \WP_REST_Response {
		[ $user_id, $route ] = $this->resolve_viewer( $viewer, $submission_id );
		\wp_set_current_user( $user_id );

		return $this->dispatch( 'POST', $route, [], $payload );
	}

	// ========== 斷言：討論串回應 ==========

	/**
	 * 斷言討論串回應成功（200），且回應是「扁平列表」（不是 {items,total} 也不是關聯陣列）
	 * 回傳訊息陣列供後續斷言
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @return array<int, array<string, mixed>>
	 */
	protected function assert_thread_list_response( \WP_REST_Response $response ): array {
		$this->assert_success_response( $response );

		$data = $response->get_data();
		$this->assertIsArray( $data, '討論串回應應為陣列' );
		$this->assertSame( \array_values( $data ), $data, '討論串回應應為扁平列表（api.yml：type array），不是物件' );

		/** @var array<int, array<string, mixed>> $data */
		return $data;
	}

	/**
	 * 取出討論串回應的訊息 id 清單（不轉型，讓 assertSame 能驗 id 為 int）
	 *
	 * @param array<int, array<string, mixed>> $messages assert_thread_list_response() 的回傳
	 * @return array<int, mixed>
	 */
	protected function thread_ids( array $messages ): array {
		return \array_map(
			static fn( array $message ) => $message['id'] ?? null,
			$messages
		);
	}

	/**
	 * 斷言單一訊息的鍵（排序後）恰為 6 個白名單鍵；不得夾帶 sender_id / sender_email
	 *
	 * @param array<string, mixed> $message 單一訊息
	 */
	protected function assert_message_view_keys( array $message ): void {
		$keys = \array_keys( $message );
		\sort( $keys );

		$this->assertSame(
			self::MESSAGE_VIEW_KEYS,
			$keys,
			'訊息欄位必須恰為 body / created_at / id / mine / read_at / sender_type，不得夾帶 sender_id / sender_email（api.yml AssignmentMessage）'
		);
	}

	/**
	 * 斷言整份回應序列化後完全沒有發送者資訊（sender_id / sender_email 欄位名、admin 與 alice 的 email）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_no_sender_leak( \WP_REST_Response $response ): void {
		$json = (string) \wp_json_encode( $response->get_data() );

		$this->assertNotSame( '', $json, '回應不應為空' );
		$this->assertStringNotContainsString( 'admin@test.com', $json, '回應不得含管理員 email' );
		$this->assertStringNotContainsString( 'alice@test.com', $json, '回應不得含學員 email' );
		$this->assertStringNotContainsString( 'sender_email', $json, '回應不得含 sender_email 欄位' );
		$this->assertStringNotContainsString( 'sender_id', $json, '回應不得含 sender_id 欄位' );
	}

	/**
	 * 斷言「繳交紀錄不存在」404（DEC-W2-03）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_submission_not_found( \WP_REST_Response $response ): void {
		$this->assert_operation_failed_response( $response, '繳交紀錄不存在', 404, 'rest_not_found' );
	}

	/**
	 * 斷言「無權存取此繳交紀錄」403（DEC-W2-03；AssignmentException::forbidden = rest_forbidden）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_not_submission_owner( \WP_REST_Response $response ): void {
		$this->assert_operation_failed_response( $response, '無權存取此繳交紀錄', 403, 'rest_forbidden' );
	}

	/**
	 * 斷言「訊息內容長度必須為 1 至 10000 字」400（DEC-W2-05）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	protected function assert_invalid_message_body( \WP_REST_Response $response ): void {
		$this->assert_operation_failed_response( $response, '訊息內容長度必須為 1 至 10000 字', 400, 'rest_invalid_param' );
	}

	// ========== W2-R1：匯入批改結果 CSV ==========

	/**
	 * 建立「匯入批改結果CSV」feature 的 Background，並把「現在時間」固定為 2026-09-17 10:00:00
	 *
	 * Admin（admin@test.com，administrator）/ Alice（alice@test.com）/ Bob（bob@test.com）；
	 * 課程 100（PHP 基礎課）、章節 201（1-1 變數）、作業 400（練習一：變數宣告）；
	 * 繳交 500（Alice）submitted_at 2026-09-16 09:00:00、繳交 501（Bob）submitted_at 2026-09-16 15:30:00，
	 * 兩筆皆 pending、無評語、無 graded_by / graded_at。
	 * 與 feature 一致：Alice 沒有加入課程 100（批改與章節完成都不檢查觀看權）。
	 *
	 * 回傳（同時存入 $this->fx）的鍵：admin / alice / bob / course / chapter / assignment / sub_500 / sub_501
	 *
	 * @return array<string, int>
	 */
	protected function seed_import_fixture(): array {
		$admin_id = $this->factory()->user->create(
			[
				'user_login'   => 'admin_imp_' . \uniqid(),
				'display_name' => 'Admin',
				'user_email'   => 'admin@test.com',
				'role'         => 'administrator',
			]
		);
		$alice_id = $this->factory()->user->create(
			[
				'user_login'   => 'alice_imp_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email'   => 'alice@test.com',
				'role'         => 'subscriber',
			]
		);
		$bob_id   = $this->factory()->user->create(
			[
				'user_login'   => 'bob_imp_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email'   => 'bob@test.com',
				'role'         => 'subscriber',
			]
		);

		$course_id     = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$chapter_id    = $this->create_chapter( $course_id, [ 'post_title' => '1-1 變數' ] );
		$assignment_id = $this->create_assignment(
			$chapter_id,
			[
				'title'         => '練習一：變數宣告',
				'allow_text'    => 'yes',
				'allow_file'    => 'no',
				'required_text' => 'no',
				'required_file' => 'no',
				'max_files'     => 1,
				'due_at'        => 0,
				'menu_order'    => 0,
				'post_status'   => 'publish',
			]
		);

		$sub_500_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_id,
				'user_id'       => $alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 09:00:00',
			]
		);
		$sub_501_id = $this->seed_submission(
			[
				'assignment_id' => $assignment_id,
				'user_id'       => $bob_id,
				'text_body'     => 'const y = 2;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 15:30:00',
			]
		);

		$this->fx = [
			'admin'      => $admin_id,
			'alice'      => $alice_id,
			'bob'        => $bob_id,
			'course'     => $course_id,
			'chapter'    => $chapter_id,
			'assignment' => $assignment_id,
			'sub_500'    => $sub_500_id,
			'sub_501'    => $sub_501_id,
		];

		$this->set_now( self::NOW );

		return $this->fx;
	}

	/**
	 * 匯入 route（POST assignment-submissions/import，api.yml importAssignmentGradesCsv）
	 *
	 * @return string
	 */
	protected function import_route(): string {
		return '/power-course/assignment-submissions/import';
	}

	/**
	 * 建立一個「PHP $_FILES 形狀」的上傳檔參數，內容寫進暫存檔（tear_down 刪除）
	 *
	 * 預設 size = 內容實際長度、error = UPLOAD_ERR_OK；$overrides 可蓋掉任一鍵（name / type / tmp_name / error / size）。
	 * 注意：PHPUnit CLI 下 is_uploaded_file() 對任何路徑都是 false（計劃 R-W2-08），所以帶檔的 dispatch
	 * 只可能走到「大小 / 錯誤碼 / is_uploaded_file」這幾道上傳層檢查，到不了解析與批改。
	 *
	 * @param string               $content   檔案內容
	 * @param array<string, mixed> $overrides 覆蓋欄位
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
	 */
	protected function make_upload_file( string $content = '', array $overrides = [] ): array {
		$path = (string) \wp_tempnam( 'pc-import-' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		\file_put_contents( $path, $content );
		$this->upload_tmp_files[] = $path;

		$file = \array_merge(
			[
				'name'     => 'grades.csv',
				'type'     => 'text/csv',
				'tmp_name' => $path,
				'error'    => \UPLOAD_ERR_OK,
				'size'     => \strlen( $content ),
			],
			$overrides
		);

		/** @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
		return $file;
	}

	/**
	 * 以指定使用者身分 POST 一個 multipart 檔案到 $route，一律走 rest_get_server()->dispatch()
	 *
	 * 用 WP_REST_Request::set_file_params() 帶檔（API 層讀的是 $request->get_file_params()['file']），
	 * 不碰 $_FILES 全域。$file 為 null 時完全不設檔案參數（= 沒有附檔）。
	 *
	 * @param string                                                                            $route   REST route（含 namespace）
	 * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $file    $_FILES['file'] 形狀；null = 不附檔
	 * @param int                                                                               $user_id 操作者 user ID（0 = 訪客）
	 * @return \WP_REST_Response
	 */
	protected function dispatch_with_file( string $route, ?array $file, int $user_id ): \WP_REST_Response {
		\wp_set_current_user( $user_id );

		$request = new \WP_REST_Request( 'POST', $route );
		$request->set_header( 'Content-Type', 'multipart/form-data; boundary=----pcimport' );

		if ( null !== $file ) {
			$request->set_file_params( [ 'file' => $file ] );
		}

		return \rest_get_server()->dispatch( $request );
	}

	/**
	 * 由二維陣列組出 CSV 字串（第一列為表頭）
	 *
	 * 含逗號 / 雙引號 / CR / LF 的格會加雙引號並把內部雙引號重複一次；其餘格原樣輸出（不 trim）。
	 * 每一列（含最後一列）都以 $eol 結尾，跟 Excel / Service\Csv 匯出的形狀一致。
	 *
	 * @param array<int, array<int, string|int>> $rows 第一列為表頭
	 * @param bool                               $bom  是否在最前面加 UTF-8 BOM
	 * @param string                             $eol  列分隔（預設 CRLF）
	 * @return string
	 */
	protected function build_csv( array $rows, bool $bom = false, string $eol = "\r\n" ): string {
		$lines = [];
		foreach ( $rows as $row ) {
			$cells = [];
			foreach ( $row as $cell ) {
				$cell    = (string) $cell;
				$cells[] = 1 === \preg_match( '/[",\r\n]/', $cell )
					? '"' . \str_replace( '"', '""', $cell ) . '"'
					: $cell;
			}
			$lines[] = \implode( ',', $cells );
		}

		return ( $bom ? "\xEF\xBB\xBF" : '' ) . ( [] === $lines ? '' : \implode( $eol, $lines ) . $eol );
	}

	/**
	 * 把 wp_date( 'Y-m-d H:i:s' )（不帶 timestamp、即「真實現在時間」）固定回傳 $datetime
	 *
	 * 為什麼需要：Chapter\Service\Progress::mark_finished() 寫 finished_at 用的是 wp_date( 'Y-m-d H:i:s' ) 的真實系統時間，
	 * 不是作業模組自己的 Utils\Clock（第二波計劃不改 mark_finished，見 GradeAssignmentSubmissionTest 檔頭說明），
	 * 所以 set_now() 管不到它。feature 要求 finished_at = "2026-09-17 10:00:00"，這裡只攔「timestamp 落在真實現在
	 * ±30 秒內」的 wp_date 呼叫；Grading 寫 graded_at 時是 wp_date( ..., Clock::now() )（timestamp = 2026-09-17），
	 * 不會被攔到，所以不會讓 graded_at 的斷言變成同義反覆。tear_down 會移除這個 filter。
	 *
	 * @param string $datetime 站台時區 'Y-m-d H:i:s'
	 */
	protected function freeze_wp_date_now( string $datetime ): void {
		if ( null !== $this->wp_date_freeze_filter ) {
			\remove_filter( 'wp_date', $this->wp_date_freeze_filter, 10 );
		}

		$this->wp_date_freeze_filter = static function ( $date, $format, $timestamp ) use ( $datetime ) {
			if ( 'Y-m-d H:i:s' === $format && \abs( (int) $timestamp - \time() ) <= 30 ) {
				return $datetime;
			}

			return $date;
		};
		\add_filter( 'wp_date', $this->wp_date_freeze_filter, 10, 3 );
	}

	/**
	 * 呼叫 CsvImport::import( $raw, $actor_id )；class / method 不存在時以清楚的 Failure 取代 fatal
	 *
	 * @param string   $raw      CSV 原始內容
	 * @param int|null $actor_id 操作者（批改者）user ID，預設 fixture 的 Admin
	 * @return array<string, mixed>
	 */
	protected function import_csv( string $raw, ?int $actor_id = null ): array {
		$this->assertNotEmpty( $this->fx, '請先呼叫 seed_import_fixture()' );

		/** @var array<string, mixed> $result */
		$result = $this->call_static_or_fail( self::CSV_IMPORT_CLASS, 'import', [ $raw, $actor_id ?? $this->fx['admin'] ] );

		return $result;
	}

	/**
	 * 斷言匯入摘要：鍵集合恰為 total / updated / skipped / failed / errors，四個計數為 int 且相符，
	 * errors 依序為 [{row:int, message:string}]
	 *
	 * @param array<string, mixed>                          $result   CsvImport::import() 的回傳
	 * @param int                                           $total    期望 total
	 * @param int                                           $updated  期望 updated
	 * @param int                                           $skipped  期望 skipped
	 * @param int                                           $failed   期望 failed
	 * @param array<int, array{row: int, message: string}> $expected_errors 期望的錯誤明細（依處理順序）
	 */
	protected function assert_import_summary( array $result, int $total, int $updated, int $skipped, int $failed, array $expected_errors = [] ): void {
		$keys = \array_keys( $result );
		\sort( $keys );
		$this->assertSame( [ 'errors', 'failed', 'skipped', 'total', 'updated' ], $keys, '匯入結果的鍵應恰為 total / updated / skipped / failed / errors（api.yml AssignmentImportSummary）' );

		$context = '（實際結果：' . (string) \wp_json_encode( $result ) . '）';
		$this->assertSame( $total, $result['total'], "total 不符{$context}" );
		$this->assertSame( $updated, $result['updated'], "updated 不符{$context}" );
		$this->assertSame( $skipped, $result['skipped'], "skipped 不符{$context}" );
		$this->assertSame( $failed, $result['failed'], "failed 不符{$context}" );

		$this->assertIsArray( $result['errors'], 'errors 應為陣列' );
		$actual_errors = [];
		foreach ( $result['errors'] as $error ) {
			$this->assertIsArray( $error, 'errors 的每一項應為 {row, message}' );
			$error_keys = \array_keys( $error );
			\sort( $error_keys );
			$this->assertSame( [ 'message', 'row' ], $error_keys, 'errors 每一項的鍵應恰為 row / message' );
			$actual_errors[] = [
				'row'     => $error['row'],
				'message' => $error['message'],
			];
		}
		$this->assertSame( $expected_errors, $actual_errors, "errors 明細不符{$context}" );
	}

	/**
	 * 斷言繳交 500 / 501 完全沒被動過（仍為 pending、無評語、無 graded_by / graded_at）
	 */
	protected function assert_import_untouched(): void {
		$this->assertNotEmpty( $this->fx, '請先呼叫 seed_import_fixture()' );

		foreach ( [ 'sub_500', 'sub_501' ] as $key ) {
			$row = $this->get_submission_row( $this->fx[ $key ] );
			$this->assertIsArray( $row, "繳交 {$key} 應存在" );
			$this->assertSame( 'pending', $row['status'], "{$key} 的 status 應仍為 pending" );
			$this->assertNull( $row['feedback'], "{$key} 的 feedback 應仍為空" );
			$this->assertNull( $row['graded_by'], "{$key} 的 graded_by 應仍為空" );
			$this->assertNull( $row['graded_at'], "{$key} 的 graded_at 應仍為空" );
		}
	}
}
