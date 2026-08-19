<?php
/**
 * Chapter REST API nocache 標頭整合測試（Issue #216 Bug #1b 修復驗證）
 *
 * Feature: specs/features/chapter/章節CPT結構與層級.feature
 * Rule: power-course namespace 的 REST API 必須回傳 nocache 標頭
 *
 * ─── 本層「能驗什麼、不能驗什麼」（務必先讀完再改本檔）─────────────────
 *
 * nocache_headers() 是用 PHP header() 送標頭，它「不會」寫進
 * WP_REST_Response::$headers，也「不會」經過 WP_REST_Server::send_header()，
 * 所以 $response->get_headers() 永遠是空的 —— 這不是外掛沒設 header。
 *
 * 更關鍵：WP 測試 bootstrap 在跑測試前已經 echo 過文字
 *（"Not running ajax tests." 等），整個 PHPUnit process 的
 * headers_sent() === true，nocache_headers() 第一行就
 * `if ( headers_sent() ) { return; }` 直接返回，連 'nocache_headers'
 * filter 都不會觸發 —— CLI 下沒有任何 runtime 觀測點可用。
 *
 * 反例（禁止採用）：改成斷言 Spy_REST_Server::$sent_headers['Cache-Control']。
 * WP core 自己在 WP_REST_Server::serve_request() 內，對「已登入使用者」
 * 本來就會送一整組 nocache 標頭（rest_send_nocache_headers filter，
 * wp-includes/rest-api/class-wp-rest-server.php:487）。
 * 那樣就算把本外掛所有 \nocache_headers() 呼叫刪光，測試依然全綠 —— 說謊的儀器。
 *
 * 因此本測試分兩段，兩段都是真斷言：
 *   1. runtime  ：端點確實有註冊、dispatch 回 200（不是 404 rest_no_route）。
 *   2. contract ：用 token_get_all() 檢查該 callback 的「第一個語句」
 *                 確實是 \nocache_headers()（.claude/rules/wordpress.rule.md 明文規定）。
 * 真實 HTTP 回應標頭由 tests/e2e/01-admin/api-chapter-crud.spec.ts
 *（describe 'Issue #216 — REST API nocache 標頭'）在真的 web SAPI 下驗證。
 * ────────────────────────────────────────────────────────────────
 *
 * @group chapter
 * @group api
 * @group nocache
 * @group issue-216
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Core\Api as ChapterApi;

/**
 * Class ChapterApiNocacheHeadersTest
 * 驗證 Chapter REST callbacks 注入 nocache_headers
 */
class ChapterApiNocacheHeadersTest extends TestCase {

	/** @var int 課程 ID */
	private int $course_id;

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int 章節 ID */
	private int $chapter_id;

	protected function configure_dependencies(): void {
	}

	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_nocache_' . uniqid(),
				'user_email' => 'admin_nocache_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );

