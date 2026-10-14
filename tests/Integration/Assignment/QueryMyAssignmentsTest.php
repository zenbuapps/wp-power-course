<?php
/**
 * 查詢學員作業 整合測試（Issue #131 第一波 P3）
 *
 * Feature: specs/features/assignment/查詢學員作業.feature
 * 依據：計劃 §9 P3、§4 DEC-01/DEC-12、specs/api/api.yml（GET /my/assignments、GET /my/assignments/{id}）
 *
 * ─── 回應形狀備忘 ───────────────────────────────────────────
 * api.yml MyAssignmentItem 是巢狀物件：{ assignment: {...Assignment}, my_submission: {...}|null }，
 * 不是 QueryAssignmentsTest（管理端）那種扁平陣列，所以本檔不沿用 AssignmentTestCase 既有的
 * assert_response_list_ids() / assert_response_includes()（那兩個是扁平結構），改在本檔自行
 * 解析巢狀欄位，不修改共用基底既有方法的簽名與行為。
 * error code：與 SubmitAssignmentTest 相同的「尚未報名此課程」，依 api.yml 該端點（submit）
 * 內嵌範例與既有 Chapter Api 鎖定訊息寫法一致採 code = '403'（字面字串），三個學員端端點
 * （list / get / submit）共用同一句訊息與同一個 code（DEC-12）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

use J7\PowerCourse\Resources\AccessPass\Core\CPT as AccessPassCPT;
use J7\PowerCourse\Resources\AccessPass\Service\Repository as AccessPassRepository;

/**
 * Class QueryMyAssignmentsTest
 *
 * @group assignment
 * @group issue-131
 */
class QueryMyAssignmentsTest extends AssignmentTestCase {

	/** @var int Alice（已加入課程 100） */
	private int $alice_id;

	/** @var int Bob（未加入課程） */
	private int $bob_id;

	/** @var int 課程 100（PHP 基礎課） */
	private int $course_id;

	/** @var int 章節 200（第一章） */
	private int $chapter_200_id;

	/** @var int 章節 201（1-1 變數，巢狀在 200 之下） */
	private int $chapter_201_id;

	/** @var int 作業 400（練習一：變數宣告，章節 201，menu_order 0） */
	private int $assignment_400_id;

	/** @var int 作業 401（練習二：上傳截圖，章節 201，menu_order 10） */
	private int $assignment_401_id;

	/** @var int 作業 402（已刪除練習，章節 201，trash） */
	private int $assignment_402_id;

	/** @var int 作業 410（第一章總複習，章節 200） */
	private int $assignment_410_id;

	/** @var int 繳交 500（Alice 對作業 400，pass） */
	private int $submission_500_id;

	/**
	 * 建立 Background：Alice/Bob、課程 100、章節 200/201（巢狀）、作業 400/401/402/410、
	 * Alice 已加入課程 100（expire_date 0）、Alice 對作業 400 的一筆 pass 繳交
	 */
	public function set_up(): void {
		parent::set_up();

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_qma_' . \uniqid(),
				'display_name' => 'Alice',
				'user_email' => 'alice_qma_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->bob_id   = $this->factory()->user->create(
			[
				'user_login' => 'bob_qma_' . \uniqid(),
				'display_name' => 'Bob',
				'user_email' => 'bob_qma_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id     = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_200_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
		$this->chapter_201_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title'  => '1-1 變數',
				'post_parent' => $this->chapter_200_id,
			]
		);

