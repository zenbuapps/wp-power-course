<?php
/**
 * Power Course 自產 utility CSS 載入整合測試（Issue #285）
 *
 * 背景：前台模板與後台 SPA 的 Tailwind / daisyUI class 原本全靠 Powerhouse 的
 * front.min.css / admin.min.css。Powerhouse 3.6.0 建置時沒掃到 power-course，
 * 漏了 786 / 787 條規則 → 學員「我的課程」整片空白、教室 SVG 圖示爆大。
 *
 * 規則：
 * - 前台全站載入 front-utilities.min.css，且排在 powerhouse_front 之後
 * - Powerhouse 沒註冊 powerhouse_front 時照樣載入（不可因相依缺失被 WP 整個略過）
 * - 後台 utility CSS 只在課程後台載入，不跟著前台 vidstack 播放器進前台
 * - 產出的 CSS 必須含模板實際依賴的規則，且不可輸出 daisyUI 主題變數（會蓋掉站長選的主題色）
 *
 * @group smoke
 * @group issue-285
 */

declare( strict_types=1 );

namespace Tests\Integration\Bootstrap;

use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Templates\Ajax;
use Tests\Integration\TestCase;

/**
 * Class UtilityStyleEnqueueTest
 * 驗證 Power Course 自產的前台 / 後台 utility CSS 正確載入
 */
class UtilityStyleEnqueueTest extends TestCase {

	/** 前台 utility CSS 的相對路徑 */
	private const FRONT_CSS = '/inc/assets/dist/css/front-utilities.min.css';