		$this->course_id  = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->chapter_id = $this->create_chapter( $this->course_id, [ 'post_title' => '第一章' ] );
	}

	/**
	 * 觸發 REST 請求。
	 *
	 * @param string                   $method    REST method（GET/POST/DELETE）.
	 * @param string                   $route     路徑（如 /power-course/chapters）.
	 * @param array<string,mixed>      $params    一般參數（GET query / POST body）.
	 * @param array<string,mixed>|null $json_body 有值時改以 application/json body 送出，
	 *                                            供 callback 內用 get_json_params() 讀取的端點使用.
	 * @return \WP_REST_Response
	 */
	private function dispatch( string $method, string $route, array $params = [], ?array $json_body = null ): \WP_REST_Response {
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

	/**
	 * 斷言端點確實有註冊且成功回應。
	 *
	 * 特別攔 404 rest_no_route：dispatch() 會把 WP_Error 轉成 WP_REST_Response，
	 * 所以「只斷言不是 WP_Error」會在路由根本不存在時假性通過。
	 *
	 * @param \WP_REST_Response $response Response.
	 */
	private function assert_endpoint_dispatched( \WP_REST_Response $response ): void {
		$data = $response->get_data();
		$code = is_array( $data ) ? ( $data['code'] ?? '' ) : '';

		$this->assertNotSame(
			'rest_no_route',
			$code,
			'端點未註冊（404 rest_no_route），請檢查 Api::$apis 是否漏了這條路由'
		);
		$this->assertSame( 200, $response->get_status(), 'REST 端點應回應 200' );
	}

	/**
	 * 斷言指定 callback 的「第一個語句」就是 \nocache_headers()。
	 *
	 * 依據 .claude/rules/wordpress.rule.md：
	 * 「所有 power-course namespace 的 REST callback 必須在第一行呼叫 \nocache_headers()」（Issue #216）。
	 * 用 token_get_all() 而非字串比對，才不會被註解 / 空白 / 換行騙過。
	 *
	 * @param string $method Api class 的 callback method 名稱.
	 */
	private function assert_callback_starts_with_nocache_headers( string $method ): void {
		$ref = new \ReflectionMethod( ChapterApi::class, $method );

		$file  = (string) $ref->getFileName();
		$lines = (array) file( $file );
		$src   = implode(
			'',
			array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 )
		);

		$tokens     = token_get_all( '<?php ' . $src );
		$in_body    = false;
		$first_stmt = null;

		foreach ( $tokens as $token ) {
			if ( ! is_array( $token ) ) {
				// 方法簽章結束、進入 body 的第一個 '{'
				if ( '{' === $token ) {
					$in_body = true;
				}
				continue;
			}

			if ( ! $in_body ) {
				continue;
			}

			if ( in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$first_stmt = $token[1];
			break;
		}

		$this->assertSame(
			'nocache_headers',
			ltrim( (string) $first_stmt, '\\' ),
			sprintf(
				'%s::%s() 的第一個語句必須是 \nocache_headers()（Issue #216 / .claude/rules/wordpress.rule.md）',
				ChapterApi::class,
				$method
			)
		);
	}

	// ========== Chapter Api 端點驗證 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/章節CPT結構與層級.feature line 91-96
	 * 驗證：GET /power-course/chapters 有註冊，且 callback 第一行注入 nocache
	 */
	public function test_get_chapters_returns_no_cache_headers(): void {
		$response = $this->dispatch(
			'GET',
			'/power-course/chapters',
			[ 'post_parent' => (string) $this->course_id ]
		);

		$this->assert_endpoint_dispatched( $response );
		$this->assert_callback_starts_with_nocache_headers( 'get_chapters_callback' );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/排序章節.feature line 91-93
	 * 驗證：POST /chapters/sort 有註冊，且 callback 第一行注入 nocache
	 *
	 * 註：此端點以 get_json_params() 讀 body（前端 SortableChapters 走 axios JSON），
	 *     所以必須送 application/json body，不能用 set_param()。
	 */
	public function test_post_chapters_sort_returns_no_cache_headers(): void {
		$chapter_2 = $this->create_chapter( $this->course_id, [ 'post_title' => '第二章' ] );

		$response = $this->dispatch(
			'POST',
			'/power-course/chapters/sort',
			[],
			[
				'from_tree' => [
					[
						'id'         => (string) $this->chapter_id,
						'depth'      => 0,
						'menu_order' => 0,
						'parent_id'  => (string) $this->course_id,
					],
					[
						'id'         => (string) $chapter_2,
						'depth'      => 0,
						'menu_order' => 1,
						'parent_id'  => (string) $this->course_id,
					],
				],
				'to_tree'   => [
					[
						'id'         => (string) $chapter_2,
						'depth'      => 0,
						'menu_order' => 0,
						'parent_id'  => (string) $this->course_id,
					],
					[
						'id'         => (string) $this->chapter_id,
						'depth'      => 0,
						'menu_order' => 1,
						'parent_id'  => (string) $this->course_id,
					],
				],
			]
		);

		$this->assert_endpoint_dispatched( $response );
		$this->assertSame( 'sort_success', $response->get_data()['code'] ?? '', '排序應成功' );
		$this->assert_callback_starts_with_nocache_headers( 'post_chapters_sort_callback' );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/章節CPT結構與層級.feature line 96
	 * 驗證：POST /chapters/{id}（更新章節）有註冊，且 callback 第一行注入 nocache
	 */
	public function test_post_chapters_with_id_returns_no_cache_headers(): void {
		$response = $this->dispatch(
			'POST',
			'/power-course/chapters/' . $this->chapter_id,
			[
				'post_title' => '第一章（更新）',
			]
		);

		$this->assert_endpoint_dispatched( $response );
		$this->assert_callback_starts_with_nocache_headers( 'post_chapters_with_id_callback' );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * 驗證：POST /chapters（新增章節）有註冊，且 callback 第一行注入 nocache
	 */
	public function test_post_chapters_returns_no_cache_headers(): void {
		$response = $this->dispatch(
			'POST',
			'/power-course/chapters',
			[
				'post_title'       => '新章節',
				'depth'            => 0,
				'parent_course_id' => $this->course_id,
			]
		);

		$this->assert_endpoint_dispatched( $response );
		$this->assert_callback_starts_with_nocache_headers( 'post_chapters_callback' );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/刪除章節.feature line 39
	 *「後置（狀態）- 透過自訂 API 端點 DELETE /power-course/chapters/{id} 刪除章節」
	 * 驗證：DELETE /chapters/{id} 有註冊，且 callback 第一行注入 nocache
	 */
	public function test_delete_chapters_with_id_returns_no_cache_headers(): void {
		$response = $this->dispatch(
			'DELETE',
			'/power-course/chapters/' . $this->chapter_id
		);

		$this->assert_endpoint_dispatched( $response );
		$this->assertSame( 'delete_success', $response->get_data()['code'] ?? '', '刪除應成功' );
		$this->assertSame( 'trash', \get_post_status( $this->chapter_id ), '章節應被移到回收桶' );
		$this->assert_callback_starts_with_nocache_headers( 'delete_chapters_with_id_callback' );
	}
}
