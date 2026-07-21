<?php
/**
 * 排程 / 私密課程於後台課程列表可見性 整合測試（Red 階段）
 *
 * Feature: specs/features/course/查詢課程列表.feature
 * Plan:    specs/plan/issue-256-scheduled-course-visible/implementation-plan.md
 * Issue:   #256
 *
 * 背景：後台課程列表查詢 status 目前硬編碼 ['publish', 'draft']，導致「排程發佈
 * （post_status = future）」與「私密（private）」課程從後台管理列表消失，且回應
 * 沒有「預計上架時間」欄位（date_publish）。本計劃要將這兩處 status 預設集合擴充為
 * ['publish', 'draft', 'future', 'private']（REST 與 MCP 共用查詢層一致），並新增
 * date_publish 欄位。
 *
 * Red 判定：
 * - T1 / T2 / T3 / T6 / T7：目前生產碼會失敗（status 預設集合缺 future/private；
 *   format_course_base_records 尚無 date_publish 欄位）。
 * - T4 / T5（明確帶 status 參數篩選）：wc_get_products 對「呼叫方明確傳入」的 status
 *   本就不受預設集合限制，理論上目前程式碼也可能通過；保留是為了完整覆蓋 Feature
 *   Examples（Rule: 支援依 status 篩選），並非本檔案 Red 判定的必要條件。
 *
 * 涵蓋兩條獨立路徑（Q6=A 要求人工後台與 AI 視角一致）：
 * - REST：J7\PowerCourse\Api\Course::get_courses_callback()（GET /power-course/courses）
 * - MCP 共用查詢層：J7\PowerCourse\Resources\Course\Service\Query::list()
 *
 * Background（對齊 feature，5 門課程）：
 *   | courseId | name         | status  | publish_date        |
 *   | 100      | PHP 基礎課   | publish |                     |
 *   | 101      | React 實戰課 | publish |                     |
 *   | 102      | Vue 入門     | draft   |                     |
 *   | 103      | AI 繪圖入門  | future  | 2025-08-01 09:00:00 |
 *   | 104      | 內部教育訓練 | private |                     |
 *
 * 測試指令：
 *   composer run test
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course \
 *     -- vendor/bin/phpunit --group course
 *
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\Service\Query as CourseQuery;

/**
 * Class CourseListScheduledStatusTest
 * 測試後台課程列表查詢（REST + MCP 共用層）對排程 / 私密課程的可見性與 date_publish 欄位。
 */
class CourseListScheduledStatusTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int 課程 100：PHP 基礎課（publish） */
	private int $course_publish_1_id;

	/** @var int 課程 101：React 實戰課（publish） */
	private int $course_publish_2_id;

	/** @var int 課程 102：Vue 入門（draft） */
	private int $course_draft_id;

	/** @var int 課程 103：AI 繪圖入門（future，publish_date=2025-08-01 09:00:00） */
	private int $course_future_id;

	/** @var int 課程 104：內部教育訓練（private） */
	private int $course_private_id;

	/** 初始化依賴（本測試直接呼叫 REST callback 與 Query 靜態方法，無需額外初始化） */
	protected function configure_dependencies(): void {}

	/**
	 * 每個測試前建立 Background fixture（5 門課程）
	 */
	public function set_up(): void {
		parent::set_up();

		// 暖機 REST server 並清空 _doing_it_wrong 快取，避免 MCP abilities 註冊的既有通知
		// （與本測試無關，源自 WP 6.9 Abilities API / mcp-adapter 的 category slug 問題）
		// 因測試執行順序不同而間歇性判定失敗（同 CoursesShortcodePageApiTest 手法）。
		\rest_get_server();
		$this->caught_doing_it_wrong = [];

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_i256_' . uniqid(),
				'user_email' => 'admin_i256_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );
		$this->ids['Admin'] = $this->admin_id;

		$this->course_publish_1_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
				'price'      => 1200,
			]
		);

		$this->course_publish_2_id = $this->create_course(
			[
				'post_title' => 'React 實戰課',
				'_is_course' => 'yes',
				'price'      => 2000,
			]
		);

		$this->course_draft_id = $this->create_course(
			[
				'post_title'  => 'Vue 入門',
				'post_status' => 'draft',
				'_is_course'  => 'yes',
				'price'       => 800,
			]
		);

		$this->course_future_id = $this->create_scheduled_course(
			[
				'post_title' => 'AI 繪圖入門',
				'_is_course' => 'yes',
				'price'      => 2500,
			],
			'2025-08-01 09:00:00'
		);

		$this->course_private_id = $this->create_course(
			[
				'post_title'  => '內部教育訓練',
				'post_status' => 'private',
				'_is_course'  => 'yes',
				'price'       => 3000,
			]
		);
	}

	// ========== Helpers ==========

	/**
	 * 建立「排程發佈」課程。
	 *
	 * 繞過 wp_insert_post() / wp_update_post() 內建的「post_date_gmt 若不夠未來（與測試
	 * 執行當下的真實系統時間比較）就自動降級為 publish」轉態邏輯——這條邏輯比對的是測試
	 * 實際執行時的系統時鐘，與 Issue #256 的業務語意無關，若不繞過會讓 fixture 的
	 * post_status 在建立瞬間就被 WP 核心改寫成 publish，測試前提就不成立。
	 * 因此直接寫入 wp_posts 資料表，確保 status 精準為 future、post_date 精準等於指定值
	 * （供 T6 驗證 date_publish＝預計上架時間）。
	 *
	 * @param array<string, mixed> $args      課程參數（同 TestCase::create_course()）。
	 * @param string               $post_date 排程發佈時間（Y-m-d H:i:s）；比照本測試環境
	 *                                        預設 UTC 時區，post_date_gmt 採相同字串。
	 * @return int 課程（商品）ID
	 */
	private function create_scheduled_course( array $args, string $post_date ): int {
		$course_id = $this->create_course(
			array_merge( $args, [ 'post_status' => 'publish' ] )
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_status'   => 'future',
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date,
			],
			[ 'ID' => $course_id ]
		);
		\clean_post_cache( $course_id );

		// 自我驗證資料前提：若這裡就不是 future，後面所有斷言都毫無意義。
		$this->assertSame(
			'future',
			\get_post_status( $course_id ),
			'測試前提失敗：排程課程 post_status 必須為 future'
		);

		return $course_id;
	}

	/**
	 * 呼叫 REST GET /power-course/courses，回傳整理過的 status / data / 分頁 header。
	 *
	 * @param array<string, mixed> $params Query 參數。
	 * @return array{status:int, data:mixed, total:int, total_pages:int}
	 */
	private function call_courses_api( array $params = [] ): array {
		$request = new \WP_REST_Request( 'GET', '/power-course/courses' );
		// 明確設定 query params，確保 $request->get_query_params() 能取到。
		$request->set_query_params( $params );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = \rest_do_request( $request );
		$headers  = $response->get_headers();

		return [
			'status'      => (int) $response->get_status(),
			'data'        => $response->get_data(),
			'total'       => (int) ( $headers['X-WP-Total'] ?? 0 ),
			'total_pages' => (int) ( $headers['X-WP-TotalPages'] ?? 0 ),
		];
	}

	/**
	 * 從回應（array of formatted course records）中依課程名稱找出對應項目。
	 *
	 * @param mixed  $data 回應資料。
	 * @param string $name 課程名稱（對應 format_course_base_records 的 'name' 欄位）。
	 * @return array<string, mixed>|null
	 */
	private function find_course_by_name( $data, string $name ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( $data as $row ) {
			if ( is_array( $row ) && ( $row['name'] ?? null ) === $name ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * 判斷回應中是否包含指定名稱的課程。
	 *
	 * @param mixed  $data 回應資料。
	 * @param string $name 課程名稱。
	 */
	private function response_contains_course( $data, string $name ): bool {
		return null !== $this->find_course_by_name( $data, $name );
	}

	// ========== REST 路徑：Rule 後置（回應）- 後台預設回傳四態課程 ==========

	/**
	 * @test
	 * @group happy
	 * T1（REST）：預設查詢（無 status 參數）應包含排程中課程「AI 繪圖入門」。
	 * 對應 Example：排程中的課程不會從後台列表消失
	 */
	public function test_REST_預設查詢應包含排程中課程(): void {
		$result = $this->call_courses_api(
			[
				'posts_per_page' => 10,
				'paged'          => 1,
			]
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue(
			$this->response_contains_course( $result['data'], 'AI 繪圖入門' ),
			'預設查詢應包含排程中課程「AI 繪圖入門」，但目前生產碼 status 硬編碼 [publish,draft] 導致排程課程消失'
		);
	}

	/**
	 * @test
	 * @group happy
	 * T2（REST）：預設查詢應包含私密課程「內部教育訓練」。
	 * 對應 Example：私密課程出現在後台列表
	 */
	public function test_REST_預設查詢應包含私密課程(): void {
		$result = $this->call_courses_api(
			[
				'posts_per_page' => 10,
				'paged'          => 1,
			]
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertTrue(
			$this->response_contains_course( $result['data'], '內部教育訓練' ),
			'預設查詢應包含私密課程「內部教育訓練」，但目前生產碼 status 硬編碼 [publish,draft] 導致私密課程消失'
		);
	}

	/**
	 * @test
	 * @group happy
	 * T3（REST）：預設查詢總數應為 5（publish×2 + draft×1 + future×1 + private×1）。
	 * 對應 Example：預設查詢回傳所有非垃圾桶狀態的課程
	 */
	public function test_REST_預設查詢總數應為5(): void {
		$result = $this->call_courses_api(
			[
				'posts_per_page' => 10,
				'paged'          => 1,
			]
		);

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( 5, $result['total'], 'X-WP-Total 應為 5' );
		$this->assertSame( 1, $result['total_pages'], 'X-WP-TotalPages 應為 1' );
		$this->assertCount( 5, (array) $result['data'], '回應課程數量應為 5' );
	}

	// ========== REST 路徑：Rule 後置（回應）- 依 status 篩選 ==========

	/**
	 * @test
	 * @group happy
	 * T4（REST）：status=future 篩選應只回 1 筆，且為「AI 繪圖入門」。
	 * 對應 Example：篩選排程中課程以盤點即將上架的內容
	 */
	public function test_REST_篩選status為future應只回1筆(): void {
		$result = $this->call_courses_api( [ 'status' => 'future' ] );

		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 1, (array) $result['data'], 'status=future 應只回 1 筆' );
		$this->assertTrue( $this->response_contains_course( $result['data'], 'AI 繪圖入門' ) );
	}

	/**
	 * @test
	 * @group happy
	 * T5（REST）：status=private 篩選應只回 1 筆，且為「內部教育訓練」。
	 * 對應 Example：篩選私密課程
	 */
	public function test_REST_篩選status為private應只回1筆(): void {
		$result = $this->call_courses_api( [ 'status' => 'private' ] );

		$this->assertSame( 200, $result['status'] );
		$this->assertCount( 1, (array) $result['data'], 'status=private 應只回 1 筆' );
		$this->assertTrue( $this->response_contains_course( $result['data'], '內部教育訓練' ) );
	}

	// ========== REST 路徑：Rule 後置（回應）- 排程課程應帶預計上架時間 ==========

	/**
	 * @test
	 * @group happy
	 * T6（REST）：排程課程回應 status 應為 'future'，且 date_publish 應為排程 post_date
	 * '2025-08-01 09:00:00'（預計上架時間）。
	 * 對應 Example：排程課程回應包含預計上架的發佈時間
	 */
	public function test_REST_排程課程應帶預計上架時間(): void {
		$result = $this->call_courses_api( [ 'status' => 'future' ] );

		$this->assertSame( 200, $result['status'] );
		$course = $this->find_course_by_name( $result['data'], 'AI 繪圖入門' );
		$this->assertNotNull( $course, '應能在 status=future 篩選結果中找到「AI 繪圖入門」課程' );
		$this->assertSame( 'future', $course['status'] ?? null, 'status 應為 future' );
		$this->assertArrayHasKey(
			'date_publish',
			$course,
			'回應應含 date_publish 欄位（生產碼目前尚未新增此欄位）'
		);
		$this->assertSame(
			'2025-08-01 09:00:00',
			$course['date_publish'] ?? null,
			'date_publish 應為排程 post_date（預計上架時間）'
		);
	}

	/**
	 * @test
	 * @group edge
	 * T7：非 future 課程（publish）的 date_publish 應為 null。
	 * 對應 Rule：排程課程回應中應帶有預計上架時間（其餘狀態無此語意，應為 null）。
	 */
	public function test_REST_非future課程date_publish應為null(): void {
		$result = $this->call_courses_api( [ 'status' => 'publish' ] );

		$this->assertSame( 200, $result['status'] );
		$course = $this->find_course_by_name( $result['data'], 'PHP 基礎課' );
		$this->assertNotNull( $course, '應能在 status=publish 篩選結果中找到「PHP 基礎課」課程' );
		$this->assertArrayHasKey( 'date_publish', $course, '回應應含 date_publish 欄位' );
		$this->assertNull(
			$course['date_publish'] ?? 'NOT_NULL_SENTINEL',
			'非 future 狀態課程的 date_publish 應為 null'
		);
	}

	// ========== MCP 共用查詢層：Query::list()（Q6=A 要求與 REST 一致） ==========

	/**
	 * @test
	 * @group happy
	 * MCP 版 T1 + T2：Query::list([]) 預設查詢的 items 應同時含排程課程與私密課程。
	 * 對應 REST 版 test_REST_預設查詢應包含排程中課程 / test_REST_預設查詢應包含私密課程，
	 * 驗證 MCP 課程列表工具與後台人工視角一致（Q6=A）。
	 */
	public function test_MCP_Query_list預設查詢應含排程與私密課程(): void {
		$result = CourseQuery::list( [] );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertTrue(
			$this->response_contains_course( $result['items'], 'AI 繪圖入門' ),
			'Query::list() 預設查詢應含排程課程「AI 繪圖入門」，MCP 視角應與後台 REST 一致'
		);
		$this->assertTrue(
			$this->response_contains_course( $result['items'], '內部教育訓練' ),
			'Query::list() 預設查詢應含私密課程「內部教育訓練」，MCP 視角應與後台 REST 一致'
		);
	}

	/**
	 * @test
	 * @group happy
	 * MCP 版 T3：Query::list([]) 預設查詢 total 應為 5。
	 */
	public function test_MCP_Query_list預設查詢total應為5(): void {
		$result = CourseQuery::list( [] );

		$this->assertSame( 5, $result['total'], 'Query::list() 預設 total 應為 5' );
		$this->assertCount( 5, $result['items'] );
	}

	/**
	 * @test
	 * @group happy
	 * MCP 版 T4：Query::list(['status' => 'future']) 應只回 1 筆（total 與 items 皆是）。
	 */
	public function test_MCP_Query_list篩選status為future應只回1筆(): void {
		$result = CourseQuery::list( [ 'status' => 'future' ] );

		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['items'] );
		$this->assertTrue( $this->response_contains_course( $result['items'], 'AI 繪圖入門' ) );
	}
}
