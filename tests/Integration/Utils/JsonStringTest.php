<?php
/**
 * JsonString 寫入守門工具整合測試
 *
 * Feature: JSON parse error 根治（JSON_PARSE_ERROR.md）
 * 測試 JSON 字串欄位寫入前的正規化行為：
 * - 合法 JSON 原樣通過（byte 不變）
 * - 空字串視為清空、放行
 * - 非法 JSON 回傳 WP_Error（400）
 * - 雙重（多重）編碼自癒：拆到最裡層物件
 * - require_object 時純量 JSON 被拒絕
 *
 * @group utils
 * @group json-string
 */

declare( strict_types=1 );

namespace Tests\Integration\Utils;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\JsonString;

/**
 * Class JsonStringTest
 *
 * @group json-content
 */
class JsonStringTest extends TestCase {

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 不需要額外依賴
	}

	/**
	 * @test
	 * @group happy
	 * 合法 JSON（含 \n、\"、\\ 跳脫字元）原樣通過，byte 不變
	 */
	public function test_合法JSON含跳脫字元原樣通過(): void {
		$json = (string) \wp_json_encode(
			[
				'content' => "line1\nline2 with \"quotes\" and \\ backslash",
			]
		);

		$result = JsonString::normalize( $json, true );

		$this->assertSame( $json, $result, '合法 JSON 應原樣通過，任何 byte 都不可改變' );
	}

	/**
	 * @test
	 * @group happy
	 * 空字串代表清空內容，直接放行
	 */
	public function test_空字串視為清空放行(): void {
		$this->assertSame( '', JsonString::normalize( '' ) );
		$this->assertSame( '', JsonString::normalize( '   ' ) );
	}

	/**
	 * @test
	 * @group error
	 * 非法 JSON 回傳 WP_Error 且 status 400
	 */
	public function test_非法JSON回傳WP_Error(): void {
		$result = JsonString::normalize( '{"broken": ' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_json_content', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	/**
	 * @test
	 * @group error
	 * 被 stripslashes 咬壞的 JSON（跳脫引號變裸引號）應被拒絕
	 */
	public function test_被咬壞的JSON被拒絕(): void {
		// 模擬 \" 被咬成 " 之後的壞資料
		$corrupted = '{"content":"he said "hi""}';

		$result = JsonString::normalize( $corrupted );

		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	/**
	 * @test
	 * @group edge
	 * 雙重編碼自癒：字串再被 stringify 一次後，能拆回最裡層物件 JSON
	 */
	public function test_雙重編碼自動拆回裡層(): void {
		$inner  = (string) \wp_json_encode( [ 'type' => 'page', 'data' => [ 'x' => "a\nb" ] ] );
		$double = (string) \wp_json_encode( $inner ); // 模擬前端把字串再 stringify 一次

		$result = JsonString::normalize( $double, true );

		$this->assertSame( $inner, $result, '雙重編碼應自動拆回裡層物件 JSON' );
	}

	/**
	 * @test
	 * @group edge
	 * 三重編碼也能自癒
	 */
	public function test_三重編碼自動拆回裡層(): void {
		$inner  = (string) \wp_json_encode( [ 'type' => 'page' ] );
		$triple = (string) \wp_json_encode( (string) \wp_json_encode( $inner ) );

		$result = JsonString::normalize( $triple, true );

		$this->assertSame( $inner, $result );
	}

	/**
	 * @test
	 * @group edge
	 * require_object=true 時，純量 JSON（如 "hello"、123）被拒絕
	 */
	public function test_require_object時純量被拒絕(): void {
		$this->assertInstanceOf( \WP_Error::class, JsonString::normalize( '"hello"', true ) );
		$this->assertInstanceOf( \WP_Error::class, JsonString::normalize( '123', true ) );
	}

	/**
	 * @test
	 * @group edge
	 * require_object=false 時，純量 JSON 放行（generic 用途）
	 */
	public function test_不要求物件時純量放行(): void {
		$this->assertSame( '123', JsonString::normalize( '123' ) );
	}

	/**
	 * @test
	 * @group edge
	 * 刁鑽 JSON 文字全數原樣通過：\uXXXX、surrogate pair、\/、前導空白、深巢狀
	 */
	public function test_刁鑽JSON文字原樣通過(): void {
		$cases = [
			'unicode_escapes'  => '{"a":"中文🎉"}',
			'escaped_slash'    => '{"url":"https:\/\/example.com\/x"}',
			'leading_space'    => '  {"a":1}',
			'deep_nested'      => '{"a":{"b":{"c":{"d":{"e":[1,"x\\\\y"]}}}}}',
			'empty_object'     => '{}',
			'empty_array_json' => '[]',
			'mixed_escapes'    => '{"s":"\\\\ \\" \\n \\t \\r \\b \\f \\u0041"}',
		];

		foreach ( $cases as $label => $json ) {
			$this->assertNotNull( json_decode( $json ), "[{$label}] 測試資料本身必須合法" );
			$this->assertSame( $json, JsonString::normalize( $json ), "[{$label}] 合法 JSON 必須原樣通過" );
		}
	}

	/**
	 * @test
	 * @group error
	 * 各種壞資料全數被拒：截斷、BOM、裸字串、"Array" 字面值、單引號 JSON
	 */
	public function test_各種壞資料全數被拒(): void {
		$cases = [
			'truncated'    => '{"type":"page","data":{"va',
			'bom_prefix'   => "\xEF\xBB\xBF{\"a\":1}",
			'bare_string'  => 'just a plain sentence',
			'array_cast'   => 'Array',
			'single_quote' => "{'a':'b'}",
			'trailing_gar' => '{"a":1}garbage',
		];

		foreach ( $cases as $label => $bad ) {
			$this->assertInstanceOf(
				\WP_Error::class,
				JsonString::normalize( $bad, true ),
				"[{$label}] 壞資料必須被拒絕"
			);
		}
	}
}
