<?php
/**
 * 發送作業討論訊息 整合測試（Issue #131 第二波 W1）
 *
 * Feature: specs/features/assignment/發送作業討論訊息.feature
 * 依據：第二波計劃 §3 第 2 列、§4 DEC-W2-02 / 03 / 05 / 06 / 08、§7.2、§8；
 *       specs/api/api.yml（postMyAssignmentMessage / postAssignmentMessageAsTeacher / AssignmentMessageBody）、
 *       specs/entity/erm.dbml（pc_assignment_messages）、memory project_wp_slash_symmetry
 *
 * ─── 防說謊約定（第二波計劃 §13、W2:RED-PHP 補充 a～g）──────────────────
 * - 每個測試都走 rest_get_server()->dispatch()（含 wp_set_current_user），先斷言
 *   「200」或「期望的錯誤 status + code + zh_TW 訊息」，再斷言回應欄位 / DB。
 * - 錯誤路徑同時斷言「沒有任何訊息被寫入」（count_messages() 仍為 0），
 *   擋住「先 INSERT 再驗證」的實作。
 * - 不存在的繳交 ID 用 nonexistent_id()，不用字面 9999。
 * - 「不檢查觀看權」在 When 之前先斷言 Utils::can_access_course( 課程, Alice ) === false。
 * - 「不含 sender_email / sender_id」先 seed 一則 teacher + sender_email = admin@test.com 的訊息，
 *   並確認管理員發送後 DB 真的存了 email 快照，再斷言回應的鍵（排序後）恰為 6 個白名單鍵、
 *   序列化後的回應不含 admin@test.com。
 * - created_at 斷言字面值 '2026-09-17 10:00:00'（set_now 注入）；布林用 assertSame( true / false )。
 * - 邊界案例（10000 字、空白、HTML、反斜線、非字串 body）學員端與管理員端各測一次，
 *   避免兩條寫入路徑各自處理內容而漂移。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;

/**
 * Class PostAssignmentMessageTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class PostAssignmentMessageTest extends AssignmentWave2TestCase {

	/**
	 * 建立 Background（發送作業討論訊息 feature）：Admin/Alice/Bob、課程 100、章節 201、作業 400、
	 * Alice 已加入課程 100、繳交 500（fail + 評語）/ 501（pending）、沒有訊息、現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_thread_fixture( 'post' );
	}

	/**
	 * 取得測試用 user 的 email（斷言 teacher 訊息的 email 快照用）
	 *
	 * @param int $user_id 用戶 ID
	 * @return string
	 */
	private function user_email( int $user_id ): string {
		$user = \get_userdata( $user_id );
		$this->assertInstanceOf( \WP_User::class, $user, "用戶 {$user_id} 應存在" );

		return $user->user_email;
	}

	/**
	 * 斷言發送成功（200）、回應內容為指定訊息，且 DB 最後一則就是這則訊息（原樣保存）
	 *
	 * @param \WP_REST_Response $response   REST 回應
	 * @param string            $viewer     'student' 或 'teacher'
	 * @param string            $body       期望的訊息內容（與送出的原文逐字相同）
	 */
	private function assert_posted_verbatim( \WP_REST_Response $response, string $viewer, string $body ): void {
		$this->assert_success_response( $response );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( $body, $data['body'] ?? null, '回應的 body 應與送出的原文逐字相同' );

		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row, '應寫入一則訊息' );
		$this->assertSame( 1, $this->count_messages( $this->fx['sub_500'] ), '應恰好寫入一則訊息' );
		$this->assertSame( $body, $row['body'], 'DB 的 body 應與送出的原文逐字相同（不 trim、不 sanitize、不多一層 slash）' );
		$this->assertSame( $viewer, $row['sender_type'], 'sender_type 應與發送端一致' );
		$this->assertSame( (int) $row['id'], $data['id'] ?? null, '回應的 id 應為新訊息的 id（int）' );
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客發送訊息時操作失敗
	 */
	public function test_未登入訪客發送訊息時操作失敗(): void {
		\wp_set_current_user( 0 );

		$response = $this->dispatch( 'POST', $this->my_thread_route( $this->fx['sub_500'] ), [], [ 'body' => '你好' ] );

		$this->assert_not_logged_in( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_500'] ), '未登入不得寫入訊息' );
	}

	// ========== 前置（狀態）- 管理員端操作者必須為管理員 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 管理員端操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員呼叫管理員端發送訊息時操作失敗
	 *
	 * feature 的 When 沒有帶 body：路由不得宣告 required args，否則 WP 會先回 400（DEC-W2-02）。
	 */
	public function test_非管理員呼叫管理員端發送訊息時操作失敗(): void {
		\wp_set_current_user( $this->fx['bob'] );

		$response = $this->dispatch( 'POST', $this->admin_thread_route( $this->fx['sub_500'] ) );

		$this->assert_permission_denied( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_500'] ), '非管理員不得寫入訊息' );
	}

	// ========== 前置（狀態）- 繳交紀錄必須存在 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在
	 * Example: 學員在不存在的繳交發送訊息時操作失敗
	 */
	public function test_學員在不存在的繳交發送訊息時操作失敗(): void {
		$missing_id = $this->nonexistent_id();

		$response = $this->post_as( 'student', $missing_id, [ 'body' => '你好' ] );

		$this->assert_submission_not_found( $response );
		$this->assertSame( 0, $this->count_messages( $missing_id ), '繳交不存在不得寫入訊息' );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 繳交紀錄必須存在
	 * Example: 管理員在不存在的繳交發送訊息時操作失敗
	 */
	public function test_管理員在不存在的繳交發送訊息時操作失敗(): void {
		$missing_id = $this->nonexistent_id();

		$response = $this->post_as( 'teacher', $missing_id, [ 'body' => '你好' ] );

		$this->assert_submission_not_found( $response );
		$this->assertSame( 0, $this->count_messages( $missing_id ), '繳交不存在不得寫入訊息' );
	}

	// ========== 前置（狀態）- 學員只能在自己繳交的討論串發送訊息 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 學員只能在自己繳交的討論串發送訊息
	 * Example: Alice 在 Bob 的繳交發送訊息時操作失敗
	 */
	public function test_Alice在Bob的繳交發送訊息時操作失敗(): void {
		$response = $this->post_as( 'student', $this->fx['sub_501'], [ 'body' => '你好' ] );

		$this->assert_not_submission_owner( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_501'] ), '非本人繳交不得寫入訊息' );
	}

	// ========== 前置（狀態）- 學員發送訊息不檢查課程觀看權 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 學員發送訊息不檢查課程觀看權
	 * Example: 已被移出課程的 Alice 仍可在自己的討論串發送訊息
	 */
	public function test_已被移出課程的Alice仍可在自己的討論串發送訊息(): void {
		// Given 用戶 "Alice" 未被加入課程 100
		$this->unenroll_user_from_course( $this->fx['alice'], $this->fx['course'] );
		$this->assertFalse(
			AssignmentUtils::can_access_course( $this->fx['course'], $this->fx['alice'] ),
			'前置：Alice 此時對課程 100 應無有效觀看權，否則「不檢查觀看權」的測試沒有意義'
		);

		// When 用戶 "Alice" 在作業繳交 500 的討論串發送訊息
		$response = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '請問哪裡要補充' ] );

		// Then 操作成功
		$this->assert_success_response( $response );
		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row );
		$this->assertSame( '請問哪裡要補充', $row['body'] );
	}

	// ========== 前置（參數）- 訊息內容長度必須為 1 至 10000 字 ==========

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_invalid_body_lengths(): array {
		return [
			'空字串'        => [ '' ],
			'超過 10000 字' => [ \str_repeat( 'x', 10001 ) ],
		];
	}

	/**
	 * @test
	 * @group error
	 * @dataProvider provide_invalid_body_lengths
	 *
	 * Rule: 前置（參數）- 訊息內容長度必須為 1 至 10000 字
	 * Scenario Outline: 訊息內容為<情境>時操作失敗
	 *
	 * @param string $body 訊息內容
	 */
	public function test_訊息內容長度不合法時操作失敗( string $body ): void {
		$response = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => $body ] );

		$this->assert_invalid_message_body( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_500'] ), '參數不合法不得寫入訊息' );
	}

	// ========== 後置（狀態）- 學員發送的訊息記錄為 student ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 學員發送的訊息記錄為 student，接收方未讀
	 * Example: Alice 發送訊息
	 */
	public function test_Alice發送訊息(): void {
		$response = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '請問哪裡要補充' ] );

		$this->assert_success_response( $response );

		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row, '應寫入一則訊息' );
		$this->assertSame( 1, $this->count_messages( $this->fx['sub_500'] ) );
		$this->assertSame( 'student', $row['sender_type'] );
		$this->assertSame( (string) $this->fx['alice'], (string) $row['sender_id'] );
		$this->assertNull( $row['sender_email'], '學員訊息不記 sender_email（NULL）' );
		$this->assertSame( '請問哪裡要補充', $row['body'] );
		$this->assertNull( $row['read_at'], '新訊息 read_at 為空（接收方尚未讀）' );
		$this->assertSame( '2026-09-17 10:00:00', $row['created_at'] );
		$this->assertNull( $row['deleted_at'] );
	}

	// ========== 後置（狀態）- 管理員發送的訊息記錄為 teacher 並保存 email 快照 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（狀態）- 管理員發送的訊息記錄為 teacher 並保存管理員 email 快照，接收方未讀
	 * Example: 管理員發送訊息
	 */
	public function test_管理員發送訊息(): void {
		$this->assertSame( 'admin@test.com', $this->user_email( $this->fx['admin'] ), '前置：Admin 的 email 為 admin@test.com' );

		$response = $this->post_as( 'teacher', $this->fx['sub_500'], [ 'body' => '請說明為什麼用 let' ] );

		$this->assert_success_response( $response );

		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row, '應寫入一則訊息' );
		$this->assertSame( 1, $this->count_messages( $this->fx['sub_500'] ) );
		$this->assertSame( 'teacher', $row['sender_type'] );
		$this->assertSame( (string) $this->fx['admin'], (string) $row['sender_id'] );
		$this->assertSame( 'admin@test.com', $row['sender_email'], '老師訊息應保存管理員 email 快照' );
		$this->assertSame( '請說明為什麼用 let', $row['body'] );
		$this->assertNull( $row['read_at'], '新訊息 read_at 為空（接收方尚未讀）' );
		$this->assertSame( '2026-09-17 10:00:00', $row['created_at'] );
	}

	// ========== 後置（回應）- 回傳新訊息並標示為查詢者自己發送 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 回傳新訊息並標示為查詢者自己發送
	 * Example: 發送成功後回應新訊息
	 */
	public function test_發送成功後回應新訊息(): void {
		$response = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '請問哪裡要補充' ] );

		$this->assert_success_response( $response );
		$this->assert_response_includes(
			$response,
			[
				'sender_type' => 'student',
				'body'        => '請問哪裡要補充',
				'read_at'     => null,
				'mine'        => true,
			]
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assert_message_view_keys( $data );
		$this->assertSame( '2026-09-17 10:00:00', $data['created_at'] );

		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertIsArray( $row );
		$this->assertSame( (int) $row['id'], $data['id'], '回應的 id 應為新訊息的 id（int）' );
	}

	// ========== 邊界（第二波計劃 §11 W1 / DEC-W2-02 / 03 / 05） ==========

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
	 * 恰好 10000 字（用多位元組字元，30000 bytes）→ 200 且原樣保存：長度以字元數計，不是 bytes
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_恰好10000字時成功且原樣保存( string $viewer ): void {
		$body = \str_repeat( '字', 10000 );
		$this->assertSame( 10000, \mb_strlen( $body ), '前置：測試資料恰為 10000 字' );

		$response = $this->post_as( $viewer, $this->fx['sub_500'], [ 'body' => $body ] );

		$this->assert_posted_verbatim( $response, $viewer, $body );
		$row = $this->get_last_message_row( $this->fx['sub_500'] );
		$this->assertSame( 10000, \mb_strlen( (string) ( $row['body'] ?? '' ) ), 'DB 保存的 body 應仍為 10000 字（未被截斷）' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_viewers
	 *
	 * 只有空白 "   " → 200 且原樣保存（DEC-W2-05：不 trim）
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_只有空白時成功且原樣保存( string $viewer ): void {
		$response = $this->post_as( $viewer, $this->fx['sub_500'], [ 'body' => '   ' ] );

		$this->assert_posted_verbatim( $response, $viewer, '   ' );
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function provide_invalid_body_payloads(): array {
		$cases = [];

		foreach ( [ 'student' => '學員端', 'teacher' => '管理員端' ] as $viewer => $label ) {
			$cases[ "{$label}：body 為陣列" ]   = [ $viewer, [ 'body' => [ '你好' ] ] ];
			$cases[ "{$label}：body 為數字" ]   = [ $viewer, [ 'body' => 123 ] ];
			$cases[ "{$label}：body 為 null" ]  = [ $viewer, [ 'body' => null ] ];
			$cases[ "{$label}：沒帶 body" ]     = [ $viewer, [] ];
		}

		// 管理員端的長度邊界（feature Outline 只用學員端，這裡確認兩條寫入路徑驗證一致）
		$cases['管理員端：空字串']        = [ 'teacher', [ 'body' => '' ] ];
		$cases['管理員端：超過 10000 字'] = [ 'teacher', [ 'body' => \str_repeat( 'x', 10001 ) ] ];

		return $cases;
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_invalid_body_payloads
	 *
	 * body 為陣列 / 數字 / null / 沒帶，或管理員端長度不合法 → 400 同一句訊息（DEC-W2-05），且不寫入
	 *
	 * @param string               $viewer  'student' 或 'teacher'
	 * @param array<string, mixed> $payload JSON body
	 */
	public function test_body不合法時一律回應同一句400訊息( string $viewer, array $payload ): void {
		$response = $this->post_as( $viewer, $this->fx['sub_500'], $payload );

		$this->assert_invalid_message_body( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_500'] ), '參數不合法不得寫入訊息' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_html_payloads(): array {
		$cases = [];

		foreach ( [ 'student' => '學員端', 'teacher' => '管理員端' ] as $viewer => $label ) {
			$cases[ "{$label}：粗體標籤" ]    = [ $viewer, '<b>x</b>' ];
			$cases[ "{$label}：script 標籤" ] = [ $viewer, '<script>alert("xss")</script>' ];
		}

		return $cases;
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider provide_html_payloads
	 *
	 * HTML 原樣保存（DEC-W2-05：不 sanitize、不 wp_kses；輸出端負責跳脫）
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 * @param string $body   含 HTML 的訊息內容
	 */
	public function test_HTML內容原樣保存( string $viewer, string $body ): void {
		$response = $this->post_as( $viewer, $this->fx['sub_500'], [ 'body' => $body ] );

		$this->assert_posted_verbatim( $response, $viewer, $body );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_slash_payloads(): array {
		$bodies = [
			'Windows 路徑與結尾反斜線' => 'C:\path "q"\\',
			'反斜線加字母的跳脫序列'   => 'line1\nline2 \t tab \u00e9 \\\\ end',
			'單雙引號'                 => 'O\'Reilly said "hi" and \'bye\'',
			'CRLF 與 emoji'            => "第一行\r\n第二行 😀",
		];

		$cases = [];
		foreach ( [ 'student' => '學員端', 'teacher' => '管理員端' ] as $viewer => $label ) {
			foreach ( $bodies as $name => $body ) {
				$cases[ "{$label}：{$name}" ] = [ $viewer, $body ];
			}
		}

		return $cases;
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_slash_payloads
	 *
	 * slash 對稱（memory project_wp_slash_symmetry）：$wpdb->insert 直寫不得 wp_slash，
	 * 否則反斜線會被多加一層；存入後 DB 內容必須與送出的原文逐字相同
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 * @param string $body   含反斜線 / 引號 / CRLF 的訊息內容
	 */
	public function test_含反斜線與引號的內容存入後逐字相同( string $viewer, string $body ): void {
		$response = $this->post_as( $viewer, $this->fx['sub_500'], [ 'body' => $body ] );

		$this->assert_posted_verbatim( $response, $viewer, $body );
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider provide_viewers
	 *
	 * POST 回應不含 sender_id / sender_email（W2:RED-PHP 補充 d）：
	 * 先 seed 一則帶 sender_email 的老師訊息；管理員端發送時 DB 也會存 email 快照，
	 * 回應必須仍只有 6 個白名單鍵，且序列化後不含 admin@test.com。
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_POST回應不含sender_id與sender_email( string $viewer ): void {
		$seeded_id = $this->seed_message(
			[
				'submission_id' => $this->fx['sub_500'],
				'sender_type'   => 'teacher',
				'sender_id'     => $this->fx['admin'],
				'sender_email'  => 'admin@test.com',
				'body'          => '先前老師的回覆',
				'created_at'    => '2026-09-16 11:00:00',
			]
		);
		$this->assertSame( 'admin@test.com', $this->get_message_row( $seeded_id )['sender_email'] ?? null, '前置：DB 內確實存了 email，回應不含 email 才有意義' );

		$response = $this->post_as( $viewer, $this->fx['sub_500'], [ 'body' => '新的訊息' ] );

		$this->assert_success_response( $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assert_message_view_keys( $data );
		$this->assert_no_sender_leak( $response );

		if ( 'teacher' === $viewer ) {
			$row = $this->get_last_message_row( $this->fx['sub_500'] );
			$this->assertSame( 'admin@test.com', $row['sender_email'] ?? null, '前置：管理員發送後 DB 也存了 email 快照' );
		}
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provide_viewers
	 *
	 * 繳交不存在且 body 不合法 → 404 而不是 400（DEC-W2-03：先資源後參數）
	 *
	 * @param string $viewer 'student' 或 'teacher'
	 */
	public function test_繳交不存在且body不合法時回應404而非400( string $viewer ): void {
		$response = $this->post_as( $viewer, $this->nonexistent_id(), [ 'body' => '' ] );

		$this->assert_submission_not_found( $response );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 非本人繳交且 body 不合法 → 403 而不是 400（DEC-W2-03：ownership 先於 body 驗證）
	 */
	public function test_非本人繳交且body不合法時回應403而非400(): void {
		$response = $this->post_as( 'student', $this->fx['sub_501'], [ 'body' => '' ] );

		$this->assert_not_submission_owner( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_501'] ) );
	}
}
