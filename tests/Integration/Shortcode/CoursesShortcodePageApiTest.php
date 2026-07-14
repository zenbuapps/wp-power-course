<?php
/**
 * [pc_courses] 課程列表過濾與翻頁「端點級」整合測試（Issue #254 / Issue #236）
 *
 * 與 AjaxCategoryEncodingTest 的分工：
 * - AjaxCategoryEncodingTest 用 Reflection 直測 private sanitize_slug_list()（單元層級）。
 * - 本檔案打真正的 REST 端點 /power-course/courses-shortcode-page，驗證 issue 真正在講的
 *   「第 2 頁只回該分類的課程」這個外部可觀察行為，以及短代碼首屏的等價行為。
 *
 * 覆蓋的三個缺口：
 * - D1：短代碼產生器輸出的 product_category_id / product_tag_id（term ID）過濾必須生效。
 *       wc_get_products() 以 'field' => 'slug' 查 term，傳 ID 進去會回 0 筆，
 *       故後端必須把 term ID 正規化成 slug；ID 與 slug 兩種寫法都要吃。
 * - D2：翻頁端點必須對「登出訪客」開放（原本 permission_callback 寫 null，
 *       ApiBase::register_apis() 對 null 會 fallback 成 manage_options|manage_woocommerce → 訪客 401）。
 *       同時 /power-course/shortcode（會 do_shortcode 外部字串）必須維持管理員限定。
 * - D3：本檔案本身——端點級迴歸保護。
 *
 * @group shortcode
 * @group api
 * @group issue-254
 */

declare( strict_types=1 );

namespace Tests\Integration\Shortcode;

use Tests\Integration\TestCase;
use J7\PowerCourse\Shortcodes\General as Shortcodes;

/**
 * Class CoursesShortcodePageApiTest
 *
 * 端點級：REST /power-course/courses-shortcode-page 的過濾 × 翻頁 × 權限行為。
 */
class CoursesShortcodePageApiTest extends TestCase {

	/** @var string 翻頁端點路由 */
	private const PAGE_ROUTE = '/power-course/courses-shortcode-page';

	/** @var string 短代碼預覽端點路由（管理員限定，會 do_shortcode 外部字串） */
	private const SHORTCODE_ROUTE = '/power-course/shortcode';

	/** @var int 「前端」分類（中文名 → slug 為 percent-encoded）的 term ID */
	private int $cat_zh_id;

	/** @var string 「前端」分類的 slug（WordPress 產生，形如 %e5%89%8d%e7%ab%af） */
	private string $cat_zh_slug;

	/** @var int 「後端」分類的 term ID（對照組，結果中不應出現） */
	private int $cat_other_id;

	/** @var int 中文標籤的 term ID */
	private int $tag_zh_id;

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int 「前端」分類下的課程數（> limit，確保有第 2 頁） */
	private const ZH_COURSE_COUNT = 15;

	/** @var int 「後端」分類下的課程數（對照組） */
	private const OTHER_COURSE_COUNT = 7;

	/** @var int 未分類課程數（對照組，證明「無過濾」時總數確實更大） */
	private const UNCATEGORIZED_COURSE_COUNT = 4;

	/** @var array<int> 「前端」分類課程 ID */
	private array $zh_course_ids = [];

	/** @var array<int> 「後端」分類課程 ID */
	private array $other_course_ids = [];

	/**
	 * 初始化依賴（本測試直接打 REST 端點，無需注入 repository/service）
	 */
	protected function configure_dependencies(): void {
	}

