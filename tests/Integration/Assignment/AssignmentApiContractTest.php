<?php
/**
 * Assignment REST API 契約測試（Issue #131 第一波 P2）
 *
 * 無直接對應單一 .feature（跨全部 assignment feature 的路由契約），依據：
 *   - 計劃 §3「跨階段共用：AssignmentApiContractTest.php（路由已註冊、每個 callback 第一個
 *     語句是 nocache_headers()、非管理員 403 / 未登入 401 矩陣），每階段新增端點時一併擴充」
 *   - .claude/rules/wordpress.rule.md「REST API 快取控制（Issue #216）」
 *   - tests/Integration/Chapter/ChapterApiNocacheHeadersTest.php（同款靜態檢查手法）
 *
 * ─── 本層「能驗什麼、不能驗什麼」────────────────────────────
 * nocache_headers() 在 PHPUnit CLI 下沒有任何 runtime 觀測點（headers_sent() 已為 true，
 * 詳見 ChapterApiNocacheHeadersTest 開頭說明），故一律用 token_get_all() 靜態檢查
 * callback 的第一個語句；J7\PowerCourse\Resources\Assignment\Core\Api 類別 / 方法
 * 不存在時，一律 $this->fail() 產生 Failure，不讓 ReflectionException 變成 fatal。
 *
 * 本階段（P2）只有 6 條路由；@dataProvider 用 label 而非硬寫路由字串，
 * P3～P4 新增路由時只需擴充 route_provider() 與 route_case()。
 * ──────────────────────────────────────────────────────
 */

declare( strict_types=1 );

namespace Tests\Integration\Assignment;

/**
 * Class AssignmentApiContractTest
 *
 * @group assignment
 * @group issue-131
 */
class AssignmentApiContractTest extends AssignmentTestCase {

	/** Assignment Api 類別 FQCN（計劃 §5.1，不含前導反斜線，供動態 Reflection 用） */
	private const ASSIGNMENT_API_CLASS = 'J7\PowerCourse\Resources\Assignment\Core\Api';

	/** @var int 管理員（Admin） */
	private int $admin_id;

	/** @var int 非管理員（Alice） */
	private int $alice_id;

	/** @var int 課程 */
	private int $course_id;

	/** @var int 章節 */
	private int $chapter_id;

	/** @var int 作業 */
	private int $assignment_id;

	/**
	 * 建立共用固定資料：管理員、非管理員、課程、章節、作業
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_aac_' . \uniqid(),
				'user_email' => 'admin_aac_' . \uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_aac_' . \uniqid(),
				'user_email' => 'alice_aac_' . \uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);

		$this->course_id     = $this->create_course( [ 'post_title' => 'API 契約測試課程' ] );
		$this->chapter_id    = $this->create_chapter( $this->course_id, [ 'post_title' => 'API 契約測試章節' ] );
		$this->assignment_id = $this->create_assignment( $this->chapter_id, [ 'title' => 'API 契約測試作業' ] );
	}

	/**
	 * P2 的 6 條路由：label => [method, callback method name]
	 * label 用來在 route_case() 組出具體路由與參數，callback name 依 ApiBase 命名慣例推導
	 * （method_endpoint轉snake_case_callback，(?P<id>\d+) → with_id）
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function route_provider(): array {
		return [
			'list'   => [ 'list' ],
			'create' => [ 'create' ],
			'get'    => [ 'get' ],
			'update' => [ 'update' ],
			'delete' => [ 'delete' ],
			'sort'   => [ 'sort' ],
		];
	}

	/**
	 * 依 label 組出 [method, route, params]
	 *
	 * @param string $label route_provider() 的 label
	 * @return array{0: string, 1: string, 2: array<string, mixed>}
	 */
	private function route_case( string $label ): array {
		return match ( $label ) {
			'list'   => [ 'GET', '/power-course/assignments', [ 'chapter_id' => $this->chapter_id ] ],
			'create' => [ 'POST', '/power-course/assignments', [ 'chapter_id' => $this->chapter_id, 'title' => '新建作業' ] ],
			'get'    => [ 'GET', '/power-course/assignments/' . $this->assignment_id, [] ],
			'update' => [ 'PUT', '/power-course/assignments/' . $this->assignment_id, [ 'title' => '更新作業' ] ],
			'delete' => [ 'DELETE', '/power-course/assignments/' . $this->assignment_id, [] ],
			'sort'   => [ 'POST', '/power-course/assignments/sort', [ 'chapter_id' => $this->chapter_id, 'ids' => [ $this->assignment_id ] ] ],
		};
	}

