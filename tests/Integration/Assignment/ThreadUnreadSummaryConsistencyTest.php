<?php
/**
 * 討論串未讀定義一致性 整合測試（Issue #131 第二波 W1）
 *
 * 無直接對應單一 .feature，依據：
 *   - 第二波計劃 §3 第 3 列、§4 DEC-W2-04（寫入路徑與第一波 MessageRepository::thread_summary()
 *     同一套定義）、§14 R-W2-19（sender_type 字串在寫入端與統計端漂移）、§9 失敗模式「學員重新繳交」
 *   - specs/entity/erm.dbml pc_assignment_messages Note「已讀模型」
 *
 * 為什麼要這支測試：第一波的「討論」徽章（unread_count / message_count / last_message_at）
 * 只靠測試直接 seed 訊息驗證；第二波才有「真的由 REST 寫入與標已讀」的路徑。
 * 若寫入端把 sender_type 存成 'Student' / 'teacher ' 之類與統計端不同的字串，
 * 徽章會永遠是 0，而兩邊各自的測試都是綠的。這裡一律「用新 REST 寫入 / 讀取」，
 * 再透過管理端列表 GET assignment-submissions 觀察第一波的統計，確認兩邊同一套定義。
 *
 * 每個測試先斷言新 REST 操作成功（200），再斷言統計欄位；不 seed 任何訊息。
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class ThreadUnreadSummaryConsistencyTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class ThreadUnreadSummaryConsistencyTest extends AssignmentWave2TestCase {

	/**
	 * 建立 Background（沿用「發送作業討論訊息」feature 的 Background）：沒有任何訊息，
	 * Alice 已加入課程 100，現在時間 2026-09-17 10:00:00
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_thread_fixture( 'post' );
	}

	/**
	 * 以管理員身分查詢作業繳交列表（第一波 API），取出指定繳交的那一筆
	 *
	 * @param int $submission_id 繳交 ID
	 * @return array<string, mixed>
	 */
	private function list_item( int $submission_id ): array {
		\wp_set_current_user( $this->fx['admin'] );

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions', [ 'assignment_id' => $this->fx['assignment'] ] );
		$this->assert_success_response( $response );

		foreach ( $this->get_response_items( $response ) as $item ) {
			if ( $submission_id === (int) ( $item['id'] ?? 0 ) ) {
				return $item;
			}
		}

		$this->fail( "繳交列表應包含繳交 {$submission_id}" );
	}

	/**
	 * 斷言列表中某繳交的討論串統計
	 *
	 * @param int         $submission_id 繳交 ID
	 * @param int         $unread_count 期望未讀數（學員發送且管理員尚未讀）
	 * @param int         $message_count 期望訊息總數
	 * @param string|null $last_message_at 期望最後訊息時間
	 * @param string      $label 失敗訊息前綴
	 */
	private function assert_list_summary( int $submission_id, int $unread_count, int $message_count, ?string $last_message_at, string $label ): void {
		$item = $this->list_item( $submission_id );

		$this->assertSame( $unread_count, $item['unread_count'] ?? null, "{$label}：unread_count 不符" );
		$this->assertSame( $message_count, $item['message_count'] ?? null, "{$label}：message_count 不符" );

		// last_message_at 可能合法地是 null，不能用 ?? 判斷欄位是否存在（?? 會把 null 當成不存在）
		$this->assertArrayHasKey( 'last_message_at', $item, "{$label}：回應應包含 last_message_at 欄位" );
		$this->assertSame( $last_message_at, $item['last_message_at'], "{$label}：last_message_at 不符" );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 學員發送訊息 → 列表 unread_count = 1、message_count = 1、last_message_at = 現在
	 */
	public function test_學員發送訊息後列表顯示1則未讀(): void {
		$this->assert_list_summary( $this->fx['sub_500'], 0, 0, null, '發送前' );

		$response = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '請問哪裡要補充' ] );
		$this->assert_success_response( $response );

		$this->assert_list_summary( $this->fx['sub_500'], 1, 1, '2026-09-17 10:00:00', '學員發送後' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 管理員讀取討論串 → 列表 unread_count 歸零（學員連發兩則 → 2 則未讀 → 管理員讀 → 0）
	 */
	public function test_管理員讀取後列表未讀數歸零(): void {
		$this->assert_success_response( $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '第一則問題' ] ) );
		$this->assert_success_response( $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '第二則問題' ] ) );
		$this->assert_list_summary( $this->fx['sub_500'], 2, 2, '2026-09-17 10:00:00', '管理員讀取前' );

		$response = $this->get_thread_as( 'teacher', $this->fx['sub_500'] );
		$this->assert_success_response( $response );

		$this->assert_list_summary( $this->fx['sub_500'], 0, 2, '2026-09-17 10:00:00', '管理員讀取後' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 管理員發送訊息 → message_count = 2、unread_count 仍 0（老師訊息不算管理端的未讀）
	 */
	public function test_管理員發送訊息後訊息數加一且未讀數仍為0(): void {
		$this->assert_success_response( $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '請問哪裡要補充' ] ) );
		$this->assert_success_response( $this->get_thread_as( 'teacher', $this->fx['sub_500'] ) );
		$this->assert_list_summary( $this->fx['sub_500'], 0, 1, '2026-09-17 10:00:00', '管理員讀取後、發送前' );

		$response = $this->post_as( 'teacher', $this->fx['sub_500'], [ 'body' => '請說明為什麼用 let' ] );
		$this->assert_success_response( $response );

		$this->assert_list_summary( $this->fx['sub_500'], 0, 2, '2026-09-17 10:00:00', '管理員發送後' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 學員讀取討論串 → 老師訊息 read_at = 現在；列表統計不受影響（老師訊息本來就不算 unread_count）
	 */
	public function test_學員讀取後老師訊息的read_at為現在(): void {
		$post = $this->post_as( 'teacher', $this->fx['sub_500'], [ 'body' => '請說明為什麼用 let' ] );
		$this->assert_success_response( $post );

		$teacher_message_id = $post->get_data()['id'] ?? null;
		$this->assertIsInt( $teacher_message_id, '前置：發送成功的回應應帶 int id' );
		$teacher_row = $this->get_message_row( $teacher_message_id );
		$this->assertIsArray( $teacher_row, '前置：老師訊息應已寫入' );
		$this->assertNull( $teacher_row['read_at'], '前置：老師訊息發送後尚未被讀' );

		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );
		$messages = $this->assert_thread_list_response( $response );

		$this->assertSame( [ $teacher_message_id ], $this->thread_ids( $messages ) );
		$this->assertSame( false, $messages[0]['mine'] );
		$this->assertSame( '2026-09-17 10:00:00', $this->get_message_row( $teacher_message_id )['read_at'] ?? null, '學員讀取後老師訊息的 read_at 應為現在（DB）' );
		$this->assertSame( '2026-09-17 10:00:00', $messages[0]['read_at'], '學員讀取後老師訊息的 read_at 應為現在（回應）' );

		$this->assert_list_summary( $this->fx['sub_500'], 0, 1, '2026-09-17 10:00:00', '學員讀取後' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 學員重新繳交（POST my/assignments/{id}/submit）後討論串仍在：
	 * 繳交是覆寫同一列（同一 submission id），訊息不被刪除、訊息數與 id 不變（第二波計劃 §9）
	 */
	public function test_學員重新繳交後討論串仍在(): void {
		$student_post = $this->post_as( 'student', $this->fx['sub_500'], [ 'body' => '學員的問題' ] );
		$this->assert_success_response( $student_post );
		$teacher_post = $this->post_as( 'teacher', $this->fx['sub_500'], [ 'body' => '老師的回覆' ] );
		$this->assert_success_response( $teacher_post );

		$student_message_id = $student_post->get_data()['id'] ?? null;
		$teacher_message_id = $teacher_post->get_data()['id'] ?? null;
		$this->assertIsInt( $student_message_id, '前置：學員訊息 id 為 int' );
		$this->assertIsInt( $teacher_message_id, '前置：老師訊息 id 為 int' );

		// 重新繳交：覆寫同一列、狀態重置為 pending、評語清空（第一波 DEC-11）
		\wp_set_current_user( $this->fx['alice'] );
		$resubmit = $this->dispatch(
			'POST',
			'/power-course/my/assignments/' . $this->fx['assignment'] . '/submit',
			[],
			[
				'text_body'      => 'let x = 2;',
				'attachment_ids' => [],
			]
		);
		$this->assert_success_response( $resubmit );
		$this->assertSame( $this->fx['sub_500'], $resubmit->get_data()['id'] ?? null, '重新繳交應覆寫同一列，繳交 id 不變' );
		$this->assert_submission_exists(
			$this->fx['sub_500'],
			[
				'text_body' => 'let x = 2;',
				'status'    => 'pending',
				'feedback'  => '',
			]
		);

		// 討論串仍在：兩則訊息 id 不變、都沒有被刪除
		$response = $this->get_thread_as( 'student', $this->fx['sub_500'] );
		$messages = $this->assert_thread_list_response( $response );
		$this->assertSame( [ $student_message_id, $teacher_message_id ], $this->thread_ids( $messages ), '重新繳交後討論串的訊息與 id 不變' );
		$this->assertSame( 2, $this->count_messages( $this->fx['sub_500'] ) );

		// 統計仍是兩則（學員那一則管理員尚未讀 → unread_count 1）
		$this->assert_list_summary( $this->fx['sub_500'], 1, 2, '2026-09-17 10:00:00', '重新繳交後' );
	}
}