	/**
	 * 每個測試前建立測試資料：
	 * 中文分類 + 中文標籤 + 三組課程（前端 15 / 後端 7 / 未分類 4）。
	 */
	public function set_up(): void {
		parent::set_up();

		// 暖機 REST server，並吸收 MCP abilities 註冊噴出的 _doing_it_wrong 通知。
		//
		// 這些通知源自 WP 6.9 Abilities API 與 mcp-adapter 的既有問題（category slug 用了底線），
		// 與本測試無關；但 WP_UnitTestCase 會把「未預期的 _doing_it_wrong」判定為測試失敗，
		// 而它只在「第一次」rest_api_init 時觸發 —— 全套跑時誰第一個打 REST 誰倒楣。
		// 在此統一暖機並清掉，讓本檔的結果不受測試執行順序影響（否則會變成間歇性紅燈）。
		\rest_get_server();
		$this->caught_doing_it_wrong = [];

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_i254api_' . uniqid(),
				'user_email' => 'admin_i254api_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );

		// 中文分類「前端」：WordPress 會把中文 slug 轉成 percent-encoded（%e5%89%8d%e7%ab%af）。
		$this->cat_zh_id   = $this->create_term( '前端', 'product_cat' );
		$this->cat_zh_slug = $this->get_term_slug( $this->cat_zh_id, 'product_cat' );

		// 對照組分類「後端」。
		$this->cat_other_id = $this->create_term( '後端', 'product_cat' );

		// 中文標籤。
		$this->tag_zh_id = $this->create_term( '影音', 'product_tag' );

		// 「前端」分類 15 門課（limit=6 → 3 頁）。
		for ( $i = 0; $i < self::ZH_COURSE_COUNT; $i++ ) {
			$course_id             = $this->create_course( [ 'post_title' => '前端課程 ' . ( $i + 1 ) ] );
			$this->zh_course_ids[] = $course_id;
			\wp_set_object_terms( $course_id, [ $this->cat_zh_id ], 'product_cat' );
			\wp_set_object_terms( $course_id, [ $this->tag_zh_id ], 'product_tag' );
		}

		// 「後端」分類 7 門課（對照組：過濾生效時絕不能出現在結果中）。
		for ( $i = 0; $i < self::OTHER_COURSE_COUNT; $i++ ) {
			$course_id                = $this->create_course( [ 'post_title' => '後端課程 ' . ( $i + 1 ) ] );
			$this->other_course_ids[] = $course_id;
			\wp_set_object_terms( $course_id, [ $this->cat_other_id ], 'product_cat' );
		}

		// 未分類 4 門課（對照組）。
		for ( $i = 0; $i < self::UNCATEGORIZED_COURSE_COUNT; $i++ ) {
			$this->create_course( [ 'post_title' => '未分類課程 ' . ( $i + 1 ) ] );
		}
	}

	// ========== Helpers ==========

	/**
	 * 建立 term 並回傳 term ID。
	 *
	 * @param string $name     term 名稱（中文，slug 會被 WP percent-encode）。
	 * @param string $taxonomy 分類法（product_cat / product_tag）。
	 * @return int term ID。
	 */
	private function create_term( string $name, string $taxonomy ): int {
		$result = \wp_insert_term( $name, $taxonomy );
		$this->assertIsArray( $result, "建立 term「{$name}」失敗（taxonomy={$taxonomy}）" );
		return (int) $result['term_id'];
	}

	/**
	 * 取得 term slug。
	 *
	 * @param int    $term_id  term ID。
	 * @param string $taxonomy 分類法。
	 * @return string slug。
	 */
	private function get_term_slug( int $term_id, string $taxonomy ): string {
		$term = \get_term( $term_id, $taxonomy );
		$this->assertInstanceOf( \WP_Term::class, $term, "取得 term {$term_id} 失敗" );
		return $term->slug;
	}

	/**
	 * 打翻頁端點。
	 *
	 * @param array<string, mixed> $params query 參數。
	 * @return \WP_REST_Response
	 */
	private function request_page( array $params ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', self::PAGE_ROUTE );
		$request->set_query_params( array_map( 'strval', $params ) );
		return \rest_do_request( $request );
	}

	/**
	 * 取出翻頁端點回應的 data 區塊（並先斷言 HTTP 200）。
	 *
	 * @param \WP_REST_Response $response REST 回應。
	 * @return array{html:string, total:int, total_pages:int, current_page:int}
	 */
	private function page_data( \WP_REST_Response $response ): array {
		$this->assertSame(
			200,
			$response->get_status(),
			'翻頁端點應回 200，實際回應：' . \wp_json_encode( $response->get_data() )
		);

		/** @var array{data: array{html:string, total:int, total_pages:int, current_page:int}} $body */
		$body = $response->get_data();
		$this->assertArrayHasKey( 'data', $body, '回應應含 data 區塊' );

		return $body['data'];
	}