		$this->assignment_400_id = $this->create_assignment( $this->chapter_201_id, [ 'title' => '練習一：變數宣告', 'menu_order' => 0 ] );
		$this->assignment_401_id = $this->create_assignment(
			$this->chapter_201_id,
			[
				'title'         => '練習二：上傳截圖',
				'allow_file'    => 'yes',
				'required_file' => 'yes',
				'max_files'     => 2,
				'due_at'        => 1789055940,
				'menu_order'    => 10,
			]
		);
		$this->assignment_402_id = $this->create_assignment( $this->chapter_201_id, [ 'title' => '已刪除練習', 'menu_order' => 20, 'post_status' => 'trash' ] );
		$this->assignment_410_id = $this->create_assignment( $this->chapter_200_id, [ 'title' => '第一章總複習', 'menu_order' => 0 ] );

		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );

		$this->submission_500_id = $this->seed_submission(
			[
				'assignment_id' => $this->assignment_400_id,
				'user_id'       => $this->alice_id,
				'text_body'     => 'let x = 1;',
				'status'        => 'pass',
				'feedback'      => '寫得很好',
				'submitted_at'  => '2026-09-16 09:00:00',
				'graded_by'     => 1,
				'graded_at'     => '2026-09-16 10:00:00',
			]
		);
	}

	/**
	 * 以指定使用者身分查詢作業列表
	 *
	 * @param int                  $user_id 使用者 ID（0 = 未登入）
	 * @param array<string, mixed> $params 查詢參數
	 * @return \WP_REST_Response
	 */
	private function query_list_as( int $user_id, array $params ): \WP_REST_Response {
		\wp_set_current_user( $user_id );

		return $this->dispatch( 'GET', '/power-course/my/assignments', $params );
	}

	/**
	 * 以指定使用者身分查詢單一作業
	 *
	 * @param int $user_id 使用者 ID（0 = 未登入）
	 * @param int $assignment_id 作業 ID
	 * @return \WP_REST_Response
	 */
	private function query_single_as( int $user_id, int $assignment_id ): \WP_REST_Response {
		\wp_set_current_user( $user_id );

		return $this->dispatch( 'GET', '/power-course/my/assignments/' . $assignment_id );
	}

	/**
	 * 斷言「尚未報名此課程」403（DEC-12；code 見檔案頂端備忘）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	private function assert_not_enrolled( \WP_REST_Response $response ): void {
		$this->assert_operation_failed_response( $response, '尚未報名此課程', 403, '403' );
	}

	/**
	 * 取出回應列表中每筆的 assignment.id，依序回傳
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @return array<int, int>
	 */
	private function get_my_assignment_id_order( \WP_REST_Response $response ): array {
		$list = $this->get_response_list( $response );

		return \array_map(
			static fn( array $item ): int => (int) ( $item['assignment']['id'] ?? 0 ),
			$list
		);
	}

	/**
	 * 依 assignment_id 從回應列表取出單一項目（{assignment, my_submission}）
	 *
	 * @param \WP_REST_Response $response REST 回應
	 * @param int                $assignment_id 作業 ID
	 * @return array<string, mixed>|null
	 */
	private function find_my_assignment_item( \WP_REST_Response $response, int $assignment_id ): ?array {
		$list = $this->get_response_list( $response );

		foreach ( $list as $item ) {
			if ( (int) ( $item['assignment']['id'] ?? 0 ) === $assignment_id ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * 建立測試用權限包（沿用既有 AccessPass 模組，非本 PR 範圍，僅供 Given 資料建立）
	 *
	 * @param array<string, mixed> $args scope_type, limit_type
	 * @return int 權限包 ID
	 */
	private function create_access_pass( array $args ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => AccessPassCPT::POST_TYPE,
				'post_title'  => $args['name'] ?? '測試權限包',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', $args['scope_type'] ?? 'all' );
		\update_post_meta( $pass_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', $args['status'] ?? 'active' );

		return $pass_id;
	}

	// ========== 前置（狀態）- 使用者必須已登入 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 使用者必須已登入
	 * Example: 未登入訪客查詢作業時操作失敗
	 */
	public function test_未登入訪客查詢作業時操作失敗(): void {
		$response = $this->query_list_as( 0, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_not_logged_in( $response );
	}

	// ========== 前置（狀態）- 有效觀看權 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權
	 * Example: 未加入課程的學員查詢時操作失敗
	 */
	public function test_未加入課程的學員查詢時操作失敗(): void {
		$response = $this->query_list_as( $this->bob_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_not_enrolled( $response );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權
	 * Example: 課程觀看權已到期時操作失敗
	 */
	public function test_課程觀看權已到期時操作失敗(): void {
		// R-06：is_expired() 讀的是 time() 不是 Clock，用相對時間確保測試不隨系統時鐘漂移
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, \time() - \DAY_IN_SECONDS );

		$response = $this->query_list_as( $this->alice_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_not_enrolled( $response );
	}

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（狀態）- 學員對作業所屬課程必須有有效觀看權
	 * Example: 課程尚未開課時操作失敗
	 */
	public function test_課程尚未開課時操作失敗(): void {
		// R-06：is_course_ready() 讀的是 time() 不是 Clock，用相對時間確保測試不隨系統時鐘漂移
		\update_post_meta( $this->course_id, 'course_schedule', \time() + \DAY_IN_SECONDS );

		$response = $this->query_list_as( $this->alice_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_not_enrolled( $response );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 前置（狀態）- 持有涵蓋此課程的有效權限包亦視為有觀看權
	 * Example: 持有全站課程權限包的學員可查詢
	 */
	public function test_持有全站課程權限包的學員可查詢(): void {
		$pass_id = $this->create_access_pass( [ 'scope_type' => 'all', 'limit_type' => 'unlimited' ] );
		AccessPassRepository::insert_or_update( $this->bob_id, $pass_id, null, null );

		$response = $this->query_list_as( $this->bob_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_success_response( $response );
	}

	// ========== 前置（狀態）- 查詢單一作業不洩漏存在性 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 前置（狀態）- 查詢單一作業時，作業不存在或在垃圾桶一律回應「尚未報名此課程」，不洩漏作業是否存在
	 * Example: 查詢不存在的作業時回應尚未報名此課程
	 */
	public function test_查詢不存在的作業時回應尚未報名此課程(): void {
		$response = $this->query_single_as( $this->alice_id, 9999 );

		$this->assert_not_enrolled( $response );
	}

	// ========== 前置（參數）- chapter_id 或 course_id 至少需指定一個 ==========

	/**
	 * @test
	 * @group error
	 *
	 * Rule: 前置（參數）- 查詢列表時 chapter_id 與 course_id 至少需指定一個
	 * Example: 未指定章節或課程時操作失敗
	 */
	public function test_未指定章節或課程時操作失敗(): void {
		$response = $this->query_list_as( $this->alice_id, [] );

		$this->assert_operation_failed_response( $response, 'chapter_id 或 course_id 至少需指定一個', 400, 'rest_invalid_param' );
	}

	// ========== 後置（回應）- 以 chapter_id 查詢 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以 chapter_id 查詢時回傳該章節作業（依 menu_order 排序、不含垃圾桶中的作業）
	 *       與學員自己的繳交
	 * Example: Alice 查詢章節 201 的作業
	 */
	public function test_Alice查詢章節201的作業(): void {
		$response = $this->query_list_as( $this->alice_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_success_response( $response );
		$this->assertSame(
			[ $this->assignment_400_id, $this->assignment_401_id ],
			$this->get_my_assignment_id_order( $response ),
			'應依 menu_order 排序，且不含垃圾桶中的作業 402'
		);

		$item_400 = $this->find_my_assignment_item( $response, $this->assignment_400_id );
		$this->assertSame( 0, (int) ( $item_400['assignment']['due_at'] ?? -1 ), '作業 400 的 due_at 應為 0' );
		$this->assertSame( 'pass', $item_400['my_submission']['status'] ?? null, '作業 400 的 my_submission.status 應為 pass' );
		$this->assertSame( '寫得很好', $item_400['my_submission']['feedback'] ?? null, '作業 400 的 my_submission.feedback 不符' );

		$item_401 = $this->find_my_assignment_item( $response, $this->assignment_401_id );
		$this->assertArrayHasKey( 'my_submission', (array) $item_401, '回應應含 my_submission 欄位' );
		$this->assertNull( $item_401['my_submission'], '作業 401 尚未繳交，my_submission 應為 null' );
	}

	// ========== 後置（回應）- 以 course_id 查詢 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 以 course_id 查詢時回傳整門課程作業，先依章節攤平順序再依 menu_order 排序
	 * Example: Alice 查詢課程 100 的作業總覽
	 */
	public function test_Alice查詢課程100的作業總覽(): void {
		$response = $this->query_list_as( $this->alice_id, [ 'course_id' => $this->course_id ] );

		$this->assert_success_response( $response );
		$this->assertSame(
			[ $this->assignment_410_id, $this->assignment_400_id, $this->assignment_401_id ],
			$this->get_my_assignment_id_order( $response )
		);
	}

	// ========== 後置（回應）- 只回傳學員自己的繳交 ==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule: 後置（回應）- 只回傳學員自己的繳交，不包含其他學員的繳交
	 * Example: Bob 已繳交練習二時 Alice 查詢結果仍顯示未繳交
	 */
	public function test_Bob已繳交練習二時Alice查詢結果仍顯示未繳交(): void {
		$this->enroll_user_to_course( $this->bob_id, $this->course_id, 0 );
		$this->seed_submission(
			[
				'assignment_id' => $this->assignment_401_id,
				'user_id'       => $this->bob_id,
				'text_body'     => 'Bob 的答案',
				'status'        => 'pending',
				'submitted_at'  => '2026-09-16 11:00:00',
			]
		);

		$response = $this->query_list_as( $this->alice_id, [ 'chapter_id' => $this->chapter_201_id ] );

		$this->assert_success_response( $response );
		$item_400 = $this->find_my_assignment_item( $response, $this->assignment_400_id );
		$this->assertSame( 'pass', $item_400['my_submission']['status'] ?? null );

		$item_401 = $this->find_my_assignment_item( $response, $this->assignment_401_id );
		$this->assertArrayHasKey( 'my_submission', (array) $item_401, '回應應含 my_submission 欄位' );
		$this->assertNull( $item_401['my_submission'], 'Alice 不應看到 Bob 的繳交' );
	}

	// ========== 後置（回應）- 查詢單一作業 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule: 後置（回應）- 查詢單一作業時回傳作業設定與學員自己的繳交，繳交附件只含 attachment_id 與 file_name
	 * Example: Alice 查詢已繳交附件的作業 401
	 */
	public function test_Alice查詢已繳交附件的作業401(): void {
		$attachment_id = $this->seed_attachment( [ 'user_id' => $this->alice_id, 'original_name' => '作業截圖.png', 'mime_type' => 'image/png', 'file_size' => 20480 ] );
		$submission_id = $this->seed_submission(
			[
				'assignment_id'  => $this->assignment_401_id,
				'user_id'        => $this->alice_id,
				'text_body'      => '如附件',
				'attachment_ids' => [ $attachment_id ],
				'status'         => 'pending',
				'submitted_at'   => '2026-09-16 12:00:00',
			]
		);

		$response = $this->query_single_as( $this->alice_id, $this->assignment_401_id );

		$this->assert_success_response( $response );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		$this->assertSame( $this->assignment_401_id, (int) ( $data['assignment']['id'] ?? 0 ) );
		$this->assertSame( 2, (int) ( $data['assignment']['max_files'] ?? 0 ) );
		$this->assertSame( $submission_id, (int) ( $data['my_submission']['id'] ?? 0 ) );
		$this->assertSame( 'pending', $data['my_submission']['status'] ?? null );

		$attachments = $data['my_submission']['attachments'] ?? [];
		$this->assertIsArray( $attachments );
		$this->assertCount( 1, $attachments );
		$this->assertSame( $attachment_id, (int) ( $attachments[0]['attachment_id'] ?? 0 ) );
		$this->assertSame( '作業截圖.png', $attachments[0]['file_name'] ?? null );
	}
}
