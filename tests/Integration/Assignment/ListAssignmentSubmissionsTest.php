<?php
/**
 * 查詢作業繳交列表 整合測試（Issue #131 第一波 P4）
 *
 * Feature: specs/features/assignment/查詢作業繳交列表.feature
 * 依據：計劃 §9 P4、§4 DEC-07/DEC-13/DEC-14
 *
 * ─── 回應形狀備忘（詳見回報「介面契約」段）─────────────────────────
 * P2/P3 的作業列表（QueryAssignmentsTest 等）回應是扁平陣列，本檔起繳交列表需要「總數」
 * （feature T9「查詢結果總數應為」），改用 {items, total} 包裝（對齊既有
 * AccessPass::/{id}/users 端點慣例），故不用 AssignmentTestCase::get_response_list() /
 * assert_response_list_ids()（那兩個是扁平結構），改用本次新增的 get_response_items() /
 * assert_response_item_ids() / assert_response_total()。
 *
 * DEC-07：訊息計數（unread_count / message_count / last_message_at）直接從
 * pc_assignment_messages 表計算，第一波表可能是空的，回應就是 0/0/null——本檔特別對
 * 「附帶附件大類」（attachments[].kind，test-creator 決定欄位名）與「討論串統計」各寫一條
 * Example 驗證真的計算了，不是寫死常數。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class ListAssignmentSubmissionsTest
 *
 * @group assignment
 * @group issue-131
 */
class ListAssignmentSubmissionsTest extends AssignmentTestCase {

	/** @var int 管理員 Admin */
	private int $admin_id;

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_100_id;

	/** @var int 課程 101（JS 入門課） */
	private int $course_101_id;

	/** @var int 作業 400（練習一：變數宣告，課程 100） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，課程 100） */
	private int $assignment_401_id;

	/** @var int 作業 402（已刪除練習，課程 100，垃圾桶） */
	private int $assignment_402_id;

	/** @var int 作業 420（JS 練習，課程 101） */
	private int $assignment_420_id;

	/** @var int 附件 700（Alice 上傳，image/png） */
	private int $attachment_700_id;

	/** @var int 繳交 500（作業 400，Alice，pending，附件 700） */
	private int $submission_500_id;

	/** @var int 繳交 501（作業 400，Bob，pass） */
	private int $submission_501_id;

	/** @var int 繳交 502（作業 401，Alice，fail） */
	private int $submission_502_id;

	/** @var int 繳交 503（作業 420，Alice，pending） */
	private int $submission_503_id;

	/** @var int 繳交 504（作業 402（垃圾桶），Bob，pending） */
	private int $submission_504_id;

