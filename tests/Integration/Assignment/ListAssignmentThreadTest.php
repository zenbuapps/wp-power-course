<?php
/**
 * 查詢作業討論串 整合測試（Issue #131 第二波 W1）
 *
 * Feature: specs/features/assignment/查詢作業討論串.feature
 * 依據：第二波計劃 §3 第 1 列、§4 DEC-W2-02 / 03 / 04 / 06 / 07、§7.1、§8；
 *       specs/api/api.yml（listMyAssignmentMessages / listAssignmentMessagesForTeacher / AssignmentMessage）、
 *       specs/entity/erm.dbml（pc_assignment_messages Note「已讀模型」）
 *
 * ─── 防說謊約定（第二波計劃 §13、W2:RED-PHP 補充 a～g）──────────────────
 * - 每個測試都走 rest_get_server()->dispatch()（含 wp_set_current_user），先斷言
 *   「200」或「期望的錯誤 status + code + zh_TW 訊息」，再斷言回應欄位 / DB。
 * - 不存在的繳交 ID 用 nonexistent_id()，不用字面 9999。
 * - 「不檢查觀看權」在 When 之前先斷言 Utils::can_access_course( 課程, Alice ) === false。
 * - 「不含 sender_email / sender_id」先確認 DB 裡真的存了 admin@test.com（fixture 的 601），
 *   再斷言每筆訊息的鍵（排序後）恰為 6 個白名單鍵，且序列化後的回應不含 admin@test.com。
 * - read_at 斷言字面值 '2026-09-17 10:00:00'（set_now 注入），不用 assertNotNull。
 * - 錯誤路徑同時斷言「沒有任何訊息被標已讀」（標已讀不得發生在權限 / 存在檢查之前）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;

/**
 * Class ListAssignmentThreadTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class ListAssignmentThreadTest extends AssignmentWave2TestCase {

	/**
	 * 建立 Background（查詢作業討論串 feature）：Admin/Alice/Bob、課程 100、章節 201、作業 400、
	 * Alice 已加入課程 100、繳交 500/501、訊息 600/601/602、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_thread_fixture( 'list' );
	}

	/**
	 * 斷言訊息 read_at 欄位為指定字面值（null 代表尚未讀）
	 *
	 * @param int         $message_id 訊息 ID
	 * @param string|null $expected 期望的 read_at
	 * @param string      $label 失敗訊息前綴
	 */
	private function assert_message_read_at( int $message_id, ?string $expected, string $label ): void {
		$row = $this->get_message_row( $message_id );
		$this->assertIsArray( $row, "{$label}：訊息 {$message_id} 應存在" );

		if ( null === $expected ) {
			$this->assertNull( $row['read_at'], "{$label}：訊息 {$message_id} 的 read_at 應為空" );
			return;
		}

		$this->assertSame( $expected, $row['read_at'], "{$label}：訊息 {$message_id} 的 read_at 不符" );
	}

	/**
	 * 斷言 fixture 的訊息 600 / 601 都還沒被標已讀（錯誤路徑不得有副作用）
	 *
	 * @param string $label 失敗訊息前綴
	 */
	private function assert_fixture_messages_still_unread( string $label ): void {
		$this->assert_message_read_at( $this->fx['msg_600'], null, $label );
		$this->assert_message_read_at( $this->fx['msg_601'], null, $label );
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客查詢討論串時操作失敗
	 */
	public function test_未登入訪客查詢討論串時操作失敗(): void {
		\wp_set_current_user( 0 );

		$response = $this->dispatch( 'GET', $this->my_thread_route( $this->fx['sub_500'] ) );

		$this->assert_not_logged_in( $response );
		$this->assert_fixture_messages_still_unread( '未登入' );
	}

	// ========== 前置（狀態）- 管理員端操作者必須為管理員 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 管理員端操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員呼叫管理員端討論串時操作失敗
	 */
	public function test_非管理員呼叫管理員端討論串時操作失敗(): void {
		\wp_set_current_user( $this->fx['bob'] );

		$response = $this->dispatch( 'GET', $this->admin_thread_route( $this->fx['sub_500'] ) );

		$this->assert_permission_denied( $response );
		$this->assert_fixture_messages_still_unread( '非管理員' );
	}

	// ========== 前置（狀態）- 繳交紀錄必須存在 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在
	 * Example: 學員查詢不存在的繳交討論串時操作失敗
	 */
	public function test_學員查詢不存在的繳交討論串時操作失敗(): void {
		$response = $this->get_thread_as( 'student', $this->nonexistent_id() );

		$this->assert_submission_not_found( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在
	 * Example: 管理員查詢不存在的繳交討論串時操作失敗
	 */
	public function test_管理員查詢不存在的繳交討論串時操作失敗(): void {
		$response = $this->get_thread_as( 'teacher', $this->nonexistent_id() );

		$this->assert_submission_not_found( $response );
	}

	// ========== 前置（狀態）- 學員只能查詢自己繳交的討論串 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 學員只能查詢自己繳交的討論串
	 * Example: Alice 查詢 Bob 繳交的討論串時操作失敗
	 *
	 * 額外前置：Bob 的繳交 501 有一則老師未讀訊息。權限檢查失敗後它必須仍是未讀
	 * （標已讀不得發生在 ownership 檢查之前）。
	 */
	public function test_Alice查詢Bob繳交的討論串時操作失敗(): void {
		$bob_unread_id = $this->seed_message(
			[
				'submission_id' => $this->fx['sub_501'],
				'sender_type'   => 'teacher',
				'sender_id'     => $this->fx['admin'],
				'sender_email'  => 'admin@test.com',
				'body'          => '給 Bob 的回覆',
				'created_at'    => '2026-09-16 11:00:00',
			]
		);

		$response = $this->get_thread_as( 'student', $this->fx['sub_501'] );

		$this->assert_not_submission_owner( $response );
		$this->assert_message_read_at( $bob_unread_id, null, 'Alice 越權查詢 Bob 的討論串' );
		$this->assert_fixture_messages_still_unread( 'Alice 越權查詢 Bob 的討論串' );
	}

	// ========== 前置（狀態）- 學員查詢討論串不檢查課程觀看權 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 學員查詢討論串不檢查課程觀看權
	 * Example: 已被移出課程的 Alice 仍可查詢自己的討論串
	 */
	public function test_已被移出課程的Alice仍可查詢自己的討論串(): void {
		// Given 用戶 "Alice" 未被加入課程 100
		$this->unenroll_user_from_course( $this->fx['alice'], $this->fx['course'] );
		$this->assertFalse(
			AssignmentUtils::can_access_course( $this->fx['course'], $this->fx['alice'] ),
			'前置：Alice 此時對課程 100 應無有效觀看權，否則「不檢查觀看權」的測試沒有意義'
		);

		// When 用戶 "Alice" 查詢作業繳交 500 的討論串
		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );

		// Then 操作成功
		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame( [ $this->fx['msg_600'], $this->fx['msg_601'] ], $this->thread_ids( $messages ) );
	}

	// ========== 後置（回應）- 依建立時間由舊到新、mine 標示 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 依建立時間由舊到新回傳未刪除的訊息，並以 mine 標示是否為查詢者這一方發送
	 * Example: 學員端查詢討論串
	 */
	public function test_學員端查詢討論串(): void {
		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );

		// 已軟刪除的 602 不在列表中；依 created_at 由舊到新
		$this->assertSame( [ $this->fx['msg_600'], $this->fx['msg_601'] ], $this->thread_ids( $messages ) );

		$this->assertSame( 'student', $messages[0]['sender_type'] );
		$this->assertSame( '老師請問型別', $messages[0]['body'] );
		$this->assertSame( true, $messages[0]['mine'] );
		$this->assertSame( '2026-09-16 09:10:00', $messages[0]['created_at'] );

		$this->assertSame( 'teacher', $messages[1]['sender_type'] );
		$this->assertSame( '參考 1-1 的範例', $messages[1]['body'] );
		$this->assertSame( false, $messages[1]['mine'] );
		$this->assertSame( '2026-09-16 10:00:00', $messages[1]['created_at'] );

		// DEC-W2-04：先標已讀再讀取，所以回應裡「對方（老師）」訊息的 read_at 已是現在；自己的訊息不動
		$this->assertNull( $messages[0]['read_at'] );
		$this->assertSame( '2026-09-17 10:00:00', $messages[1]['read_at'] );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 依建立時間由舊到新回傳未刪除的訊息，並以 mine 標示是否為查詢者這一方發送
	 * Example: 管理員端查詢討論串
	 */
	public function test_管理員端查詢討論串(): void {
		$response = $this->get_thread_as( 'teacher', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );

		$this->assertSame( [ $this->fx['msg_600'], $this->fx['msg_601'] ], $this->thread_ids( $messages ) );

		$this->assertSame( 'student', $messages[0]['sender_type'] );
		$this->assertSame( '老師請問型別', $messages[0]['body'] );
		$this->assertSame( false, $messages[0]['mine'] );

		$this->assertSame( 'teacher', $messages[1]['sender_type'] );
		$this->assertSame( '參考 1-1 的範例', $messages[1]['body'] );
		$this->assertSame( true, $messages[1]['mine'] );

		// DEC-W2-04：回應裡「對方（學員）」訊息的 read_at 已是現在；自己（老師）的訊息不動
		$this->assertSame( '2026-09-17 10:00:00', $messages[0]['read_at'] );
		$this->assertNull( $messages[1]['read_at'] );
	}

	// ========== 後置（回應）- 不回傳發送者 id 與 email ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（回應）- 訊息只回傳 id、sender_type、body、read_at、created_at 與 mine，不回傳發送者 id 與 email
	 * Example: 學員端回應不含老師的 email
	 */
	public function test_學員端回應不含老師的email(): void {
		// 前置：老師訊息 601 在 DB 裡真的存了 sender_email，否則「回應不含 email」是空洞的
		$seeded = $this->get_message_row( $this->fx['msg_601'] );
		$this->assertIsArray( $seeded );
		$this->assertSame( 'admin@test.com', $seeded['sender_email'], 'fixture 的老師訊息應帶 sender_email 快照' );
		$this->assertSame( (string) $this->fx['admin'], (string) $seeded['sender_id'], 'fixture 的老師訊息應帶 sender_id' );

		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertCount( 2, $messages );
		foreach ( $messages as $message ) {
			$this->assert_message_view_keys( $message );
		}
		$this->assert_no_sender_leak( $response );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（回應）- 訊息只回傳 id、sender_type、body、read_at、created_at 與 mine，不回傳發送者 id 與 email
	 * Example（邊界）: 管理員端回應同樣不含 sender_id / sender_email（api.yml：兩端皆不回傳）
	 */
	public function test_管理員端回應同樣不含發送者資訊(): void {
		$seeded = $this->get_message_row( $this->fx['msg_601'] );
		$this->assertIsArray( $seeded );
		$this->assertSame( 'admin@test.com', $seeded['sender_email'], 'fixture 的老師訊息應帶 sender_email 快照' );

		$response = $this->get_thread_as( 'teacher', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertCount( 2, $messages );
		foreach ( $messages as $message ) {
			$this->assert_message_view_keys( $message );
		}
		$this->assert_no_sender_leak( $response );
	}

	// ========== 後置（狀態）- 查詢後對方未讀訊息標為已讀 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 學員查詢後，老師發送且未讀的訊息標為已讀
	 * Example: Alice 讀取後老師訊息已讀
	 */
	public function test_Alice讀取後老師訊息已讀(): void {
		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );

		$this->assert_success_response( $response );
		$this->assert_message_read_at( $this->fx['msg_601'], '2026-09-17 10:00:00', 'Alice 讀取後' );
		$this->assert_message_read_at( $this->fx['msg_600'], null, 'Alice 讀取後（自己發的訊息不動）' );
		$this->assert_message_read_at( $this->fx['msg_602'], null, 'Alice 讀取後（已軟刪除的訊息不動）' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 管理員查詢後，學員發送且未讀的訊息標為已讀
	 * Example: 管理員讀取後學員訊息已讀
	 *
	 * 已軟刪除的學員訊息 602 不得被標已讀（DEC-W2-04：UPDATE 條件含 deleted_at IS NULL）。
	 */
	public function test_管理員讀取後學員訊息已讀(): void {
		$response = $this->get_thread_as( 'teacher', $this->fx['sub_500'] );

		$this->assert_success_response( $response );
		$this->assert_message_read_at( $this->fx['msg_600'], '2026-09-17 10:00:00', '管理員讀取後' );
		$this->assert_message_read_at( $this->fx['msg_601'], null, '管理員讀取後（自己發的訊息不動）' );
		$this->assert_message_read_at( $this->fx['msg_602'], null, '管理員讀取後（已軟刪除的學員訊息不動）' );
	}

	// ========== 邊界（第二波計劃 §11 W1 / DEC-W2-03 / DEC-W2-04） ==========

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_viewers(): array {
		return [
			'學員端'   => [ 'student' ],
			'管理員端' => [ 'teacher' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_viewers
	 *
	 * 已有 read_at 的訊息不被覆寫：先 seed 一則 read_at = 2026-09-16 12:00:00 的對方訊息，
	 * 查詢後仍是該值；同一串中尚未讀的對方訊息才被標成現在。
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_已有read_at的訊息不被覆寫( string $viewer ): void {
		$counterpart_type = 'student' === $viewer ? 'teacher' : 'student';
		$counterpart_id   = 'student' === $viewer ? $this->fx['admin'] : $this->fx['alice'];

		$already_read_id = $this->seed_message(
			[
				'submission_id' => $this->fx['sub_500'],
				'sender_type'   => $counterpart_type,
				'sender_id'     => $counterpart_id,
				'body'          => '早就讀過的訊息',
				'read_at'       => '2026-09-16 12:00:00',
				'created_at'    => '2026-09-16 11:00:00',
			]
		);

		$response = $this->get_thread_as( $viewer, $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame( '2026-09-16 12:00:00', $this->get_message_row( $already_read_id )['read_at'] ?? null, '已有 read_at 的訊息不得被覆寫（DB）' );

		$by_id = [];
		foreach ( $messages as $message ) {
			$by_id[ $message['id'] ] = $message;
		}
		$this->assertArrayHasKey( $already_read_id, $by_id, '已讀訊息應出現在回應中' );
		$this->assertSame( '2026-09-16 12:00:00', $by_id[ $already_read_id ]['read_at'], '已有 read_at 的訊息不得被覆寫（回應）' );

		// 同一串中原本未讀的對方訊息 600（學員發）/ 601（老師發）才被標成現在
		$expected_unread_target = 'student' === $viewer ? $this->fx['msg_601'] : $this->fx['msg_600'];
		$this->assert_message_read_at( $expected_unread_target, '2026-09-17 10:00:00', '尚未讀的對方訊息' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_viewers
	 *
	 * 連讀兩次回應相同：第二次（站台時間已前進一小時）對方訊息的 read_at 仍是第一次的值，
	 * 整份回應與第一次完全一致。
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_連讀兩次回應相同( string $viewer ): void {
		$first = $this->get_thread_as( $viewer, $this->fx['sub_500'] );
		$first_messages = $this->assert_thread_list_response( $first );

		// 站台時間前進一小時：若第二次讀取又去覆寫 read_at，這裡會變成 11:00:00
		$this->set_now( '2026-09-17 11:00:00' );

		$second = $this->get_thread_as( $viewer, $this->fx['sub_500'] );
		$second_messages = $this->assert_thread_list_response( $second );

		$this->assertSame( $first_messages, $second_messages, '連讀兩次的回應應完全相同' );

		$counterpart_message_id = 'student' === $viewer ? $this->fx['msg_601'] : $this->fx['msg_600'];
		$this->assert_message_read_at( $counterpart_message_id, '2026-09-17 10:00:00', '第二次讀取後' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * POST 不會把任何訊息標已讀：學員與管理員各發一則訊息（都先斷言 200），
	 * 原本未讀的 600 / 601 的 read_at 仍為 null。
	 */
	public function test_POST不會把任何訊息標已讀(): void {
		$student_post = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '學員補充說明' ] );
		$this->assert_success_response( $student_post );

		$teacher_post = $this->post_as( 'teacher', $this->fx['sub_500'], [ 'body' => '老師補充說明' ] );
		$this->assert_success_response( $teacher_post );

		$this->assert_fixture_messages_still_unread( 'POST 之後' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 管理員讀取「作業在垃圾桶」的繳交 → 200（DEC-W2-03：討論串只要求繳交存在，不檢查作業狀態）
	 */
	public function test_管理員讀取作業在垃圾桶的繳交時操作成功(): void {
		\wp_update_post(
			[
				'ID'          => $this->fx['assignment'],
				'post_status' => 'trash',
			]
		);
		$this->assert_assignment_fields( $this->fx['assignment'], [ 'post_status' => 'trash' ] );

		$response = $this->get_thread_as( 'teacher', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame( [ $this->fx['msg_600'], $this->fx['msg_601'] ], $this->thread_ids( $messages ) );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 同一秒兩則訊息依 id 由小到大排序（DEC-W2-06：created_at ASC, id ASC）
	 */
	public function test_同一秒兩則訊息依id由小到大排序(): void {
		$first_id  = $this->seed_message(
			[
				'submission_id' => $this->fx['sub_500'],
				'sender_type'   => 'student',
				'sender_id'     => $this->fx['alice'],
				'body'          => '同一秒的第一則',
				'created_at'    => '2026-09-16 11:00:00',
			]
		);
		$second_id = $this->seed_message(
			[
				'submission_id' => $this->fx['sub_500'],
				'sender_type'   => 'teacher',
				'sender_id'     => $this->fx['admin'],
				'body'          => '同一秒的第二則',
				'created_at'    => '2026-09-16 11:00:00',
			]
		);
		$this->assertGreaterThan( $first_id, $second_id, '前置：第二則的 id 應大於第一則' );

		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame(
			[ $this->fx['msg_600'], $this->fx['msg_601'], $first_id, $second_id ],
			$this->thread_ids( $messages ),
			'created_at 相同時應依 id ASC 排序'
		);
	}

	/**
	 * @test
	 * @group edge
	 *
	 * mine 依端別而非 sender_id（DEC-W2-04）：另一位管理員讀取時，Admin 發的老師訊息 mine 仍為 true
	 */
	public function test_另一位管理員讀取時老師訊息的mine仍為true(): void {
		$admin_two_id = $this->factory()->user->create(
			[
				'user_login'   => 'admin_two_w2_' . \uniqid(),
				'display_name' => 'Admin Two',
				'user_email'   => 'admin_two@test.com',
				'role'         => 'administrator',
			]
		);
		\wp_set_current_user( $admin_two_id );

		$response = $this->dispatch( 'GET', $this->admin_thread_route( $this->fx['sub_500'] ) );

		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame( [ $this->fx['msg_600'], $this->fx['msg_601'] ], $this->thread_ids( $messages ) );
		$this->assertSame( false, $messages[0]['mine'], '學員訊息對管理員端 mine 應為 false' );
		$this->assertSame( true, $messages[1]['mine'], '老師訊息對任一管理員 mine 應為 true（不看 sender_id）' );
	}
}