	/** 後台 utility CSS 的相對路徑 */
	private const ADMIN_CSS = '/js/dist/css/admin-utilities.min.css';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接呼叫 enqueue 方法與讀取建置產物，不需額外依賴
	}

	/**
	 * 每個測試前：重建乾淨的 WP_Scripts / WP_Styles
	 */
	public function set_up(): void {
		parent::set_up();

		// 前一支測試 enqueue 過的 handle 會殘留在全域 queue，不重建會造成假綠燈
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
	}

	/**
	 * 每個測試後：還原 WP_Scripts / WP_Styles
	 */
	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		parent::tear_down();
	}

	/**
	 * @test
	 * @group happy
	 * 前台 enqueue 會載入 front-utilities，並宣告相依 powerhouse_front
	 */
	public function test_前台載入utility_css並相依powerhouse_front(): void {
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );
		$this->register_fake_powerhouse_front();

		Ajax::wp_enqueue_scripts();

		$this->assertTrue( \wp_style_is( Ajax::UTILITY_STYLE_HANDLE, 'enqueued' ), '前台應載入 Power Course 自產的 utility CSS' );

		$style = \wp_styles()->registered[ Ajax::UTILITY_STYLE_HANDLE ];
		$this->assertSame( Plugin::$url . self::FRONT_CSS, $style->src );
		$this->assertSame( [ Ajax::POWERHOUSE_FRONT_STYLE_HANDLE ], $style->deps, '必須相依 powerhouse_front，才會排在它後面' );
		$this->assertSame( (string) filemtime( Plugin::$dir . self::FRONT_CSS ), $style->ver, 'ver 用檔案 mtime，build 後自動 cache bust' );
	}

	/**
	 * @test
	 * @group happy
	 * 實際輸出時 front-utilities 的 <link> 排在 powerhouse_front 之後
	 */
	public function test_前台輸出順序在powerhouse_front之後(): void {
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );
		$this->register_fake_powerhouse_front();

		Ajax::wp_enqueue_scripts();
		// 模擬 Powerhouse 在我們之後才 enqueue：相依關係仍要保證順序
		\wp_enqueue_style( Ajax::POWERHOUSE_FRONT_STYLE_HANDLE );

		$html = $this->capture_output(
			static function (): void {
				\wp_styles()->do_items();
			}
		);

		$powerhouse_pos = strpos( $html, "id='" . Ajax::POWERHOUSE_FRONT_STYLE_HANDLE . "-css'" );
		$ours_pos       = strpos( $html, "id='" . Ajax::UTILITY_STYLE_HANDLE . "-css'" );

		$this->assertNotFalse( $powerhouse_pos, 'powerhouse_front 應有輸出，否則本測試沒測到順序' );
		$this->assertNotFalse( $ours_pos, 'front-utilities 應有輸出' );
		$this->assertGreaterThan( $powerhouse_pos, $ours_pos, 'front-utilities 必須排在 powerhouse_front 之後' );
	}

	/**
	 * @test
	 * @group edge
	 * Powerhouse 沒註冊 powerhouse_front 時，照樣載入且能輸出（不能被未註冊的相依拖累）
	 */
	public function test_沒有powerhouse_front時仍載入且能輸出(): void {
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );

		$this->assertFalse( \wp_style_is( Ajax::POWERHOUSE_FRONT_STYLE_HANDLE, 'registered' ), '前置條件：乾淨的 WP_Styles 不應有 powerhouse_front' );

		Ajax::wp_enqueue_scripts();

		$style = \wp_styles()->registered[ Ajax::UTILITY_STYLE_HANDLE ] ?? null;
		$this->assertNotNull( $style, '應註冊 front-utilities' );
		$this->assertSame( [], $style->deps, '沒有 powerhouse_front 時不可宣告相依，否則 WP 會整個略過不輸出' );

		$html = $this->capture_output(
			static function (): void {
				\wp_styles()->do_items();
			}
		);
		$this->assertStringContainsString( "id='" . Ajax::UTILITY_STYLE_HANDLE . "-css'", $html );
	}

	/**
	 * @test
	 * @group happy
	 * 後台 utility CSS 由 enqueue_admin_utility_style() 載入
	 */
	public function test_後台載入utility_css(): void {
		$this->skip_if_missing( self::ADMIN_CSS, 'pnpm run build' );

		Bootstrap::enqueue_admin_utility_style();

		$this->assertTrue( \wp_style_is( Bootstrap::ADMIN_UTILITY_STYLE_HANDLE, 'enqueued' ) );
		$this->assertSame( Plugin::$url . self::ADMIN_CSS, \wp_styles()->registered[ Bootstrap::ADMIN_UTILITY_STYLE_HANDLE ]->src );
	}

	/**
	 * @test
	 * @group edge
	 * 前台輸出 vidstack 播放器（會呼叫 Bootstrap::enqueue_script）時，不可帶入後台 utility CSS
	 */
	public function test_前台播放器不載入後台utility_css(): void {
		$this->skip_if_missing( self::ADMIN_CSS, 'pnpm run build' );
		if ( ! file_exists( Plugin::$dir . '/js/dist/manifest.json' ) ) {
			$this->markTestSkipped( '缺少 js/dist/manifest.json，請先 pnpm run build' );
		}

		Bootstrap::frontend_enqueue_script();

		$this->assertTrue( \wp_script_is( Plugin::$kebab, 'enqueued' ), '前置條件：播放器 bundle 應已載入，否則本測試沒走到 enqueue_script()' );
		$this->assertFalse( \wp_style_is( Bootstrap::ADMIN_UTILITY_STYLE_HANDLE, 'enqueued' ), '後台 utility CSS 不應跟著播放器進前台' );
	}

	/**
	 * @test
	 * @group smoke
	 * 前台 CSS 含 Issue #285 回報壞掉的規則（建置設定掃描範圍的回歸防線）
	 */
	public function test_前台css含模板依賴的關鍵規則(): void {
		$css = $this->read_css( self::FRONT_CSS, 'pnpm run build:wp' );

		$expected = [
			// 「我的課程」分頁：.active 的那一頁要顯示（inc/templates/components/tabs/content.php）
			'#tw :is(.\[\&_\.active\]\:\!tw-block .active){display:block!important}',
			// 教室頁圖示尺寸
			'#tw .size-3{',
			'#tw .sm\:size-4{',
			// daisyUI 元件，且必須收進 #tw scope（與 Powerhouse 輸出相同的 specificity）
			'#tw .pc-drawer{',
			'#tw .pc-btn{',
			// 前台 vidstack 播放器（React，js/src/App2/Ended.tsx）用的 class 也要掃到；
			// 這兩個只出現在 App2，inc/ 沒有，能證明 App2 有在掃描範圍內
			'#tw .bg-white\/70{',
			'#tw .font-thin{',
		];

		foreach ( $expected as $needle ) {
			$this->assertStringContainsString( $needle, $css, "front-utilities 缺少 {$needle}" );
		}
	}

	/**
	 * @test
	 * @group edge
	 * 前台 / 後台 CSS 都不可輸出 daisyUI 主題變數與 WordPress 衝突的 class
	 */
	public function test_css不含主題變數與blocklist的class(): void {
		foreach ( [ self::FRONT_CSS => 'pnpm run build:wp', self::ADMIN_CSS => 'pnpm run build' ] as $path => $command ) {
			$css = $this->read_css( $path, $command );

			// 主題色由 Powerhouse 依站長設定輸出；我們載入在後，若也輸出會把主題色蓋回預設
			$this->assertDoesNotMatchRegularExpression( '/--p:\s*[\d.]/', $css, "{$path} 不可輸出 daisyUI 主題變數 --p" );
			$this->assertStringNotContainsString( '[data-theme=', $css, "{$path} 不可輸出 [data-theme] 主題宣告" );

			// 與 WordPress / 佈景主題同名的 class 已改用 tw-* 版本
			$this->assertStringNotContainsString( '#tw .hidden{', $css, "{$path} 不可輸出 .hidden（與 WordPress 衝突）" );
			$this->assertStringNotContainsString( '#tw .block{', $css, "{$path} 不可輸出 .block（與 WordPress 衝突）" );
		}
	}

	/**
	 * 註冊一個假的 powerhouse_front（測試環境不一定有 Powerhouse 的前台 hook）
	 */
	private function register_fake_powerhouse_front(): void {
		\wp_register_style( Ajax::POWERHOUSE_FRONT_STYLE_HANDLE, 'https://example.com/powerhouse/front.min.css', [], '3.6.0' );
	}

	/**
	 * 讀建置產物
	 *
	 * @param string $path    相對於外掛根目錄的路徑
	 * @param string $command 產生該檔的指令（跳過訊息用）
	 * @return string
	 */
	private function read_css( string $path, string $command ): string {
		$this->skip_if_missing( $path, $command );
		return (string) file_get_contents( Plugin::$dir . $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * 沒有建置產物時跳過
	 *
	 * @param string $path    相對於外掛根目錄的路徑
	 * @param string $command 產生該檔的指令
	 */
	private function skip_if_missing( string $path, string $command ): void {
		if ( ! file_exists( Plugin::$dir . $path ) ) {
			$this->markTestSkipped( "缺少 {$path}，請先 {$command}" );
		}
	}

	/**
	 * 擷取 callback 的輸出
	 *
	 * @param callable $callback 要執行的函式
	 * @return string
	 */
	private function capture_output( callable $callback ): string {
		ob_start();
		$callback();
		return (string) ob_get_clean();
	}
}
