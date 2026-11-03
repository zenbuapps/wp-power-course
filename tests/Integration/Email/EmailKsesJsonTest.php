<?php
/**
 * Email 編輯器 JSON 經過 kses 的損毀回歸測試（Issue #290）
 *
 * WordPress 對「沒有 unfiltered_html 的目前使用者」（含未登入的排程 / CLI 情境）會在
 * excerpt_save_pre 掛 wp_filter_post_kses。kses 把 post_excerpt 當 HTML 處理，
 * wp_kses_split2 內的 wp_kses_stripslashes 會把 HTML 標籤內的 \" 改成 "，
 * 讓 JSON 字串提前結束 → 整份編輯器內容無法解析。
 *
 * 寫入前的 JsonString::normalize 救不了這件事：它跑在 wp_update_post 之前，而 kses 跑在之內。
 *
 * @group email
 * @group json-content
 */

declare( strict_types=1 );

namespace Tests\Integration\Email;

use Tests\Integration\TestCase;
use J7\PowerCourse\PowerEmail\Resources\Email\Api as EmailApi;
use J7\PowerCourse\PowerEmail\Resources\Email\CPT;

/**
 * Class EmailKsesJsonTest
 *
 * @group json-content
 */
class EmailKsesJsonTest extends TestCase {

	/** @var int 測試用郵件模板 ID */
	private int $email_id;

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/** 每個測試前建立模板，並切換成沒有 unfiltered_html 的管理員 */
	public function set_up(): void {
		parent::set_up();

		// 等同 DISALLOW_UNFILTERED_HTML / 多站台非超級管理員：map_meta_cap 回 do_not_allow
		\add_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10, 2 );

		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		// set_current_user 會觸發 kses_init，這裡才會真的把 kses 掛上去
		\wp_set_current_user( $admin_id );

		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程開通信_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);

		$this->ids['EmailTemplate'] = $this->email_id;
	}

	/** 還原全域 filter 與 kses 狀態，避免污染其他測試 */
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

	/**
	 * 直接寫入 DB，模擬已經存在的內容（繞過 kses）
	 *
	 * @param string $json 內容
	 */
	private function seed_excerpt( string $json ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $json ], [ 'ID' => $this->email_id ] );
		\clean_post_cache( $this->email_id );
	}

	/** @return string DB 內的 post_excerpt 原始位元組 */
	private function db_excerpt(): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $this->email_id )
		);
	}

	/**
	 * 呼叫正式的更新 callback
	 *
	 * @param array<string, mixed> $body_params 請求 body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_update( array $body_params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/power-email/emails/' . $this->email_id );
		$request->set_url_params( [ 'id' => (string) $this->email_id ] );
		$request->set_body_params( $body_params );

		return EmailApi::instance()->post_emails_with_id_callback( $request );
	}

	/**
	 * @return array<string, array{0: string}> 文字區塊內容（Issue #290 要求的 roundtrip 組合）
	 */
	public static function text_block_provider(): array {
		return [
			'雙引號 style 屬性' => [ '<div style="text-align: right">署名</div>' ],
			'多個屬性'        => [ '<span style="word-spacing: normal" class="x">a</span><br />b' ],
			'單引號'         => [ "<div style=\"color: red\">it's 中文</div>" ],
			'反斜線'         => [ '<p style="color: red">C:\\path\\to\\file</p>' ],
			'換行'          => [ "<div style=\"color: red\">line1\nline2</div>" ],
			'標籤外雙引號'      => [ '<p>他說 "你好" 然後離開</p>' ],
			'純中文'         => [ '繁體中文內容，沒有任何標籤' ],
		];
	}

	/**
	 * @test
	 * @dataProvider text_block_provider
	 * @group security
	 * Rule: 沒有 unfiltered_html 的使用者儲存後，post_excerpt 仍必須是合法 JSON，且文字內容不變
	 *
	 * @param string $content 文字區塊內容
	 */
	public function test_沒有unfiltered_html時儲存內容仍為合法JSON( string $content ): void {
		$json = (string) \wp_json_encode(
			[
				'type'     => 'page',
				'children' => [ [ 'type' => 'advanced_text', 'data' => [ 'value' => [ 'content' => $content ] ] ] ],
			],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$response = $this->call_update( [ 'short_description' => $json ] );
		$this->assertNotInstanceOf( \WP_Error::class, $response );

		$decoded = json_decode( $this->db_excerpt(), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), '儲存後必須是合法 JSON，實際：' . $this->db_excerpt() );
		$this->assertSame( $content, $decoded['children'][0]['data']['value']['content'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 單引號屬性是否被正規化成雙引號依 WordPress 版本而異（語意相同），JSON 都要合法
	 */
	public function test_單引號屬性被正規化後仍為合法JSON(): void {
		$json = (string) \wp_json_encode( [ 'content' => "<div style='color: red'>中文</div>" ], JSON_UNESCAPED_UNICODE );

		$this->call_update( [ 'short_description' => $json ] );

		$decoded = json_decode( $this->db_excerpt(), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), '實際：' . $this->db_excerpt() );
		$this->assertMatchesRegularExpression( '/^<div style=["\']color: red["\']>中文<\/div>$/u', $decoded['content'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 只改標題 / 狀態的部分更新不帶內容，但 wp_update_post 會把既有內容重新走過一次 filter，
	 * 不得因此把既有的合法 JSON 弄壞
	 */
	public function test_部分更新不得弄壞既有JSON(): void {
		$json = '{"type":"page","data":{"value":{"content":"<div style=\"text-align: right\">署名</div>"}}}';
		$this->seed_excerpt( $json );

		$response = $this->call_update( [ 'name' => '只改標題' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $json, $this->db_excerpt() );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 排程 / CLI 等沒有目前使用者的情境（kses 也會掛上）移到垃圾桶不得弄壞內容
	 */
	public function test_未登入情境移到垃圾桶不得弄壞既有JSON(): void {
		$json = '{"type":"page","data":{"value":{"content":"<div style=\"text-align: right\">署名</div>"}}}';
		$this->seed_excerpt( $json );

		\wp_set_current_user( 0 );
		\wp_trash_post( $this->email_id );

		$this->assertSame( $json, $this->db_excerpt() );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 修正不能變成資安破口——kses 仍要對 JSON 內的 HTML 生效，只是不能弄壞 JSON
	 */
	public function test_沒有unfiltered_html時危險標籤仍被移除(): void {
		$json = (string) \wp_json_encode(
			[ 'content' => '<div style="color: red">安全</div><script>alert(1)</script><img src="x" onerror="alert(2)">' ],
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		$this->call_update( [ 'short_description' => $json ] );

		$decoded = json_decode( $this->db_excerpt(), true );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), '儲存後必須是合法 JSON，實際：' . $this->db_excerpt() );
		$this->assertStringNotContainsString( '<script', $decoded['content'] );
		$this->assertStringNotContainsString( 'onerror', $decoded['content'] );
		$this->assertStringContainsString( 'style="color: red"', $decoded['content'] );
	}

	/**
	 * @return array<string, array{0: string}> 把 HTML 放在 key 的各種寫法（審查抓到的繞過）
	 */
	public static function dangerous_key_provider(): array {
		return [
			'頂層 key'       => [ '{"<img src=x onerror=alert(1)>":"v","content":"ok"}' ],
			'巢狀 key'       => [ '{"attributes":{"<img src=x onerror=alert(1)>":"v"}}' ],
			'陣列內物件的 key'   => [ '{"children":[{"attributes":{"<img src=x onerror=alert(1)>":"v"}}]}' ],
			'\u 編碼的 key'  => [ '{"\u003cimg src=x onerror=alert(1)\u003e":"v"}' ],
			'script 標籤 key' => [ '{"<script>alert(1)</script>":"v"}' ],
		];
	}

	/**
	 * @test
	 * @dataProvider dangerous_key_provider
	 * @group security
	 * Rule: JSON 的 key 也要過濾。easy-email 會把 attributes 的 key 直接拼進 MJML 標籤，
	 * 且對整份字串跑 kses 時 key 也會被過濾，不能因為逐字串處理而漏掉
	 *
	 * @param string $json 內容
	 */
	public function test_key內的HTML也要過濾( string $json ): void {
		$this->call_update( [ 'short_description' => $json ] );

		$db = $this->db_excerpt();
		json_decode( $db );
		$this->assertSame( JSON_ERROR_NONE, json_last_error(), '必須是合法 JSON，實際：' . $db );
		$this->assertStringNotContainsString( 'onerror', $db );
		$this->assertStringNotContainsString( '<script', $db );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 一般 key 維持原樣與原順序，空物件不變
	 */
	public function test_過濾key時保留其他key與順序(): void {
		$json = '{"z":"1","<img src=x onerror=alert(1)>":"2","a":{},"padding":"10px"}';

		$this->call_update( [ 'short_description' => $json ] );

		$decoded = json_decode( $this->db_excerpt() );
		$keys    = array_keys( get_object_vars( $decoded ) );
		$this->assertSame( 'z', $keys[0] );
		$this->assertSame( 'padding', $keys[3] );
		$this->assertStringContainsString( '"a":{}', $this->db_excerpt() );
		$this->assertSame( '1', $decoded->z );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 沒有 HTML 需要過濾時，內容逐 byte 不變（PHP 不碰結構：空物件、數字格式都不能變）
	 */
	public function test_沒有危險內容時逐byte不變(): void {
		$json = '{"attributes":{},"list":[],"width":600.0,"content":"<div style=\"color: red\">署名</div>","url":"https://x.test/?a=1&b=2"}';

		$this->call_update( [ 'short_description' => $json ] );

		$this->assertSame( $json, $this->db_excerpt() );
	}

	/**
	 * @test
	 * @group security
	 * Rule: kses 真的過濾掉東西、必須重新編碼時，結構仍要保留（空物件不得變成空陣列）
	 */
	public function test_重新編碼時保留空物件與未過濾的字串(): void {
		$json = '{"attributes":{},"content":"<div>安全</div><script>alert(1)</script>","url":"https://x.test/?a=1&b=2"}';

		$this->call_update( [ 'short_description' => $json ] );

		$db = $this->db_excerpt();
		$this->assertStringContainsString( '"attributes":{}', $db );
		$this->assertStringContainsString( '"url":"https://x.test/?a=1&b=2"', $db, '沒有標籤的字串不得被 kses 轉成 &amp;' );
		$this->assertStringNotContainsString( '<script', $db );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 有 unfiltered_html 的使用者完全不介入，內容逐 byte 保留
	 */
	public function test_有unfiltered_html時完全不介入(): void {
		\remove_filter( 'map_meta_cap', [ $this, 'deny_unfiltered_html' ], 10 );
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$json = '{"content":"<div style=\"color: red\">a&b</div><script>alert(1)</script>"}';
		$this->call_update( [ 'short_description' => $json ] );

		$this->assertSame( $json, $this->db_excerpt() );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 其他文章類型的 post_excerpt 不受影響（kses 照常作用於整個欄位）
	 */
	public function test_其他文章類型不受影響(): void {
		$post_id = $this->factory()->post->create( [ 'post_type' => 'post' ] );
		\wp_update_post(
			[
				'ID'           => $post_id,
				// 刻意用 JSON 形狀：非 JSON 的摘要本來就不會被 guard 動到，測不出「不分文章類型」
				'post_excerpt' => \wp_slash( '{"content":"<div style=\"color: red\">摘要</div><script>alert(1)</script>"}' ),
			]
		);

		// 維持 WordPress 原本對整個欄位的 kses 結果
		$this->assertSame( '{"content":"<div style="color: red">摘要</div>alert(1)"}', \get_post( $post_id )->post_excerpt );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 複製模板（Utils\Duplicate）同樣不得弄壞內容
	 */
	public function test_複製模板後內容仍為合法JSON(): void {
		$json = '{"content":"<div style=\"text-align: right\">署名</div>"}';
		$this->seed_excerpt( $json );

		$new_id = \J7\PowerCourse\Utils\Duplicate::duplicate_post( $this->email_id );

		$this->assertSame( $json, \get_post( $new_id )->post_excerpt );
	}
}
