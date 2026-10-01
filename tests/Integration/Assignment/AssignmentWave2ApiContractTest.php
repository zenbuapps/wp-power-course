<?php
/**
 * Assignment 第二波 REST API 契約測試（Issue #131 第二波）
 *
 * 無直接對應單一 .feature（跨第二波 feature 的路由契約），依據：
 *   - 第二波計劃 §3 第 4 列（「5 條新路由的契約：註冊、nocache 靜態檢查、401 / 403 矩陣、DEC-W2-02 守門」）
 *   - 第二波計劃 §4 DEC-W2-01（落點與 callback 第一句 \nocache_headers()）、DEC-W2-02（路由不宣告 required args）
 *   - .claude/rules/wordpress.rule.md「REST API 快取控制（Issue #216）」
 *   - 第一波 AssignmentApiContractTest.php（唯讀，同款手法）
 *
 * ─── 本層「能驗什麼、不能驗什麼」────────────────────────────
 * nocache_headers() 在 PHPUnit CLI 下沒有任何 runtime 觀測點（headers_sent() 已為 true），
 * 一律用 token_get_all() 靜態檢查 callback 的第一個語句（assert_callback_starts_with_nocache_headers_for）；
 * 真實 HTTP 回應標頭由 W2 的 017 E2E 驗證。Core\Api / Core\MyApi 類別或 callback 不存在時，
 * 一律 $this->fail() 產生 Failure，不讓 ReflectionException 變成 fatal。
 *
 * 本檔 W1 部分涵蓋討論串 4 條路由（管理端 GET / POST、學員端 GET / POST）；
 * W2-R1 會擴充 POST assignment-submissions/import（只新增，不改既有方法語意）。
 * ──────────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentWave2ApiContractTest
 *
 * @group assignment
 * @group issue-131
 * @group issue-131-w2
 */
class AssignmentWave2ApiContractTest extends AssignmentWave2TestCase {

	/** Assignment 管理端 Api 類別 FQCN（計劃 DEC-W2-01） */
	private const ASSIGNMENT_API_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\Api';

	/** Assignment 學員端 MyApi 類別 FQCN（計劃 DEC-W2-01） */
	private const ASSIGNMENT_MY_API_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\MyApi';

	/**
	 * 建立共用固定資料（沿用「發送作業討論訊息」feature 的 Background）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->seed_thread_fixture( 'post' );
	}

	// ========== 路由對照（label => 路由 / method / callback） ==========

	/**
	 * 討論串 4 條路由的 label
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function thread_route_provider(): array {
		return [
			'管理端 GET messages'  => [ 'admin_get' ],
			'管理端 POST messages' => [ 'admin_post' ],
			'學員端 GET messages'  => [ 'my_get' ],
			'學員端 POST messages' => [ 'my_post' ],
		];
	}

	/**
	 * 依 label 取得路由資訊
	 *
	 * @param string $label thread_route_provider() 的 label
	 * @return array{method: string, pattern: string, side: string, class: string, callback: string}
	 *         pattern = register_rest_route 註冊的路由 regex；side = 'admin' 或 'my'
	 */
	private function route_info( string $label ): array {
		return match ( $label ) {
			'admin_get'  => [
				'method'   => 'GET',
				'pattern'  => '/power-course/assignment-submissions/(?P<id>\d+)/messages',
				'side'     => 'admin',
				'class'    => self::ASSIGNMENT_API_CLASS,
				'callback' => 'get_assignment_submissions_with_id_messages_callback',
			],
			'admin_post' => [
				'method'   => 'POST',
				'pattern'  => '/power-course/assignment-submissions/(?P<id>\d+)/messages',
				'side'     => 'admin',
				'class'    => self::ASSIGNMENT_API_CLASS,
				'callback' => 'post_assignment_submissions_with_id_messages_callback',
			],
			'my_get'     => [
				'method'   => 'GET',
				'pattern'  => '/power-course/my/assignment-submissions/(?P<id>\d+)/messages',
				'side'     => 'my',
				'class'    => self::ASSIGNMENT_MY_API_CLASS,
				'callback' => 'get_my_assignment_submissions_with_id_messages_callback',
			],
			'my_post'    => [
				'method'   => 'POST',
				'pattern'  => '/power-course/my/assignment-submissions/(?P<id>\d+)/messages',
				'side'     => 'my',
				'class'    => self::ASSIGNMENT_MY_API_CLASS,
				'callback' => 'post_my_assignment_submissions_with_id_messages_callback',
			],
		};
	}

	/**
	 * 依 label 打一次真實 REST 請求（POST 一律帶合法 body，讓 400 不會蓋過權限結果）
	 *
	 * @param string $label thread_route_provider() 的 label
	 * @return \WP_REST_Response
	 */
	private function dispatch_route( string $label ): \WP_REST_Response {
		$info  = $this->route_info( $label );
		$route = 'admin' === $info['side']
			? $this->admin_thread_route( $this->fx['sub_500'] )
			: $this->my_thread_route( $this->fx['sub_500'] );

		return 'POST' === $info['method']
			? $this->dispatch( 'POST', $route, [], [ 'body' => '契約測試訊息' ] )
			: $this->dispatch( 'GET', $route );
	}