	// ========== 冒煙：測試前提（環境真的有在跑，不是空轉）==========

	/**
	 * @test
	 * @group smoke
	 *
	 * 冒煙：確認測試前提成立——product_cat 分類法存在、中文 slug 確實被 percent-encode、
	 * 且 fixture 的課程數量真的建起來了。
	 *
	 * 這支測試存在的意義：如果環境根本沒註冊 product_cat、或 wc_get_products 查不到任何課程，
	 * 下面所有「過濾生效」的斷言都會變成無意義的空轉綠燈。先把前提釘死。
	 */
	public function test_冒煙_測試前提_中文分類slug為percent_encoded且課程建立成功(): void {
		$this->assertTrue( \taxonomy_exists( 'product_cat' ), 'product_cat 分類法應存在（WooCommerce 已載入）' );
		$this->assertTrue( \taxonomy_exists( 'product_tag' ), 'product_tag 分類法應存在' );

		// 中文 slug 必須是 percent-encoded（這正是 Issue #254 的病灶前提）。
		$this->assertSame(
			'%e5%89%8d%e7%ab%af',
			$this->cat_zh_slug,
			'「前端」的 term slug 應為 percent-encoded'
		);

		// 不帶任何過濾 → 應查得到全部 26 門課（15 + 7 + 4）。
		// 若此處為 0，代表 wc_get_products 在測試環境沒作用，下方過濾斷言全部不可信。
		$data = $this->page_data( $this->request_page( [ 'limit' => 100, 'page' => 1 ] ) );

		$this->assertSame(
			self::ZH_COURSE_COUNT + self::OTHER_COURSE_COUNT + self::UNCATEGORIZED_COURSE_COUNT,
			$data['total'],
			'不帶過濾時應回傳全部課程；若為 0 代表 wc_get_products 在測試環境失效，其餘斷言不可信'
		);
	}

