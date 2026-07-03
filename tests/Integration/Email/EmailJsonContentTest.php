<?php
/**
 * Email JSON 內容儲存整合測試
 *
 * Feature: JSON parse error 根治（JSON_PARSE_ERROR.md）
 * 測試 post_emails_with_id_callback 對 post_excerpt（email 編輯器 JSON）的寫入守門：
 * - 部分更新（不帶 short_description）不得清空既有內容（原 bug：無條件覆寫成空字串）
 * - 含跳脫字元的 JSON 儲存後 byte 不變（wp_slash 對稱性）
 * - 非法 JSON 回傳 400、DB 內容不變
 * - 雙重編碼自癒
 * - 空字串允許清空
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
 * Class EmailJsonContentTest
 *
 * @group json-content
 */
class EmailJsonContentTest extends TestCase {

	/** @var int 測試用郵件模板 ID */
	private int $email_id;

	/** @var string 既有的合法 JSON 內容（含跳脫字元） */
	private string $existing_json;

	/** @var EmailApi API 實例 */
	private EmailApi $api;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 以管理員身分執行（權限 + unfiltered_html）
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );

		$this->existing_json = (string) \wp_json_encode(
			[
				'type' => 'page',
				'data' => [
					'value' => [ 'content' => "line1\nline2 with \"quotes\" and \\ backslash" ],
				],
			]
		);

		$this->email_id = $this->factory()->post->create(
			[
				'post_title'  => '課程開通信_' . uniqid(),
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'publish',
			]
		);
		// 直接寫入既有內容（模擬先前已正確儲存的信件）
		global $wpdb;
		$wpdb->update( $wpdb->posts, [ 'post_excerpt' => $this->existing_json ], [ 'ID' => $this->email_id ] );
		\clean_post_cache( $this->email_id );

		$this->api = EmailApi::instance();

		$this->ids['EmailTemplate'] = $this->email_id;
	}

	/**
	 * 建立模擬 REST 請求並呼叫 update callback
	 *
	 * @param array<string, mixed> $body_params 請求 body（前端欄位名，如 short_description）
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_update( array $body_params ): \WP_REST_Response|\WP_Error {
		$request = new \WP_REST_Request( 'POST', '/power-email/emails/' . $this->email_id );
		$request->set_url_params( [ 'id' => (string) $this->email_id ] );
		// REST server 會把 $_POST wp_unslash 後放進 body params，這裡直接給乾淨字串等價模擬
		$request->set_body_params( $body_params );

		return $this->api->post_emails_with_id_callback( $request );
	}

	/** @return string 目前 DB 中的 post_excerpt */
	private function get_db_excerpt(): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_excerpt FROM {$wpdb->posts} WHERE ID = %d", $this->email_id )
		);
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * API 類別可實例化且 callback 存在
	 */
	public function test_冒煙_api_callback存在(): void {
		$this->assertTrue( method_exists( $this->api, 'post_emails_with_id_callback' ) );
	}

	// ========== 部分更新不清空 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 部分更新——請求不帶 short_description 時，既有內容不得被清空
	 * （原 bug：`$data['post_excerpt'] ?? ''` 無條件覆寫，只改主旨就會把整封信內容清成空白）
	 */
	public function test_部分更新不帶內容時不清空既有內容(): void {
		$response = $this->call_update( [ 'name' => '只改標題' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame(
			$this->existing_json,
			$this->get_db_excerpt(),
			'不帶 short_description 的部分更新不得動到 post_excerpt'
		);
		$this->assertSame( '只改標題', \get_post( $this->email_id )->post_title );
	}

	// ========== 跳脫字元 round-trip ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 寫入對稱——含 \n、\"、\\ 跳脫字元的 JSON 儲存後 byte 不變
	 */
	public function test_含跳脫字元的JSON儲存後byte不變(): void {
		$new_json = (string) \wp_json_encode(
			[
				'type' => 'page',
				'data' => [ 'value' => [ 'content' => "new\nline \"q\" \\ bs \u{4e2d}\u{6587}" ] ],
			]
		);

		$response = $this->call_update( [ 'short_description' => $new_json ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $new_json, $this->get_db_excerpt(), '儲存後的 JSON 必須與送出的完全一致' );
		$this->assertNotFalse( json_decode( $this->get_db_excerpt() ), '存進 DB 的必須是合法 JSON' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 重複儲存不劣化——同一份內容存兩次，byte 仍不變（原 bug：每存一次掉一層反斜線）
	 */
	public function test_重複儲存不劣化(): void {
		$json = (string) \wp_json_encode( [ 'content' => "a\\b\nc" ] );

		$this->call_update( [ 'short_description' => $json ] );
		$first = $this->get_db_excerpt();
		$this->call_update( [ 'short_description' => $first ] );
		$second = $this->get_db_excerpt();

		$this->assertSame( $json, $first );
		$this->assertSame( $first, $second, '重複儲存同一內容不得再遺失任何字元' );
	}

	// ========== 非法 JSON 拒存 ==========

	/**
	 * @test
	 * @group error
	 * Rule: 寫入驗證——非法 JSON 回 400，DB 內容保持原樣
	 */
	public function test_非法JSON回400且DB不變(): void {
		$response = $this->call_update( [ 'short_description' => '{"broken": ' ] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 400, $response->get_error_data()['status'] ?? null );
		$this->assertSame( $this->existing_json, $this->get_db_excerpt(), '非法 JSON 不得動到既有內容' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 寫入驗證——非字串（巢狀陣列）回 400，DB 內容保持原樣
	 * （原 bug：陣列被 (string) 轉型成 "Array" 存進 DB）
	 */
	public function test_非字串內容回400且DB不變(): void {
		$response = $this->call_update( [ 'short_description' => [ 'type' => 'page' ] ] );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $this->existing_json, $this->get_db_excerpt() );
	}

	// ========== 雙重編碼自癒 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 自癒——前端把字串再 stringify 一次（編輯器未載入完就儲存）時，存回裡層物件 JSON
	 */
	public function test_雙重編碼自動拆回裡層再儲存(): void {
		$double = (string) \wp_json_encode( $this->existing_json );

		$response = $this->call_update( [ 'short_description' => $double ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame(
			$this->existing_json,
			$this->get_db_excerpt(),
			'雙重編碼的內容應自動拆回裡層物件 JSON 再儲存'
		);
	}

	// ========== 刁鑽字串 round-trip ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 刁鑽字串 round-trip——各種容易觸發 slash / 編碼問題的內容，
	 * 儲存後 byte 不變、仍為合法 JSON、decode 後內容與原值一致
	 */
	public function test_刁鑽字串round_trip後仍為合法JSON(): void {
		$torture_cases = [
			'windows_path'    => 'C:\\Users\\test\\Documents\\file.txt',
			'trailing_bs'     => 'path ends with a backslash \\',
			'many_bs'         => '\\\\\\\\ four backslashes',
			'literal_bs_n'    => 'literal \\n is not a newline',
			'quotes_mix'      => 'single \' and double " quotes \'"mixed"\'',
			'crlf'            => "line1\r\nline2\rline3\nline4",
			'tab_ctrl'        => "tab\there backspace\x08 formfeed\x0c end",
			'emoji_utf8mb4'   => '🎉🚀 中文與 emoji 混排 ✅ 👨‍👩‍👧‍👦',
			'unicode_literal' => 'literal \\u4e2d\\u6587 not decoded',
			'json_like_value' => '{"nested":"value that looks like json","arr":[1,2]}',
			'html_script'     => '<script>alert("xss & <b>bold</b>")</script><!-- comment -->',
			'percent_brace'   => '100% off {display_name} %s %1$s [shortcode attr="x"]',
			'sql_danger'      => "'; DROP TABLE wp_posts; -- \\' OR 1=1",
			'slash_in_url'    => 'https:\/\/example.com\/path escaped slashes',
		];

		foreach ( $torture_cases as $label => $content ) {
			$json = (string) \wp_json_encode(
				[
					'type' => 'page',
					'data' => [ 'value' => [ 'content' => $content ] ],
				]
			);

			$response = $this->call_update( [ 'short_description' => $json ] );

			$this->assertNotInstanceOf( \WP_Error::class, $response, "[{$label}] 儲存不應失敗" );

			$stored = $this->get_db_excerpt();
			$this->assertSame( $json, $stored, "[{$label}] 儲存後 byte 必須與送出完全一致" );

			$decoded = json_decode( $stored, true );
			$this->assertNotNull( $decoded, "[{$label}] 存進 DB 的必須是合法 JSON（模擬前端 JSON.parse）" );
			$this->assertSame(
				$content,
				$decoded['data']['value']['content'] ?? null,
				"[{$label}] decode 後內容必須與原值一致"
			);
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 手寫 JSON（raw UTF-8 中文 + \uXXXX 混合，非 wp_json_encode 產出）round-trip 不變
	 */
	public function test_手寫JSON含raw中文與unicode跳脫round_trip不變(): void {
		// 手組 JSON 文字：raw 中文、\uXXXX、\n、\"、\\ 全混在同一份
		$json = '{"type":"page","data":{"value":{"content":"中文 raw + 中文 escaped\nnew \"line\" C:\\\\temp"}},"children":[]}';

		// 先確認測試資料本身合法
		$this->assertNotNull( json_decode( $json ), '測試資料本身必須是合法 JSON' );

		$response = $this->call_update( [ 'short_description' => $json ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( $json, $this->get_db_excerpt(), '手寫 JSON 儲存後 byte 必須完全一致' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 刁鑽內容重複儲存三次不劣化（模擬用戶連續編輯儲存）
	 */
	public function test_刁鑽內容重複儲存三次不劣化(): void {
		$json = (string) \wp_json_encode(
			[
				'type' => 'page',
				'data' => [ 'value' => [ 'content' => "C:\\Users\\x \\' \"q\" \r\n 🎉 100% {ph} '; --" ] ],
			]
		);

		for ( $i = 0; $i < 3; $i++ ) {
			// 每輪都用「DB 現值」重存，模擬前端取出 → 再儲存的完整循環
			$payload  = 0 === $i ? $json : $this->get_db_excerpt();
			$response = $this->call_update( [ 'short_description' => $payload ] );
			$this->assertNotInstanceOf( \WP_Error::class, $response, "第 {$i} 輪儲存不應失敗" );
		}

		$this->assertSame( $json, $this->get_db_excerpt(), '重複儲存三次後 byte 必須與最初送出一致' );
		$this->assertNotNull( json_decode( $this->get_db_excerpt() ), '三輪後仍必須是合法 JSON' );
	}

	// ========== 清空語意 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 清空——明確送出空字串時允許清空內容
	 */
	public function test_空字串允許清空內容(): void {
		$response = $this->call_update( [ 'short_description' => '' ] );

		$this->assertNotInstanceOf( \WP_Error::class, $response );
		$this->assertSame( '', $this->get_db_excerpt() );
	}
}
