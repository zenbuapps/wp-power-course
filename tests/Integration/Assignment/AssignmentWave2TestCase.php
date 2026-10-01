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
 * dispatch_with_file() 不在本階段建立（W2-R1 匯入測試才加）。
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

	/** @var array<string, int> seed_thread_fixture() 建立的 feature ID → 實際 ID 對照 */
	protected array $fx = [];

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
}