	// ========== 快樂路徑：分類過濾 × 翻頁（Issue #254 本體）==========

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（Issue #254 本體）：中文分類 slug 在 AJAX 翻到第 2 頁時，過濾必須持續生效，
	 * total 必須是「該分類的課程數」而不是「全站課程數」。
	 */
	public function test_快樂_中文分類slug翻到第2頁只回該分類課程(): void {
		$data = $this->page_data(
			$this->request_page(
				[
					'category' => $this->cat_zh_slug,
					'limit'    => 6,
					'page'     => 2,
				]
			)
		);

		$this->assertSame( 2, $data['current_page'], 'current_page 應為 2' );
		$this->assertSame(
			self::ZH_COURSE_COUNT,
			$data['total'],
			'第 2 頁的 total 應為該分類課程數 15，而非全站課程數 26（過濾靜默失效）'
		);
		$this->assertSame( 3, $data['total_pages'], '15 門課 / 每頁 6 → 3 頁' );

		// 第 2 頁的卡片不可混入其他分類的課程。
		$this->assertStringNotContainsString(
			'後端課程',
			$data['html'],
			'第 2 頁不應出現其他分類的課程'
		);
		$this->assertStringNotContainsString(
			'未分類課程',
			$data['html'],
			'第 2 頁不應出現未分類的課程'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（D1）：短代碼產生器輸出的是 [pc_courses product_category_id="16"]（term ID）。
	 * 翻頁端點必須接受 product_category_id，且把 term ID 正規化成 slug 後才交給 wc_get_products
	 * （wc_get_products 是以 'field' => 'slug' 查 term，直接餵 ID 會回 0 筆）。
	 */
	public function test_快樂_product_category_id翻到第2頁只回該分類課程(): void {
		$data = $this->page_data(
			$this->request_page(
				[
					'product_category_id' => $this->cat_zh_id,
					'limit'               => 6,
					'page'                => 2,
				]
			)
		);

		$this->assertSame( 2, $data['current_page'], 'current_page 應為 2' );
		$this->assertSame(
			self::ZH_COURSE_COUNT,
			$data['total'],
			'product_category_id 過濾必須生效：total 應為 15（該分類課程數），而非 26（全站）或 0（ID 被當 slug 查）'
		);
		$this->assertStringNotContainsString( '後端課程', $data['html'], '不應出現其他分類的課程' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（D1）：category 參數同時要相容「直接傳 term ID」的寫法。
	 */
	public function test_快樂_category參數直接傳term_id也要能過濾(): void {
		$data = $this->page_data(
			$this->request_page(
				[
					'category' => $this->cat_zh_id,
					'limit'    => 100,
					'page'     => 1,
				]
			)
		);

		$this->assertSame(
			self::ZH_COURSE_COUNT,
			$data['total'],
			'category 傳 term ID 時也應正規化成 slug 後過濾'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（D1）：product_tag_id（標籤 term ID）同樣要能過濾。
	 */
	public function test_快樂_product_tag_id過濾生效(): void {
		$data = $this->page_data(
			$this->request_page(
				[
					'product_tag_id' => $this->tag_zh_id,
					'limit'          => 100,
					'page'           => 1,
				]
			)
		);

		$this->assertSame(
			self::ZH_COURSE_COUNT,
			$data['total'],
			'product_tag_id 過濾必須生效：只有「前端」的 15 門課掛了此標籤'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（D1，首屏）：[pc_courses product_category_id="16"] 的「首屏 server render」
	 * 也必須只顯示該分類的課程——這一段完全不經過 AJAX 端點，是 pc_courses_callback 自己的責任。
	 */
	public function test_快樂_短代碼首屏product_category_id只顯示該分類課程(): void {
		$html = Shortcodes::pc_courses_callback(
			[
				'product_category_id' => (string) $this->cat_zh_id,
				'limit'               => '6',
			]
		);

		// 容器 data-total 反映查詢總數，供前台 JS 建立分頁。
		$this->assertStringContainsString(
			'data-total="' . self::ZH_COURSE_COUNT . '"',
			$html,
			'首屏 data-total 應為該分類課程數 15，而非全站 26'
		);
		$this->assertStringContainsString( '前端課程', $html, '首屏應顯示該分類的課程' );
		$this->assertStringNotContainsString( '後端課程', $html, '首屏不應顯示其他分類的課程' );
		$this->assertStringNotContainsString( '未分類課程', $html, '首屏不應顯示未分類的課程' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Rule（D1）：首屏渲染出的 data-category 必須把 product_category_id 一起帶上，
	 * 否則前台 JS 翻頁時會漏掉過濾條件（首屏對、第 2 頁全站）。
	 */
	public function test_快樂_短代碼首屏把product_category_id帶進data_category(): void {
		$html = Shortcodes::pc_courses_callback(
			[
				'product_category_id' => (string) $this->cat_zh_id,
				'limit'               => '6',
			]
		);

		$this->assertMatchesRegularExpression(
			'/data-category="[^"]*' . preg_quote( (string) $this->cat_zh_id, '/' ) . '[^"]*"/',
			$html,
			'data-category 應帶上 product_category_id，讓 AJAX 翻頁沿用同一組過濾條件'
		);
	}

	// ========== 邊緣：term 解析失敗必須回空集合，不可退化成「全站課程」==========

	/**
	 * @test
	 * @group edge
	 *
	 * Rule（D1 安全性）：指定了不存在的分類 ID 時，必須回「空集合」，
	 * 絕不可因為 term 查不到就把過濾條件丟掉、退化成回傳全站課程。
	 */
	public function test_邊緣_不存在的分類ID回空集合而非全站課程(): void {
		$data = $this->page_data(
			$this->request_page(
				[
					'product_category_id' => 999999,
					'limit'               => 100,
					'page'                => 1,
				]
			)
		);

		$this->assertSame(
			0,
			$data['total'],
			'不存在的分類 ID 應回 0 筆；回傳全站課程代表過濾靜默失效（正是 Issue #254 的病灶模式）'
		);
		$this->assertStringNotContainsString( '前端課程', $data['html'], '不應回傳任何課程' );
		$this->assertStringNotContainsString( '後端課程', $data['html'], '不應回傳任何課程' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule（D1）：ID 與 slug 混用的逗號清單，每一項都要各自正規化（OR 語意）。
	 */
	public function test_邊緣_分類ID與slug混合清單各自正規化(): void {
		$other_slug = $this->get_term_slug( $this->cat_other_id, 'product_cat' );

		$data = $this->page_data(
			$this->request_page(
				[
					// 一項是 term ID（前端），一項是 slug（後端）。
					'category' => $this->cat_zh_id . ',' . $other_slug,
					'limit'    => 100,
					'page'     => 1,
				]
			)
		);

		$this->assertSame(
			self::ZH_COURSE_COUNT + self::OTHER_COURSE_COUNT,
			$data['total'],
			'ID + slug 混合清單應各自正規化後以 OR 合併，總數應為 15 + 7 = 22'
		);
		$this->assertStringNotContainsString( '未分類課程', $data['html'], '不應包含未分類課程' );
	}

	// ========== 安全性：匿名存取（Issue #236 遺留債）==========

	/**
	 * @test
	 * @group security
	 *
	 * Rule（D2）：翻頁端點是「前台公開」端點，登出訪客必須能翻頁。
	 *
	 * 原本 permission_callback 寫 null，但 ApiBase::register_apis() 用 is_callable() 判斷，
	 * null 不是 callable → fallback 成 ApiBase::permission_callback()
	 * （manage_options | manage_woocommerce）→ 訪客拿到 401 rest_forbidden，
	 * 前台永遠卡在骨架屏。
	 */
	public function test_安全_登出訪客可以翻頁不應回401(): void {
		\wp_set_current_user( 0 );
		$this->assertFalse( \is_user_logged_in(), '前提：目前必須是登出狀態' );

		$response = $this->request_page(
			[
				'limit' => 6,
				'page'  => 2,
			]
		);

		$this->assertNotSame(
			401,
			$response->get_status(),
			'登出訪客翻頁不應回 401（這正是前台卡骨架屏的原因）'
		);
		$this->assertNotSame( 403, $response->get_status(), '登出訪客翻頁不應回 403' );
		$this->assertSame( 200, $response->get_status(), '登出訪客翻頁應回 200' );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule（D2 + D1）：登出訪客翻頁時，分類過濾必須「持續生效」。
	 * 只把端點打開卻讓過濾失效，等於把 bug 從 401 換成資料錯誤。
	 */
	public function test_安全_登出訪客翻頁時分類過濾持續生效(): void {
		\wp_set_current_user( 0 );

		$data = $this->page_data(
			$this->request_page(
				[
					'product_category_id' => $this->cat_zh_id,
					'limit'               => 6,
					'page'                => 2,
				]
			)
		);

		$this->assertSame( 2, $data['current_page'], '訪客翻頁 current_page 應為 2' );
		$this->assertSame(
			self::ZH_COURSE_COUNT,
			$data['total'],
			'訪客翻頁時分類過濾必須持續生效（total 應為 15，而非全站 26）'
		);
		$this->assertStringNotContainsString( '後端課程', $data['html'], '訪客翻頁不應混入其他分類課程' );
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule（D2 安全邊界）：/power-course/shortcode 會對「外部傳入的字串」執行 do_shortcode()，
	 * 等同任意短代碼執行，**必須維持管理員限定**，不可跟著翻頁端點一起開放。
	 *
	 * 這支測試是防止未來有人「順手把兩支端點一起改成 __return_true」的護欄。
	 */
	public function test_安全_shortcode預覽端點對登出訪客維持401(): void {
		\wp_set_current_user( 0 );

		$request = new \WP_REST_Request( 'GET', self::SHORTCODE_ROUTE );
		$request->set_query_params( [ 'shortcode' => '[pc_courses]' ] );
		$response = \rest_do_request( $request );

		$this->assertSame(
			401,
			$response->get_status(),
			'/power-course/shortcode 會 do_shortcode 外部字串，登出訪客必須被擋（401）'
		);
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule（D2 安全邊界）：/power-course/shortcode 對「已登入但非管理員」的一般用戶也必須擋下（403）。
	 */
	public function test_安全_shortcode預覽端點對一般用戶維持403(): void {
		$subscriber_id = $this->factory()->user->create(
			[
				'user_login' => 'sub_i254api_' . uniqid(),
				'user_email' => 'sub_i254api_' . uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		\wp_set_current_user( $subscriber_id );

		$request = new \WP_REST_Request( 'GET', self::SHORTCODE_ROUTE );
		$request->set_query_params( [ 'shortcode' => '[pc_courses]' ] );
		$response = \rest_do_request( $request );

		$this->assertSame(
			403,
			$response->get_status(),
			'/power-course/shortcode 對一般用戶必須回 403'
		);
	}

	/**
	 * @test
	 * @group security
	 *
	 * Rule（D2 輸入驗證）：公開端點的 orderby 不可讓任意字串流進查詢，
	 * 非白名單值一律退回預設 date，且不得崩潰。
	 */
	public function test_安全_orderby非白名單值退回預設且不崩潰(): void {
		\wp_set_current_user( 0 );

		$data = $this->page_data(
			$this->request_page(
				[
					'orderby' => "meta_value); DROP TABLE wp_posts;--",
					'order'   => 'ASC); DROP TABLE wp_posts;--',
					'limit'   => 100,
					'page'    => 1,
				]
			)
		);

		// 惡意 orderby / order 應被丟棄退回預設，查詢照常運作（回全部課程）。
		$this->assertSame(
			self::ZH_COURSE_COUNT + self::OTHER_COURSE_COUNT + self::UNCATEGORIZED_COURSE_COUNT,
			$data['total'],
			'非白名單 orderby/order 應退回預設值，查詢仍正常運作'
		);

		// wp_posts 必須還在（確認沒有被 SQL injection 幹掉）。
		global $wpdb;
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) );
		$this->assertSame( $wpdb->posts, $table_exists, 'wp_posts 資料表必須完好無損' );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * Rule（D2 輸入驗證）：公開端點的 limit 必須有上界，避免匿名訪客用 limit=99999
	 * 一次把整站商品撈進記憶體。
	 *
	 * 這支測試刻意把課程總數灌到「超過 MAX_LIMIT」——否則不論有沒有夾 limit，
	 * total_pages 都會是 1，斷言就變成永遠成立的空轉綠燈。
	 * 課程數 = 26（既有 fixture）+ 80（本測試補的）= 106 > MAX_LIMIT(100)：
	 * - 有夾住   → posts_per_page=100 → total_pages = ceil(106/100) = 2
	 * - 沒夾住   → posts_per_page=99999 → total_pages = 1
	 * 兩者可區分，斷言才有訊號。
	 */
	public function test_邊緣_超大limit必須被夾到MAX_LIMIT(): void {
		$base_total = self::ZH_COURSE_COUNT + self::OTHER_COURSE_COUNT + self::UNCATEGORIZED_COURSE_COUNT;
		$extra      = Shortcodes::MAX_LIMIT + 6 - $base_total; // 補到 106 門課

		for ( $i = 0; $i < $extra; $i++ ) {
			$this->create_course( [ 'post_title' => '補量課程 ' . ( $i + 1 ) ] );
		}

		\wp_set_current_user( 0 );

		$data = $this->page_data(
			$this->request_page(
				[
					'limit' => 99999,
					'page'  => 1,
				]
			)
		);

		$this->assertSame( Shortcodes::MAX_LIMIT + 6, $data['total'], '課程總數應為 106' );
		$this->assertSame(
			2,
			$data['total_pages'],
			'limit 應被夾到 MAX_LIMIT(100) → 106 門課分成 2 頁；若得到 1 頁代表 limit 沒有上界'
		);
	}
}