	/**
	 * 依 label 取得 ApiBase 推導的 callback method 名稱
	 *
	 * @param string $label route_provider() 的 label
	 * @return string
	 */
	private function callback_name( string $label ): string {
		return match ( $label ) {
			'list'   => 'get_assignments_callback',
			'create' => 'post_assignments_callback',
			'get'    => 'get_assignments_with_id_callback',
			'update' => 'put_assignments_with_id_callback',
			'delete' => 'delete_assignments_with_id_callback',
			'sort'   => 'post_assignments_sort_callback',
		};
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
			'端點未註冊（404 rest_no_route），請檢查 Assignment\Core\Api::$apis 是否漏了這條路由'
		);
	}

	/**
	 * 斷言指定 callback 的「第一個語句」就是 \nocache_headers()（.claude/rules/wordpress.rule.md）
	 *
	 * @param string $method Api class 的 callback method 名稱
	 */
	private function assert_callback_starts_with_nocache_headers( string $method ): void {
		$class = self::ASSIGNMENT_API_CLASS;

		if ( ! \class_exists( $class ) ) {
			$this->fail( "{$class} 尚未實作，callback {$method}() 無法檢查 nocache_headers()" );
		}
		if ( ! \method_exists( $class, $method ) ) {
			$this->fail( "{$class}::{$method}() 尚未實作" );
		}

		$ref = new \ReflectionMethod( $class, $method );

		$file  = (string) $ref->getFileName();
		$lines = (array) \file( $file );
		$src   = \implode(
			'',
			\array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 )
		);

		$tokens     = \token_get_all( '<?php ' . $src );
		$in_body    = false;
		$first_stmt = null;

		foreach ( $tokens as $token ) {
			if ( ! \is_array( $token ) ) {
				if ( '{' === $token ) {
					$in_body = true;
				}
				continue;
			}

			if ( ! $in_body ) {
				continue;
			}

			if ( \in_array( $token[0], [ T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ], true ) ) {
				continue;
			}

			$first_stmt = $token[1];
			break;
		}

		$this->assertSame(
			'nocache_headers',
			\ltrim( (string) $first_stmt, '\\' ),
			\sprintf(
				'%s::%s() 的第一個語句必須是 \nocache_headers()（Issue #216 / .claude/rules/wordpress.rule.md）',
				$class,
				$method
			)
		);
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider route_provider
	 *
	 * P2 的 6 條路由（GET/POST assignments、GET/PUT/DELETE assignments/{id}、POST assignments/sort）
	 * 皆已註冊（管理員身分呼叫，不應得到 rest_no_route）
	 *
	 * @param string $label route_provider() 的 label
	 */
	public function test_六條路由已註冊( string $label ): void {
		\wp_set_current_user( $this->admin_id );

		[ $method, $route, $params ] = $this->route_case( $label );
		$response                    = $this->dispatch( $method, $route, $params );

		$this->assert_route_registered( $response );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider route_provider
	 *
	 * 每個 callback 的第一個語句必須是 \nocache_headers()（Issue #216）
	 *
	 * @param string $label route_provider() 的 label
	 */
	public function test_每個callback第一行呼叫nocache_headers( string $label ): void {
		$this->assert_callback_starts_with_nocache_headers( $this->callback_name( $label ) );
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider route_provider
	 *
	 * 非管理員（Alice，subscriber）呼叫任一路由皆應回應 403 rest_forbidden（DEC-01）
	 *
	 * @param string $label route_provider() 的 label
	 */
	public function test_非管理員呼叫時回應403( string $label ): void {
		\wp_set_current_user( $this->alice_id );

		[ $method, $route, $params ] = $this->route_case( $label );
		$response                    = $this->dispatch( $method, $route, $params );

		$this->assert_permission_denied( $response );
	}
}
