<?php
/**
 * Bunny Stream API 金鑰外洩防護整合測試
 *
 * 背景：Bootstrap::enqueue_script() 同時掛在 wp_enqueue_scripts（前台）與後台，
 * 會把 env 以 Powerhouse simple_encrypt（base64 + 字元位移，非加密）注入 HTML。
 * 若 env 內含 BUNNY_STREAM_API_KEY，所有前台訪客（含未登入）都能還原出
 * 擁有整個 Bunny 影片庫讀寫刪權限的金鑰。
 *
 * 規則：只有具備外掛後台權限（Plugin::$capability）的使用者才拿得到金鑰。
 *
 * @group security
 * @group bunny
 */

declare( strict_types=1 );

namespace Tests\Integration\Bootstrap;

use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\Plugin;
use J7\Powerhouse\Settings\Model\Settings;
use Tests\Integration\TestCase;

/**
 * Class BunnyStreamApiKeyExposureTest
 * 驗證注入瀏覽器的 env 不會把 Bunny Stream API 金鑰交給無權限的使用者
 */
class BunnyStreamApiKeyExposureTest extends TestCase {

	/** @var string 測試用假金鑰 */
	private const FAKE_API_KEY = 'TEST-bunny-stream-api-key-0000';

	/** @var string 測試用假 CDN hostname */
	private const FAKE_CDN_HOSTNAME = 'vz-test.b-cdn.net';

	/** @var string 原始金鑰（tear_down 還原用） */
	private string $original_api_key = '';

	/** @var string 原始 CDN hostname（tear_down 還原用） */
	private string $original_cdn_hostname = '';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接呼叫 Bootstrap::enqueue_script()，不需額外依賴
	}

	/**
	 * 每個測試前：設定假金鑰、重建乾淨的 WP_Scripts
	 */
	public function set_up(): void {
		parent::set_up();

		$settings                    = Settings::instance();
		$this->original_api_key      = $settings->bunny_stream_api_key;
		$this->original_cdn_hostname = $settings->bunny_cdn_hostname;

		$settings->bunny_stream_api_key = self::FAKE_API_KEY;
		$settings->bunny_cdn_hostname   = self::FAKE_CDN_HOSTNAME;

		// wp_localize_script 會把資料「附加」到既有 handle 上，
		// 不重建的話前一支測試（例如管理員）注入的 env 會殘留，造成假綠燈
		$GLOBALS['wp_scripts'] = null;
	}

	/**
	 * 每個測試後：還原設定與 WP_Scripts
	 */
	public function tear_down(): void {
		$settings                       = Settings::instance();
		$settings->bunny_stream_api_key = $this->original_api_key;
		$settings->bunny_cdn_hostname   = $this->original_cdn_hostname;

		$GLOBALS['wp_scripts'] = null;
		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * 各角色預期能否拿到金鑰
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function role_provider(): array {
		return [
			'未登入訪客'          => [ '', false ],
			'subscriber'          => [ 'subscriber', false ],
			'customer（學員）'    => [ 'customer', false ],
			'shop_manager'        => [ 'shop_manager', true ],
			'administrator'       => [ 'administrator', true ],
		];
	}

	/**
	 * @test
	 * @group security
	 * @dataProvider role_provider
	 * 注入 HTML 的 env：只有具後台權限者拿得到 Bunny Stream API 金鑰
	 *
	 * @param string $role       使用者角色（空字串 = 未登入）
	 * @param bool   $should_get 是否應拿到金鑰
	 */
	public function test_注入HTML的env只對具後台權限者輸出金鑰( string $role, bool $should_get ): void {
		$this->login_as( $role );

		Bootstrap::enqueue_script();
		$env = $this->get_injected_env();

		$this->assertSame(
			$should_get ? self::FAKE_API_KEY : '',
			$env['BUNNY_STREAM_API_KEY'] ?? null,
			$should_get
				? "角色 {$role} 具後台權限，應拿到金鑰（後台上傳影片需要）"
				: "角色「{$role}」不具後台權限，HTML 內不得出現 Bunny Stream API 金鑰"
		);
	}

	/**
	 * @test
	 * @group security
	 * 未登入訪客仍拿得到前台播放所需的 CDN hostname（只拿掉金鑰，不影響播放）
	 */
	public function test_未登入訪客仍保留播放所需的CDN_hostname(): void {
		$this->login_as( '' );

		Bootstrap::enqueue_script();
		$env = $this->get_injected_env();

		$this->assertSame( self::FAKE_CDN_HOSTNAME, $env['BUNNY_CDN_HOSTNAME'] ?? null );
	}

	/**
	 * @test
	 * @group security
	 * 未登入訪客的整段 localize 資料（還原後的明文）完全不含金鑰字串
	 */
	public function test_未登入訪客的localize資料完全不含金鑰字串(): void {
		$this->login_as( '' );

		Bootstrap::enqueue_script();
		$raw = (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' );

		$this->assertNotSame( '', $raw, 'enqueue_script() 應有注入 localize 資料，否則本測試沒有碰到洩漏點' );
		$this->assertStringNotContainsString( self::FAKE_API_KEY, $raw );
		$this->assertStringNotContainsString( self::FAKE_API_KEY, \wp_json_encode( $this->get_injected_env() ) ?: '' );
	}

	/**
	 * 以指定角色登入（空字串 = 未登入）
	 *
	 * @param string $role 角色
	 */
	private function login_as( string $role ): void {
		if ( '' === $role ) {
			\wp_set_current_user( 0 );
			return;
		}

		$user_id = $this->factory()->user->create(
			[
				'user_login' => "bunny_{$role}_" . uniqid(),
				'user_email' => "bunny_{$role}_" . uniqid() . '@test.com',
				'role'       => $role,
			]
		);
		\wp_set_current_user( $user_id );
	}

	/**
	 * 從 WP_Scripts 取出注入 HTML 的 power_course_data.env，並以前端相同演算法還原
	 *
	 * @return array<string, mixed>
	 */
	private function get_injected_env(): array {
		$raw = (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' );

		$matched = preg_match( '/var ' . preg_quote( Plugin::$snake, '/' ) . '_data = (\{.*?\});/s', $raw, $m );
		$this->assertSame( 1, $matched, '找不到 power_course_data 的 localize 資料' );

		$payload = json_decode( $m[1], true );
		$this->assertIsArray( $payload );
		$this->assertIsString( $payload['env'] ?? null );

		// simple_encrypt 的逆運算：每個字元 -1 → base64 decode → JSON
		$shifted = '';
		$length  = strlen( $payload['env'] );
		for ( $i = 0; $i < $length; $i++ ) {
			$shifted .= chr( ord( $payload['env'][ $i ] ) - 1 );
		}
		$env = json_decode( (string) base64_decode( $shifted, true ), true );
		$this->assertIsArray( $env, 'env 還原失敗' );

		/** @var array<string, mixed> $env */
		return $env;
	}
}