	/**
	 * 斷言端點確實有註冊（不是 404 rest_no_route）
	 * dispatch() 會把 WP_Error 轉成 WP_REST_Response，所以「只斷言不是 WP_Error」
	 * 在路由根本不存在時會假性通過，必須明確排除 rest_no_route
	 *
	 * @param \WP_REST_Response $response REST 回應
	 */
	private function assert_route_registered( \WP_REST_Response $response ): void {
		$data = $response->get_data();
		$code = \is_array( $data ) ? ( $data['code'] ?? '' ) : '';

		$this->assertNotSame(
			'rest_no_route',
			$code,
			'端點未註冊（404 rest_no_route），請檢查 Assignment\Core\Api / MyApi 的 $apis 是否漏了這條路由'
		);
	}

	// ========== 註冊 ==========

	/**
	 * @test
	 * @group happy
	 * @dataProvider thread_route_provider
	 *
	 * 4 條討論串路由皆已註冊、HTTP method 正確（該路由只有 GET 與 POST 兩個 method），
	 * 且對應身分（管理員 / 學員）打下去不會是 rest_no_route
	 *
	 * @param string $label thread_route_provider() 的 label
	 */
	public function test_討論串路由已註冊且method正確( string $label ): void {
		$info = $this->route_info( $label );

		// (1) 路由表：該 regex 存在，且 method 恰為 GET + POST
		$routes = \rest_get_server()->get_routes();
		$this->assertArrayHasKey( $info['pattern'], $routes, "路由 {$info['pattern']} 未註冊" );

		$methods = [];
		foreach ( $routes[ $info['pattern'] ] as $handler ) {
			$methods = \array_merge( $methods, \array_keys( (array) ( $handler['methods'] ?? [] ) ) );
		}
		$this->assertContains( $info['method'], $methods, "路由 {$info['pattern']} 應支援 {$info['method']}" );
		$this->assertEqualsCanonicalizing( [ 'GET', 'POST' ], \array_unique( $methods ), "路由 {$info['pattern']} 只應支援 GET 與 POST" );

		// (2) 真實請求：管理端用管理員、學員端用本人（Alice）
		\wp_set_current_user( 'admin' === $info['side'] ? $this->fx['admin'] : $this->fx['alice'] );
		$response = $this->dispatch_route( $label );

		$this->assert_route_registered( $response );
		$this->assert_success_response( $response );
	}

	// ========== nocache_headers() 靜態檢查 ==========

	/**
	 * @test
	 * @group happy
	 * @dataProvider thread_route_provider
	 *
	 * 每個 callback 的第一個語句必須是 \nocache_headers()（Issue #216；GET 有「標已讀」副作用，
	 * 被邊緣快取會造成快取命中時不標已讀，第二波 R-W2-02）
	 *
	 * @param string $label thread_route_provider() 的 label
	 */
	public function test_討論串callback第一行呼叫nocache_headers( string $label ): void {
		$info = $this->route_info( $label );

		$this->assert_callback_starts_with_nocache_headers_for( $info['class'], $info['callback'] );
	}

	// ========== 401 矩陣 ==========

	/**
	 * @test
	 * @group security
	 * @dataProvider thread_route_provider
	 *
	 * 訪客呼叫 4 條皆 401：管理端是 ApiBase 預設 permission（rest_forbidden，WP 對未登入者回 401），
	 * 學員端是 check_logged_in_permission（rest_not_logged_in，DEC-01）
	 *
	 * @param string $label thread_route_provider() 的 label
	 */
	public function test_訪客呼叫討論串路由時回應401( string $label ): void {
		$info = $this->route_info( $label );

		\wp_set_current_user( 0 );
		$response = $this->dispatch_route( $label );

		$data = $response->get_data();
		$this->assertSame( 401, $response->get_status(), '訪客應回應 401（實際回應：' . (string) \wp_json_encode( $data ) . '）' );
		$this->assertSame(
			'admin' === $info['side'] ? 'rest_forbidden' : 'rest_not_logged_in',
			\is_array( $data ) ? ( $data['code'] ?? null ) : null,
			'錯誤代碼不符'
		);
	}

	// ========== 403（DEC-W2-02 守門） ==========

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function admin_method_provider(): array {
		return [
			'GET'  => [ 'GET' ],
			'POST' => [ 'POST' ],
		];
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider admin_method_provider
	 *
	 * 非管理員（Bob）呼叫管理端 GET 與 POST（POST 不帶 body）皆 403 rest_forbidden。
	 * DEC-W2-02 守門：WP REST 先驗參數、後驗權限，路由若宣告 required args，
	 * 「不帶 body 的非管理員」會變成 400 而不是 403。
	 *
	 * @param string $method 'GET' 或 'POST'
	 */
	public function test_非管理員呼叫管理端討論串路由時回應403( string $method ): void {
		\wp_set_current_user( $this->fx['bob'] );

		$response = $this->dispatch( $method, $this->admin_thread_route( $this->fx['sub_500'] ) );

		$this->assert_permission_denied( $response );
		$this->assertSame( 0, $this->count_messages( $this->fx['sub_500'] ), '非管理員不得寫入訊息' );
	}
}
