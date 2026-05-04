<?php
/**
 * Chapter REST API nocache 標頭整合測試（Issue #216 Bug #1b 修復驗證）
 *
 * Feature: specs/features/chapter/章節CPT結構與層級.feature
 * Rule: power-course namespace 的 REST API 必須回傳 nocache 標頭
 *
 * 本測試驗證：
 * - GET /wp-json/power-course/chapters 回應 header 含 Cache-Control: no-store / no-cache
 * - POST /wp-json/power-course/chapters/sort 同樣回傳 nocache 標頭
 * - POST /wp-json/power-course/chapters/{id} 回傳 nocache 標頭
 * - 此標頭由 nocache_headers() 於 REST 端點 callback 內注入
 *
 * 實作驗證手法：在 `rest_post_dispatch` filter 攔截 response，
 * 透過 wp_get_nocache_headers() 比對 callback 內是否確實設定過 nocache headers。
 *
 * @group chapter
 * @group api
 * @group nocache
 * @group issue-216
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;

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

	/** @var array<string,string> 攔截到的 response headers */
	private array $captured_headers = [];

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

		$this->captured_headers = [];
	}

	/**
	 * 觸發 REST 請求並攔截實際送出的 response headers。
	 *
	 * 由於 PHPUnit CLI 模式下 header() 不會真正送出，
	 * 改用 rest_pre_serve_request 與 WP_REST_Server 內部設定的 headers 攔截。
	 *
	 * @param string                 $method REST method（GET/POST/DELETE）.
	 * @param string                 $route  路徑（如 /power-course/v2/chapters）.
	 * @param array<string,mixed>    $params 參數.
	 * @return \WP_REST_Response
	 */
	private function dispatch_and_capture( string $method, string $route, array $params = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		// 模擬 REST API 來源請求
		add_filter(
			'rest_post_dispatch',
			function ( $response, $server, $req ) {
				if ( $response instanceof \WP_REST_Response ) {
					$this->captured_headers = $response->get_headers();
				}
				return $response;
			},
			10,
			3
		);

		$server   = \rest_get_server();
		$response = $server->dispatch( $request );

		// dispatch 不會自動跑 rest_post_dispatch，手動觸發
		\apply_filters( 'rest_post_dispatch', $response, $server, $request );

		return $response;
	}

	/**
	 * 斷言 response headers 包含 nocache 相關 directives。
	 *
	 * @param array<string,string> $headers Response headers.
	 */
	private function assert_nocache_headers( array $headers ): void {
		$cache_control = $headers['Cache-Control'] ?? $headers['cache-control'] ?? '';
		$this->assertNotEmpty( $cache_control, 'Cache-Control header 應已被設定' );

		// nocache_headers() 會注入 'no-cache, must-revalidate, max-age=0' 含 'no-store'
		$this->assertStringContainsString(
			'no-store',
			(string) $cache_control,
			'Cache-Control 應含 no-store（防止 LiteSpeed/WP Rocket/Cloudflare 快取）'
		);
		$this->assertStringContainsString(
			'no-cache',
			(string) $cache_control,
			'Cache-Control 應含 no-cache'
		);
	}

	// ========== Chapter Api 端點驗證 ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/章節CPT結構與層級.feature line 91-96
	 * 驗證：GET /wp-json/power-course/chapters 回應帶 nocache 標頭
	 */
	public function test_get_chapters_returns_no_cache_headers(): void {
		$response = $this->dispatch_and_capture(
			'GET',
			'/power-course/chapters',
			[ 'post_parent' => (string) $this->course_id ]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'GET /chapters 不應回傳 WP_Error' );
		$this->assert_nocache_headers( $this->captured_headers );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/排序章節.feature line 91-93
	 * 驗證：POST /chapters/sort 回應帶 nocache 標頭
	 */
	public function test_post_chapters_sort_returns_no_cache_headers(): void {
		// 建立第二個章節用於排序
		$chapter_2 = $this->create_chapter( $this->course_id, [ 'post_title' => '第二章' ] );

		$response = $this->dispatch_and_capture(
			'POST',
			'/power-course/chapters/sort',
			[
				'from_tree' => [
					[ 'id' => $this->chapter_id, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
					[ 'id' => $chapter_2, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
				],
				'to_tree' => [
					[ 'id' => $chapter_2, 'depth' => 0, 'menu_order' => 0, 'parent_id' => $this->course_id ],
					[ 'id' => $this->chapter_id, 'depth' => 0, 'menu_order' => 1, 'parent_id' => $this->course_id ],
				],
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'POST /chapters/sort 不應回傳 WP_Error' );
		$this->assert_nocache_headers( $this->captured_headers );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * Spec: specs/features/chapter/章節CPT結構與層級.feature line 96
	 * 驗證：POST /chapters/{id}（更新章節）回應帶 nocache 標頭
	 */
	public function test_post_chapters_with_id_returns_no_cache_headers(): void {
		$response = $this->dispatch_and_capture(
			'POST',
			'/power-course/chapters/' . $this->chapter_id,
			[
				'post_title' => '第一章（更新）',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'POST /chapters/{id} 不應回傳 WP_Error' );
		$this->assert_nocache_headers( $this->captured_headers );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * 驗證：POST /chapters（新增章節）回應帶 nocache 標頭
	 */
	public function test_post_chapters_returns_no_cache_headers(): void {
		$response = $this->dispatch_and_capture(
			'POST',
			'/power-course/chapters',
			[
				'post_title'       => '新章節',
				'depth'            => 0,
				'parent_course_id' => $this->course_id,
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'POST /chapters 不應回傳 WP_Error' );
		$this->assert_nocache_headers( $this->captured_headers );
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-216
	 *
	 * 驗證：DELETE /chapters/{id} 回應帶 nocache 標頭
	 */
	public function test_delete_chapters_with_id_returns_no_cache_headers(): void {
		$response = $this->dispatch_and_capture(
			'DELETE',
			'/power-course/chapters/' . $this->chapter_id
		);

		$this->assertNotInstanceOf( \WP_Error::class, $response, 'DELETE /chapters/{id} 不應回傳 WP_Error' );
		$this->assert_nocache_headers( $this->captured_headers );
	}
}