	/**
	 * 建立 Background：Admin/Alice/Bob、課程 100/101、章節 201/300、
	 * 作業 400/401/402(trash)/420、附件 700、繳交 500/501/502/503/504
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_list_' . \uniqid(),
				'user_email' => 'admin_list_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_list_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_list_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_100_id = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->course_101_id = $this->create_course( [ 'post_title' => 'JS 入門課' ] );

		$chapter_201_id = $this->create_chapter( $this->course_100_id, [ 'post_title' => '1-1 變數' ] );
		$chapter_300_id = $this->create_chapter( $this->course_101_id, [ 'post_title' => 'JS 第一章' ] );

		$this->assignment_400_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習一：變數宣告', 'allow_text' => 'yes', 'allow_file' => 'yes', 'menu_order' => 0 ] );
		$this->assignment_401_id = $this->create_assignment( $chapter_201_id, [ 'title' => '練習二：上傳截圖', 'allow_text' => 'yes', 'allow_file' => 'yes', 'required_file' => 'yes', 'max_files' => 2, 'menu_order' => 10 ] );
		$this->assignment_402_id = $this->create_assignment( $chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 20, 'post_status' => 'trash' ] );
		$this->assignment_420_id = $this->create_assignment( $chapter_300_id, [ 'title' => 'JS 練習', 'menu_order' => 0 ] );

		$this->attachment_700_id = $this->seed_attachment(
			[
				'user_id'       => $this->alice_id,
				'original_name' => '作業截圖.png',
				'mime_type'     => 'image/png',
				'file_size'     => 20480,
			]
		);

		$this->submission_500_id = $this->seed_submission(
			[
				'assignment_id'  => $this->assignment_400_id,
				'user_id'        => $this->alice_id,
				'text_body'      => 'let x = 1;',
				'attachment_ids' => [ $this->attachment_700_id ],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 09:00:00',
			]
		);
		$this->submission_501_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'const y = 2;',
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 15:30:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-17 09:30:00',
			]
		);
		$this->submission_502_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->alice_id,
				'text_body'     => '如附件',
				'status'        => 'fail',
				'feedback'      => '請補充型別說明',
				'submitted_at'  => '2026-09-15 20:00:00',
				'graded_by'     => $this->admin_id,
				'graded_at'     => '2026-09-16 09:00:00',
			]
		);
		$this->submission_503_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_420_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let z = 3;',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 09:30:00',
			]
		);
		$this->submission_504_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_402_id,
				'user_id'       => $this->bob_id,
				'text_body'     => '舊作業',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-17 09:00:00',
			]
		);
	}

	/**
	 * 查詢作業繳交列表
	 *
	 * @param array<string, mixed> $params 查詢參數
	 * @return \WP_REST_Response
	 */
	private function list_submissions( array $params = [] ): \WP_REST_Response {
		\wp_set_current_user( $this->admin_id );

		return $this->dispatch( 'GET', '/power-course/assignment-submissions', $params );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 操作者必須為管理員（manage_options 或 manage_woocommerce）
	 * Example: 非管理員查詢繳交列表時操作失敗
	 */
	public function test_非管理員查詢繳交列表時操作失敗(): void {
		\wp_set_current_user( $this->alice_id );

		$response = $this->dispatch( 'GET', '/power-course/assignment-submissions' );

		$this->assert_permission_denied( $response );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（參數）- status 不是 all、pending、pass、fail 時視同 all
	 * Example: status 為非法值時回傳全部繳交
	 */
	public function test_status為非法值時回傳全部繳交(): void {
		$response = $this->list_submissions( [ 'status' => 'graded' ] );

		$this->assert_success_response( $response );
		$this->assert_response_item_ids(
			$response,
			[ $this->submission_503_id, $this->submission_501_id, $this->submission_500_id, $this->submission_502_id ]
		);
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_limit_offset
	 *
	 * Rule: 前置（參數）- limit 夾限在 1 至 200、預設 50；offset 小於 0 時視為 0
	 *
	 * @param int             $limit        limit 參數
	 * @param int             $offset       offset 參數
	 * @param array<int, int> $expected_ids 期望回傳的 id 順序（相對索引，於方法內轉換為實際 id）
	 */
	public function test_limit與offset夾限( int $limit, int $offset, array $expected_ids ): void {
		$all_ids_desc = [ $this->submission_503_id, $this->submission_501_id, $this->submission_500_id, $this->submission_502_id ];
		$expected     = \array_map( static fn( int $i ): int => $all_ids_desc[ $i ], $expected_ids );

		$response = $this->list_submissions( [ 'limit' => $limit, 'offset' => $offset ] );

		$this->assert_success_response( $response );
		$this->assert_response_item_ids( $response, $expected );
		$this->assert_response_total( $response, 4 );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: array<int, int>}>
	 */
	public static function provider_limit_offset(): array {
		return [
			'limit0_offset0'     => [ 0, 0, [ 0 ] ],
			'limit500_offset0'   => [ 500, 0, [ 0, 1, 2, 3 ] ],
			'limit50_offsetNeg5' => [ 50, -5, [ 0, 1, 2, 3 ] ],
		];
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 未提供篩選時依 submitted_at 由新到舊回傳全部繳交並附總數，不含垃圾桶中作業的繳交
	 * Example: 預設查詢
	 */
	public function test_預設查詢依新到舊排列不含垃圾桶(): void {
		$response = $this->list_submissions( [ 'status' => 'all' ] );

		$this->assert_success_response( $response );
		$this->assert_response_item_ids(
			$response,
			[ $this->submission_503_id, $this->submission_501_id, $this->submission_500_id, $this->submission_502_id ]
		);
		$this->assert_response_total( $response, 4 );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider provider_status篩選
	 *
	 * Rule: 後置（回應）- 依批改狀態篩選
	 *
	 * @param string          $status       status 參數
	 * @param array<int, int> $expected_ids 期望回傳的 id（0=500,1=501,2=502,3=503,4=504 相對索引）
	 */
	public function test_依批改狀態篩選( string $status, array $expected_ids ): void {
		$ids_by_index = [
			$this->submission_500_id,
			$this->submission_501_id,
			$this->submission_502_id,
			$this->submission_503_id,
			$this->submission_504_id,
		];
		$expected     = \array_map( static fn( int $i ): int => $ids_by_index[ $i ], $expected_ids );

		$response = $this->list_submissions( [ 'status' => $status ] );

		$this->assert_success_response( $response );
		$this->assert_response_item_ids( $response, $expected );
	}

	/**
	 * @return array<string, array{0: string, 1: array<int, int>}>
	 */
	public static function provider_status篩選(): array {
		return [
			'pending' => [ 'pending', [ 3, 0 ] ],
			'pass'    => [ 'pass', [ 1 ] ],
			'fail'    => [ 'fail', [ 2 ] ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider provider_courseId與assignmentId交集
	 *
	 * Rule: 後置（回應）- course_id 與 assignment_id 同時提供時取交集，作業不屬於該課程時回傳空列表
	 *
	 * @param string          $course_key     'course_100' | 'course_101' | ''
	 * @param string          $assignment_key 'assignment_400' | 'assignment_420' | 'assignment_402' | ''
	 * @param array<int, int> $expected_ids   期望回傳的 id（相對索引，0=500,1=501,2=502,3=503）
	 */
	public function test_courseId與assignmentId同時提供時取交集( string $course_key, string $assignment_key, array $expected_ids ): void {
		$ids_by_index = [
			$this->submission_500_id,
			$this->submission_501_id,
			$this->submission_502_id,
			$this->submission_503_id,
		];
		$expected     = \array_map( static fn( int $i ): int => $ids_by_index[ $i ], $expected_ids );

		$params = [];
		if ( '' !== $course_key ) {
			$params['course_id'] = 'course_100' === $course_key ? $this->course_100_id : $this->course_101_id;
		}
		if ( '' !== $assignment_key ) {
			$params['assignment_id'] = match ( $assignment_key ) {
				'assignment_400' => $this->assignment_400_id,
				'assignment_420' => $this->assignment_420_id,
				'assignment_402' => $this->assignment_402_id,
			};
		}

		$response = $this->list_submissions( $params );

		$this->assert_success_response( $response );
		$this->assert_response_item_ids( $response, $expected );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<int, int>}>
	 */
	public static function provider_courseId與assignmentId交集(): array {
		return [
			'course100_only'          => [ 'course_100', '', [ 1, 0, 2 ] ],
			'course100_assignment400' => [ 'course_100', 'assignment_400', [ 1, 0 ] ],
			'course101_assignment400' => [ 'course_101', 'assignment_400', [] ],
			'assignment420_only'      => [ '', 'assignment_420', [ 3 ] ],
			'assignment402_only'      => [ '', 'assignment_402', [] ],
		];
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 每筆繳交附帶學員、作業、課程摘要與附件（含附件大類）
	 * Example: 查詢作業 400 的繳交摘要
	 */
	public function test_每筆繳交附帶學員作業課程與附件摘要(): void {
		$response = $this->list_submissions( [ 'assignment_id' => $this->assignment_400_id ] );

		$this->assert_success_response( $response );
		$items = $this->get_response_items( $response );
		$this->assertCount( 2, $items );

		$this->assertSame( $this->submission_501_id, (int) $items[0]['id'] );
		$this->assertSame( 'Bob', $items[0]['customer_name'] ?? null );
		$this->assertSame( 'bob@test.com', $items[0]['customer_email'] ?? null );
		$this->assertSame( '練習一：變數宣告', $items[0]['assignment_title'] ?? null );
		$this->assertSame( 'PHP 基礎課', $items[0]['course_title'] ?? null );
		$this->assertSame( 'pass', $items[0]['status'] ?? null );
		$this->assertSame( '寫得很好', $items[0]['feedback'] ?? null );
		$this->assertSame( [], $items[0]['attachments'] ?? null );

		$this->assertSame( $this->submission_500_id, (int) $items[1]['id'] );
		$this->assertSame( 'Alice', $items[1]['customer_name'] ?? null );
		$this->assertSame( 'alice@test.com', $items[1]['customer_email'] ?? null );
		$this->assertSame( 'pending', $items[1]['status'] ?? null );
		$this->assertSame(
			[ [ 'attachment_id' => $this->attachment_700_id, 'file_name' => '作業截圖.png', 'kind' => 'image' ] ],
			$items[1]['attachments'] ?? null
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 每筆繳交附帶討論串未讀數（學員發送且管理員未讀）、訊息總數與最後訊息時間
	 * Example: 繳交 500 有 3 則訊息其中 1 則未讀
	 */
	public function test_繳交500有3則訊息其中1則未讀(): void {
		$this->seed_message(
			[
				'submission_id' => $this->submission_500_id,
				'sender_type'   => 'student',
				'sender_id'     => $this->alice_id,
				'body'          => '老師請問型別',
				'created_at'    => '2026-09-16 09:10:00',
			]
		);
		$this->seed_message(
			[
				'submission_id' => $this->submission_500_id,
				'sender_type'   => 'teacher',
				'sender_id'     => $this->admin_id,
				'body'          => '參考 1-1 的範例',
				'read_at'       => '2026-09-16 10:05:00',
				'created_at'    => '2026-09-16 10:00:00',
			]
		);
		$this->seed_message(
			[
				'submission_id' => $this->submission_500_id,
				'sender_type'   => 'student',
				'sender_id'     => $this->alice_id,
				'body'          => '了解，謝謝',
				'read_at'       => '2026-09-16 10:30:00',
				'created_at'    => '2026-09-16 10:20:00',
			]
		);

		$response = $this->list_submissions( [ 'assignment_id' => $this->assignment_400_id ] );

		$this->assert_success_response( $response );
		$items = $this->get_response_items( $response );

		$this->assertSame( $this->submission_501_id, (int) $items[0]['id'] );
		$this->assertSame( 0, $items[0]['unread_count'] ?? null );
		$this->assertSame( 0, $items[0]['message_count'] ?? null );
		$this->assertArrayHasKey( 'last_message_at', $items[0], '回應應包含 last_message_at 欄位' );
		$this->assertNull( $items[0]['last_message_at'], '無訊息時 last_message_at 應為 null' );

		$this->assertSame( $this->submission_500_id, (int) $items[1]['id'] );
		$this->assertSame( 1, $items[1]['unread_count'] ?? null );
		$this->assertSame( 3, $items[1]['message_count'] ?? null );
		$this->assertSame( '2026-09-16 10:20:00', $items[1]['last_message_at'] ?? null );
	}
}
