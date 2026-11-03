<?php
/**
 * Email 寄送 HTML 與網址欄位經過 kses 的回歸測試（Issue #290 同類問題）
 *
 * post_content 是 MJML 產生的完整文件。沒有 unfiltered_html 時，wp_kses_post 會移除 html / head / body / style，
 * CSS 變成信裡看得到的純文字，Outlook 條件註解也會壞掉。
 *
 * fixtures/mjml-all-blocks.html 是專案 mjml-browser 的真實輸出，涵蓋 easy-email 的所有區塊。
 *
 * @group email
 * @group json-content
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;
use J7\PowerCourse\PowerEmail\Resources\Email\EditorJsonGuard;
use J7\PowerCourse\PowerEmail\Resources\Email\HtmlSanitizer;
use J7\PowerCourse\PowerEmail\Resources\Email\JsonRepair;

/**
 * Class EmailKsesHtmlTest
 *
 * @group json-content
 */
class EmailKsesHtmlTest extends TestCase {

	/** @var int 測試用郵件模板 ID */
	private int $email_id;

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/** 每個測試前建立模板，並切換成沒有 unfiltered_html 的管理員 */
	public function set_up(): void {
		parent::set_up();

		\add_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10, 2 );

		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程開通信_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);
	}

	/** 還原全域 filter 與 kses 狀態 */
	public function tear_down(): void {
		\remove_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10 );
		\wp_set_current_user( 0 );
		\kses_remove_filters();
		parent::tear_down();
	}

	/**
	 * 拒絕 unfiltered_html
	 *
	 * @param array<string> $caps 必要權限
	 * @param string        $cap  檢查中的權限
	 * @return array<string>
	 */
	public function deny_unfiltered_html( array $caps, string $cap ): array {
		return 'unfiltered_html' === $cap ? [ 'do_not_allow' ] : $caps;
	}

	/** @return string 真實的 MJML 輸出 */
	private function fixture(): string {
		return (string) \file_get_contents( __DIR__ . '/fixtures/mjml-all-blocks.html' );
	}

	/**
	 * 把 HTML 轉成可比對的形式：只忽略標籤內的空白、行內樣式的分號與空的 style 屬性，其他內容逐字比對
	 *
	 * @param string $html HTML
	 * @return string
	 */
	private function normalize( string $html ): string {
		$html = (string) \preg_replace_callback(
			'/style="([^"]*)"/',
			static function ( array $match ): string {
				$declarations = \array_filter( \array_map( static fn( string $d ): string => (string) \preg_replace( '/\s*:\s*/', ':', \trim( $d ), 1 ), \explode( ';', $match[1] ) ) );
				return [] === $declarations ? '' : 'style="' . \str_replace( [ '&#039;', "'" ], "'", \implode( ';', $declarations ) ) . '"';
			},
			$html
		);

		return (string) \preg_replace_callback(
			'/<[a-zA-Z][^<>]*>/',
			static fn( array $match ): string => (string) \preg_replace( [ '/\s+/', '/\s(\/?)>$/' ], [ ' ', '$1>' ], $match[0] ),
			$html
		);
	}

	/** @return string DB 內的 post_content */
	private function db_content(): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $this->email_id ) );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider fixture_provider
	 *
	 * @param string $file fixture 檔名
	 */
	public function test_MJML真實輸出過濾後內容完全相同( string $file ): void {
		$html = (string) \file_get_contents( __DIR__ . '/fixtures/' . $file );

		$this->assertSame( $this->normalize( $html ), $this->normalize( HtmlSanitizer::sanitize( $html ) ) );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider fixture_provider
	 *
	 * @param string $file fixture 檔名
	 */
	public function test_過濾結果可重複過濾( string $file ): void {
		$once = HtmlSanitizer::sanitize( (string) \file_get_contents( __DIR__ . '/fixtures/' . $file ) );

		$this->assertSame( $once, HtmlSanitizer::sanitize( $once ) );
	}

	/**
	 * 正式存檔用的是壓縮版（Emails/Edit 的 minify: true）
	 *
	 * @return array<string, array{string}>
	 */
	public function fixture_provider(): array {
		return [
			'未壓縮' => [ 'mjml-all-blocks.html' ],
			'壓縮'  => [ 'mjml-all-blocks.min.html' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_沒有unfiltered_html時存檔保留完整文件(): void {
		$result = \wp_update_post(
			\wp_slash(
				[
					'ID'           => $this->email_id,
					'post_content' => $this->fixture(),
				]
			),
			true
		);

		$this->assertSame( $this->email_id, $result );
		$content = $this->db_content();
		$this->assertStringContainsString( '<html', $content );
		$this->assertMatchesRegularExpression( '/<style type="text\/css">\s*#outlook a/', $content );
		$this->assertStringContainsString( '<!--[if mso | IE]>', $content );
		$this->assertStringContainsString( '<v:rect', $content );
		$this->assertFalse( HtmlSanitizer::is_damaged( $content ) );
		$this->assertStringNotContainsString( '&lt;!--', $content );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_只改標題或移到垃圾桶不會弄壞既有文件(): void {
		global $wpdb;
		$fixture = $this->fixture();
		$wpdb->update( $wpdb->posts, [ 'post_content' => $fixture ], [ 'ID' => $this->email_id ] );
		\clean_post_cache( $this->email_id );

		\wp_update_post(
			[
				'ID'         => $this->email_id,
				'post_title' => '新標題',
			]
		);
		$this->assertSame( $this->normalize( $fixture ), $this->normalize( $this->db_content() ) );

		\wp_trash_post( $this->email_id );
		$this->assertSame( $this->normalize( $fixture ), $this->normalize( $this->db_content() ) );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_其他文章類型維持原本的kses(): void {
		$post_id = $this->factory()->post->create( [ 'post_content' => '<html><style>a{}</style><p>x</p></html>' ] );

		$this->assertStringNotContainsString( '<style', (string) \get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_條件註解內的CSS不能提早結束註解(): void {
		$this->assertSame( '', HtmlSanitizer::sanitize( '<!--[if mso]><style>a{}--></style><![endif]-->' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_有unfiltered_html時不過濾寄送HTML(): void {
		\remove_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10 );
		\kses_remove_filters();

		$html = '<html><body><script>a()</script></body></html>';
		\wp_update_post(
			\wp_slash(
				[
					'ID'           => $this->email_id,
					'post_content' => $html,
				]
			)
		);

		$this->assertSame( $html, $this->db_content() );
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider unsafe_html_provider
	 *
	 * @param string $html      惡意內容
	 * @param string $forbidden 過濾後不得出現的字串
	 */
	public function test_危險內容會被移除( string $html, string $forbidden ): void {
		$this->assertStringNotContainsStringIgnoringCase( $forbidden, (string) HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function unsafe_html_provider(): array {
		return [
			'script'                  => [ '<p>a</p><script>alert(1)</script>', '<script' ],
			'事件屬性'                    => [ '<img src="https://a/a.png" onerror="alert(1)">', 'onerror' ],
			'javascript 網址'           => [ '<a href="javascript:alert(1)">x</a>', 'javascript:' ],
			'iframe'                  => [ '<iframe src="https://evil.example"></iframe>', '<iframe' ],
			'meta refresh'            => [ '<meta http-equiv="refresh" content="0;url=https://evil.example">', 'refresh' ],
			'base'                    => [ '<base href="https://evil.example">', '<base' ],
			'input 其他類型'              => [ '<input type="text" name="password">', 'password' ],
			'form'                    => [ '<form action="https://evil.example"><button>x</button></form>', 'evil.example' ],
			'link 非樣式表'               => [ '<link rel="import" href="https://evil.example/a.html">', 'rel=' ],
			'條件註解內的 script'           => [ '<!--[if mso]><script>alert(1)</script><![endif]-->', '<script' ],
			'條件註解內的事件屬性'              => [ '<!--[if mso]><v:rect onclick="alert(1)"></v:rect><![endif]-->', 'onclick' ],
			'條件註解提早結束'                => [ '<!--[if mso]>--><img src=x onerror=alert(1)><!--<![endif]-->', 'onerror' ],
			'條件註解結尾跳出'                => [ '<!--[if mso]><style>--></style><img src=x onerror=alert(1)><![endif]-->', 'onerror' ],
			'一般內容不允許 Outlook 標籤'      => [ '<v-rect src="https://a/a.png"></v-rect>', '<v-rect' ],
			'一般註解藏標籤'                 => [ '<!-- <script>alert(1)</script> -->', 'alert' ],
			'style 跳出標籤'              => [ '<style></style><script>alert(1)</script>', '<script' ],
			'style 內含標籤'              => [ '<style>a{}</style ><style><img src=x onerror=alert(1)></style>', 'onerror' ],
			'css expression'          => [ '<style>a{width:expression(alert(1))}</style>', 'expression' ],
			'css 註解藏關鍵字'              => [ '<style>a{background:url(java/**/script:alert(1))}</style>', 'script:' ],
			'css 跳脫藏關鍵字'              => [ '<style>a{width:\65xpression(alert(1))}</style>', 'xpression' ],
			'css behavior'            => [ '<style>a{behavior:url(https://a/x.htc)}</style>', 'behavior' ],
			'css moz-binding'         => [ '<style>a{-moz-binding:url(https://a/x.xml)}</style>', 'binding' ],
			'css data 網址'             => [ '<style>a{background:url(data:text/html,x)}</style>', 'data:' ],
			'style 其他屬性'              => [ '<style onload="alert(1)">a{}</style>', 'onload' ],
			'佔位字串跑進屬性'                => [ '<img alt="<!--[if mso]><a title=&quot;x onerror=alert(1) y&quot;><![endif]-->" src="https://a/a.png">', 'onerror=alert(1)' ],
			'佔位字串跑進屬性 2'              => [ '<img alt="<!--[if mso]><a title="x onerror=alert(1) y"><![endif]-->" src="https://a/a.png">', 'onerror=alert(1)' ],
			'style 跑進屬性'              => [ '<img alt="<style>a{}</style>" src="https://a/a.png" onerror="alert(1)">', 'onerror' ],
			'行內樣式 javascript'         => [ '<div style="background:url(javascript:alert(1))">x</div>', 'javascript' ],
			'行內樣式未知屬性'                => [ '<div style="-moz-binding:url(https://a/x.xml)">x</div>', 'binding' ],
			'行內樣式跳出規則'                => [ '<div style="color:red}body{display:none">x</div>', 'body{' ],
			'未加引號的 href'              => [ '<a href=javascript:alert(1)>x</a>', 'javascript' ],
			'未加引號的屬性不放行'              => [ '<a href=https://ok.example/>x</a>', 'ok.example' ],
			'實體編碼的 href'              => [ '<a href="java&#115;cript:alert(1)">x</a>', 'cript' ],
			'雙重實體的 href'              => [ '<a href="javascript&amp;colon;alert(1)">x</a>', 'cript' ],
			'meta 改編碼'                => [ '<meta http-equiv="Content-Type" content="text/html; charset=utf-7">', 'utf-7' ],
			'meta charset'            => [ '<meta charset="utf-7">', 'utf-7' ],
			'math'                    => [ '<math><mi>x</mi></math>', '<math' ],
			'svg'                     => [ '<svg onload="alert(1)"></svg>', '<svg' ],
			'textarea'                => [ '<textarea>x</textarea>', '<textarea' ],
		];
	}

	/**
	 * 這些輸入曾經能繞過過濾；只比對字串看不出來，要用瀏覽器的解析方式檢查 DOM
	 *
	 * @test
	 * @group security
	 * @dataProvider parsed_bypass_provider
	 *
	 * @param string $html 惡意內容
	 */
	public function test_繞過手法解析後沒有可執行內容( string $html ): void {
		$this->assert_no_executable_content( (string) HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * 用瀏覽器的解析方式逐標籤檢查：字串比對會被屬性值裡的內容騙過
	 *
	 * @param string $html HTML
	 */
	private function assert_no_executable_content( string $html ): void {
		// 惡意內容整個被移除時沒有標籤可檢查，這也是通過
		$this->addToAssertionCount( 1 );

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = (string) $processor->get_tag();
			$this->assertNotContains( $tag, [ 'SCRIPT', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'MATH', 'TEXTAREA' ], $html );

			foreach ( (array) $processor->get_attribute_names_with_prefix( '' ) as $name ) {
				$this->assertStringStartsNotWith( 'on', (string) $name, "{$tag}[{$name}]: {$html}" );
			}

			foreach ( [ 'href', 'src', 'background' ] as $name ) {
				$value = $processor->get_attribute( $name );
				if ( \is_string( $value ) ) {
					$this->assertStringNotContainsStringIgnoringCase( 'script:', $value, $html );
				}
			}
		}
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_輸出檢查會擋下不該出現的內容(): void {
		$method = new \ReflectionMethod( HtmlSanitizer::class, 'assert_allowed' );
		$method->setAccessible( true );

		foreach ( [ '<img onerror="alert(1)">', '<iframe></iframe>', '<a href="javascript:alert(1)">x</a>', '<img alt="x" src="data:text/html,x">', '<style onload="a()"></style>', '<style style="a:b"></style>' ] as $html ) {
			// 不能把 fail() 包在 catch RuntimeException 裡：PHPUnit 的失敗例外也繼承它，會被吞掉
			$blocked = false;
			try {
				$method->invoke( null, $html, [ 'img' => [ 'alt' => true ], 'a' => [] ] );
			} catch ( \RuntimeException $e ) {
				$blocked = true;
			}
			$this->assertTrue( $blocked, "應該要擋下：{$html}" );
		}

		$method->invoke( null, '<a href="https://a.example" data-x="1" aria-label="y" style="color:red">x</a><style type="text/css">a{}</style>', [ 'a' => [] ] );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_過濾後仍有不該出現的內容時拒絕處理(): void {
		// 模擬前面的步驟失守：style 開始標籤被弄髒，事件屬性混進輸出
		$dirty = static fn(): string => 'print" onload="alert(1)';

		\add_filter( 'attribute_escape', $dirty );
		$clean = HtmlSanitizer::sanitize( '<style media="print">a{}</style>' );
		\remove_filter( 'attribute_escape', $dirty );

		$this->assertNull( $clean );
	}

	/**
	 * 每一層防線都要自己處理好，不能靠最後的輸出檢查拒存，所以這裡比對精確結果
	 *
	 * @test
	 * @group security
	 * @dataProvider exact_output_provider
	 *
	 * @param string $html     輸入
	 * @param string $expected 輸出
	 */
	public function test_危險內容的精確過濾結果( string $html, string $expected ): void {
		$this->assertSame( $expected, HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public function exact_output_provider(): array {
		return [
			'危險網址只移除屬性'     => [ '<a href="javascript:alert(1)">x</a>', '<a>x</a>' ],
			'style 只留可用屬性'  => [ '<style onload="alert(1)" media="print">a{}</style>', '<style media="print">a{}</style>' ],
			'條件註解藏進屬性值'     => [ '<img src="<!--[if mso]><a title=" onerror=alert(1) x="><![endif]-->">', '<img>' ],
			'style 區塊藏進屬性值' => [ '<img src="<style>a{}</style>">', '<img>' ],
		];
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_佔位字串跑進別的屬性時移除條件註解(): void {
		$clean = (string) HtmlSanitizer::sanitize( '<img alt="<!--[if mso]><a title=&quot;x&quot;><![endif]-->" src="https://a.example/a.png">' );

		$this->assertStringContainsString( 'src="https://a.example/a.png"', $clean );
		$this->assertStringNotContainsString( '[if mso]', $clean );
		$this->assertStringNotContainsString( 'pcemail', $clean );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_佔位字串不會留在信裡(): void {
		$clean = (string) HtmlSanitizer::sanitize( "<img alt=' src=\"https://a.example/a.png\" y='>" );

		$this->assertStringNotContainsString( 'pcemail', $clean );
		$this->assertStringNotContainsString( 'data-pc-', $clean );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_過大的內容拒絕處理且不覆寫原內容(): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_content' => '<p>原內容</p>' ], [ 'ID' => $this->email_id ] );
		\clean_post_cache( $this->email_id );

		$too_large = '<p>' . \str_repeat( 'a', 600000 ) . '</p>';
		$this->assertNull( HtmlSanitizer::sanitize( $too_large ) );

		\wp_update_post(
			\wp_slash(
				[
					'ID'           => $this->email_id,
					'post_content' => $too_large,
				]
			)
		);
		$this->assertSame( '<p>原內容</p>', $this->db_content() );

		$request = new \WP_REST_Request( 'POST', '/power-email/emails/' . $this->email_id );
		$request->set_url_params( [ 'id' => (string) $this->email_id ] );
		$request->set_body_params( [ 'description' => $too_large ] );
		$response = \J7\PowerCourse\PowerEmail\Resources\Email\Api::instance()->post_emails_with_id_callback( $request );

		$this->assertWPError( $response );
		$this->assertSame( 'invalid_email_html', $response->get_error_code() );
		$this->assertSame( '<p>原內容</p>', $this->db_content() );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_超長網址視為不安全(): void {
		$this->assertFalse( HtmlSanitizer::is_safe_url( 'javascript:alert(1)//{{' . \str_repeat( 'a', 1100000 ) ) );
		$this->assertFalse( HtmlSanitizer::is_safe_url( 'https://a.example/' . \str_repeat( 'a', 9000 ) ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_JSON內容欄位保留雙引號(): void {
		$json = '{"data":{"value":{"content":"<p>他說 \"你好\"</p>"}}}';

		$this->assertSame( $json, EditorJsonGuard::sanitize_json( $json ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function parsed_bypass_provider(): array {
		return [
			'CSS 註解藏結束標籤'   => [ '<style>/*</style x><img src=x onerror=alert(1)>*/</style>' ],
			'CSS 註解藏斜線結束標籤' => [ '<style>/*</style/><img src=x onerror=alert(1)>*/</style>' ],
			'CSS 註解藏大寫結束標籤' => [ "<style>/*</STYLE\n foo=bar><script>alert(1)</script>*/</style>" ],
			'math 內的 style'  => [ '<math><style>/*<img src=x onerror=alert(1)>*/</style></math>' ],
			'title 內的 style' => [ '<title><style>/*</title><img src=x onerror=alert(1)>*/</style></title>' ],
			'textarea 內的 style' => [ '<textarea><style>/*</textarea><img src=x onerror=alert(1)>*/</style></textarea>' ],
			'單引號屬性藏 style'   => [ "<img src=\"https://a/a.png\" alt=' style=\"&#039; onerror=alert(1) x=\"z\" '>" ],
			'title 屬性藏 style' => [ "<a title=' style=\"&#039; onmouseover=alert(1) x=\"z\" '>x</a>" ],
			'跨標籤藏 style'     => [ "<p> style=\"</p><img alt='&#039; onerror=alert(1) ' src=x>" ],
			'單引號屬性藏 href'    => [ "<img src=\"https://a/a.png\" alt=' href=\"&#039; onerror=alert(1) x=\"z\" '>" ],
			'行內樣式藏單引號'      => [ "<img alt=' style=\"color:&#039; onerror=alert(1) x=&#039;\" '>" ],
			'條件註解藏進 src'     => [ '<img src="<!--[if mso]><a title=" onerror=alert(1) x="><![endif]-->">' ],
			'條件註解藏進 href'    => [ '<a href="<!--[if mso]><a title=" onmouseover=alert(1) x="><![endif]-->">x</a>' ],
			'條件註解藏進 style'   => [ '<div style="<!--[if mso]><a title=" onclick=alert(1) x="><![endif]-->">x</div>' ],
			'條件註解藏進 background' => [ '<table background="<!--[if mso]><a title=" onclick=alert(1) x="><![endif]-->"></table>' ],
			'style 區塊藏進 src'  => [ '<img src="<style media="x onerror=alert(1) y"></style>">' ],
			'單引號條件註解藏進 src'  => [ "<img src='<!--[if mso]><a title=\"&#039; onerror=alert(1) x=&#039;\"><![endif]-->'>" ],
			'link 藏條件註解'     => [ '<link href="<!--[if mso]><a title=" onload=alert(1) x="><![endif]-->">' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider safe_html_url_provider
	 *
	 * @param string $url 合法網址
	 */
	public function test_HTML合法網址不被破壞( string $url ): void {
		$this->assertSame( '<a href="' . $url . '">x</a>', HtmlSanitizer::sanitize( '<a href="' . $url . '">x</a>' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function safe_html_url_provider(): array {
		return [
			'變數'       => [ '{{course.permalink}}' ],
			'變數加時間參數'  => [ '{{course.permalink}}?t=12:30' ],
			'變數加連接埠'   => [ '{{site.url}}:8080/x' ],
			'相對路徑加冒號'  => [ '/classroom?t=12:30' ],
			'圖片附件'     => [ 'cid:logo123' ],
			'LINE'     => [ 'line://ti/p/abc' ],
			'編碼的 & 符號' => [ 'https://a.example/?x=1&amp;y=2' ],
		];
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_背景網址含連接符號時保留宣告(): void {
		$clean = HtmlSanitizer::sanitize( '<td style="background:url(\'https://a.example/a.png?x=1&amp;y=2\') no-repeat;color:red">x</td>' );

		$this->assertStringContainsString( 'background:url(&#039;https://a.example/a.png?x=1&amp;y=2&#039;) no-repeat;color:red', $clean );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_正則失敗時拒絕處理而不是清空或放行(): void {
		$limit = (int) \ini_get( 'pcre.backtrack_limit' );
		\ini_set( 'pcre.backtrack_limit', '1000' ); // phpcs:ignore WordPress.PHP.IniSet.Risky

		try {
			$clean = HtmlSanitizer::sanitize( '<p>留下來</p><style>' . \str_repeat( 'a', 5000 ) . '<iframe src="https://evil.example"></iframe>' );
		} finally {
			\ini_set( 'pcre.backtrack_limit', (string) $limit ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		}

		$this->assertNull( $clean );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_一般key不受字元限制(): void {
		$json = '{"data":{"value":{"中文key":"值","font-family":"Arial"}},"attributes":{"color":"red"}}';

		$this->assertSame( $json, EditorJsonGuard::sanitize_json( $json ) );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider safe_url_provider
	 *
	 * @param string $url 合法網址
	 */
	public function test_JSON合法網址逐字不變( string $url ): void {
		$json = (string) \wp_json_encode( [ 'attributes' => [ 'href' => $url ] ], JSON_UNESCAPED_SLASHES );

		$this->assertSame( $json, EditorJsonGuard::sanitize_json( $json ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function safe_url_provider(): array {
		return [
			'https 與參數' => [ 'https://example.com/?a=1&b=2:3' ],
			'前導空白'      => [ ' https://example.com/' ],
			'變數'        => [ '{{course.permalink}}' ],
			'變數加路徑'     => [ '{{site.url}}/classroom' ],
			'變數加時間'     => [ '{{course.permalink}}?t=12:30' ],
			'變數加連接埠'    => [ '{{site.url}}:8080/x' ],
			'路徑含冒號'     => [ '/a:b' ],
			'查詢含冒號'     => [ '/classroom?t=12:30' ],
			'LINE'      => [ 'line://ti/p/abc' ],
			'圖片附件'      => [ 'cid:logo' ],
			'mailto'    => [ 'mailto:a@example.com' ],
			'tel'       => [ 'tel:+886212345678' ],
			'相對路徑'      => [ '/my-account/' ],
			'錨點'        => [ '#top' ],
			'空字串'       => [ '' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_有unfiltered_html時不過濾網址(): void {
		\remove_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10 );
		\kses_remove_filters();

		$json = '{"attributes":{"href":"javascript:alert(1)"}}';
		\wp_update_post(
			\wp_slash(
				[
					'ID'           => $this->email_id,
					'post_excerpt' => $json,
				]
			)
		);

		$this->assertSame( $json, \get_post_field( 'post_excerpt', $this->email_id ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_健康檢查列出寄送HTML已損壞的模板(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_content' => \wp_kses_post( $this->fixture() ),
				'post_excerpt' => '{"type":"page"}',
			],
			[ 'ID' => $this->email_id ]
		);

		$items = \array_column( JsonRepair::scan(), null, 'id' );

		$this->assertSame( JsonRepair::STATE_HTML_DAMAGED, $items[ (string) $this->email_id ]['state'] );
		$this->assertSame( [], $items[ (string) $this->email_id ]['changes'] );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider healthy_html_provider
	 *
	 * @param string $html 正常的內容
	 */
	public function test_健康檢查不誤判正常內容( string $html ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_content' => $html,
				'post_excerpt' => '{"type":"page"}',
			],
			[ 'ID' => $this->email_id ]
		);

		$this->assertArrayNotHasKey( (string) $this->email_id, \array_column( JsonRepair::scan(), null, 'id' ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function healthy_html_provider(): array {
		return [
			'空內容'    => [ '' ],
			'手寫片段'   => [ '<p>您好</p>' ],
			'有樣式的文件' => [ '<html><head><style>#outlook a { padding:0; }</style></head><body><div class="mj-column-per-100"></div></body></html>' ],
			'內文提到 outlook' => [ '<p>請把 #outlook a 寫進 CSS</p>' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_健康檢查不提示沒有編輯內容的模板(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			[
				'post_content' => '<div class="mj-column-per-100"></div>',
				'post_excerpt' => '',
			],
			[ 'ID' => $this->email_id ]
		);

		$this->assertArrayNotHasKey( (string) $this->email_id, \array_column( JsonRepair::scan(), null, 'id' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_註解內的style標籤不算有樣式(): void {
		$this->assertTrue( HtmlSanitizer::is_damaged( '<!-- <style> --><div class="mj-column-per-100"></div>' ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_複製模板保留內容的反斜線(): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_content' => '<p>C:\\docs</p>' ], [ 'ID' => $this->email_id ] );
		\clean_post_cache( $this->email_id );

		$new_id = ( new \J7\PowerCourse\Utils\Duplicate() )->process( $this->email_id );

		$this->assertStringContainsString( 'C:\\docs', (string) \get_post_field( 'post_content', (int) $new_id ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 新文章過大時不能把全域 $post 的內容存進信裡
	 */
	public function test_新文章無法過濾時不取全域文章內容(): void {
		global $post;
		$other = $this->factory()->post->create_and_get( [ 'post_content' => '別篇文章的內容' ] );
		$post  = $other; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$new_id = \wp_insert_post(
			[
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => '過大的信',
				'post_status'  => 'draft',
				'post_content' => \str_repeat( 'a', HtmlSanitizer::MAX_BYTES + 1 ),
			]
		);

		$post = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		$this->assertSame( '', \get_post( (int) $new_id )->post_content );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 過濾後超過上限要回 null：MCP 會再存一次，第二次失敗就變成回報成功卻沒存
	 */
	public function test_過濾後變大超過上限回傳null(): void {
		$html = \str_repeat( 'A&B ', 120000 );

		$this->assertLessThanOrEqual( HtmlSanitizer::MAX_BYTES, \strlen( $html ) );
		$this->assertNull( HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * @test
	 * @group happy
	 * @dataProvider real_variable_style_provider
	 * Rule: 實際寄送變數是單大括號（{course_image_url}），放在背景圖不能被當成 CSS 大括號刪掉
	 *
	 * @param string $style 行內樣式
	 */
	public function test_行內樣式保留實際寄送變數( string $style ): void {
		$html = '<td style="' . $style . '">x</td>';

		$this->assertStringContainsString( '{course_image_url}', (string) HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function real_variable_style_provider(): array {
		return [
			'背景圖'   => [ 'background-image:url({course_image_url})' ],
			'背景簡寫' => [ 'background:url({course_image_url}) no-repeat;color:red' ],
		];
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider css_brace_provider
	 * Rule: 放行變數不能連帶放行真正的 CSS 大括號
	 *
	 * @param string $style 行內樣式
	 */
	public function test_行內樣式不放行CSS大括號( string $style ): void {
		$html = '<td style="' . $style . '">x</td>';

		$this->assertStringNotContainsString( 'color:red', (string) HtmlSanitizer::sanitize( $html ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function css_brace_provider(): array {
		return [
			'結束規則' => [ 'color:red}' ],
			'開始規則' => [ 'color:red{' ],
			'含空白'   => [ 'color:red{ a }' ],
			'含分隔字元' => [ 'color:red{a:b}' ],
		];
	}
}
