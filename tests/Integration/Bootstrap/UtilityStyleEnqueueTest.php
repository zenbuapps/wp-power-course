<?php
/**
 * Power Course 自產 utility CSS 載入整合測試（Issue #285）
 *
 * 背景：前台模板與後台 SPA 的 Tailwind / daisyUI class 原本全靠 Powerhouse 的
 * front.min.css / admin.min.css。Powerhouse 3.6.0 建置時沒掃到 power-course，
 * 漏了 786 / 787 條規則 → 學員「我的課程」整片空白、教室 SVG 圖示爆大。
 *
 * 規則：
 * - 前台全站載入兩份：front-components（daisyUI 元件）排在 powerhouse_front 之前，
 *   front-utilities 排在之後——元件若排在後面，會蓋掉 Powerhouse 的手寫修正與姊妹外掛的覆寫（PR #286 審查 B1）
 * - Powerhouse 沒註冊 powerhouse_front 時兩份照樣載入（不可因相依缺失被 WP 整個略過）
 * - base 的 `--tw-*` 必須包在 `@layer tailwind`；後台元件必須包在 `@layer power-course-components`（S1 / B1）
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

	/** 前台元件 CSS 的相對路徑 */
	private const FRONT_COMPONENTS_CSS = '/inc/assets/dist/css/front-components.min.css';

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
	 * 前台元件 CSS 掛成 powerhouse_front 的相依（WP 會先印相依）
	 */
	public function test_前台元件css掛成powerhouse_front的相依(): void {
		$this->skip_if_missing( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' );
		$this->register_fake_powerhouse_front();

		Ajax::wp_enqueue_scripts();
		// 重跑一次（例如別的外掛又觸發 wp_enqueue_scripts），相依不可重複加
		Ajax::enqueue_utility_style();

		$component = \wp_styles()->registered[ Ajax::COMPONENT_STYLE_HANDLE ] ?? null;
		$this->assertNotNull( $component, '應註冊 front-components' );
		$this->assertSame( Plugin::$url . self::FRONT_COMPONENTS_CSS, $component->src );
		$this->assertSame( [], $component->deps, '元件 CSS 本身不可相依 powerhouse_front，否則會形成循環相依' );
		$this->assertSame(
			[ Ajax::COMPONENT_STYLE_HANDLE ],
			\wp_styles()->registered[ Ajax::POWERHOUSE_FRONT_STYLE_HANDLE ]->deps,
			'front-components 必須（且只一次）掛進 powerhouse_front 的相依'
		);
	}

	/**
	 * @test
	 * @group happy
	 * 實際輸出順序：front-components → powerhouse_front → front-utilities，
	 * 不論 Powerhouse 先 enqueue（實際情況：它在 -100、我們在 -10）還是後 enqueue
	 */
	public function test_前台輸出順序元件在前utility在後(): void {
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );
		$this->skip_if_missing( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' );

		foreach ( [ 'Powerhouse 先 enqueue' => true, 'Powerhouse 後 enqueue' => false ] as $scenario => $powerhouse_first ) {
			$GLOBALS['wp_styles'] = null;
			$this->register_fake_powerhouse_front();

			if ( $powerhouse_first ) {
				\wp_enqueue_style( Ajax::POWERHOUSE_FRONT_STYLE_HANDLE );
			}
			Ajax::wp_enqueue_scripts();
			if ( ! $powerhouse_first ) {
				\wp_enqueue_style( Ajax::POWERHOUSE_FRONT_STYLE_HANDLE );
			}

			$html = $this->capture_output(
				static function (): void {
					\wp_styles()->do_items();
				}
			);

			$component_pos  = strpos( $html, "id='" . Ajax::COMPONENT_STYLE_HANDLE . "-css'" );
			$powerhouse_pos = strpos( $html, "id='" . Ajax::POWERHOUSE_FRONT_STYLE_HANDLE . "-css'" );
			$ours_pos       = strpos( $html, "id='" . Ajax::UTILITY_STYLE_HANDLE . "-css'" );

			$this->assertNotFalse( $powerhouse_pos, "{$scenario}：powerhouse_front 應有輸出，否則本測試沒測到順序" );
			$this->assertNotFalse( $component_pos, "{$scenario}：front-components 應有輸出" );
			$this->assertNotFalse( $ours_pos, "{$scenario}：front-utilities 應有輸出" );
			$this->assertLessThan( $powerhouse_pos, $component_pos, "{$scenario}：front-components 必須排在 powerhouse_front 之前，否則會蓋掉 Powerhouse 的手寫修正" );
			$this->assertGreaterThan( $powerhouse_pos, $ours_pos, "{$scenario}：front-utilities 必須排在 powerhouse_front 之後" );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Powerhouse 沒註冊 powerhouse_front 時，照樣載入且能輸出（不能被未註冊的相依拖累）
	 */
	public function test_沒有powerhouse_front時仍載入且能輸出(): void {
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );
		$this->skip_if_missing( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' );

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
		$this->assertStringContainsString( "id='" . Ajax::COMPONENT_STYLE_HANDLE . "-css'", $html, '沒有 powerhouse_front 可掛時，元件 CSS 要自己載入' );
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
		$css        = $this->read_css( self::FRONT_CSS, 'pnpm run build:wp' );
		$components = $this->read_css( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' );

		// daisyUI 元件在 front-components，且必須收進 #tw scope（與 Powerhouse 輸出相同的 specificity）
		foreach ( [ '#tw .pc-drawer{', '#tw .pc-btn{', '#tw .pc-rating ' ] as $needle ) {
			$this->assertStringContainsString( $needle, $components, "front-components 缺少 {$needle}" );
			$this->assertStringNotContainsString( $needle, $css, "front-utilities 不可再輸出元件 {$needle}，否則會排在 Powerhouse 之後蓋掉它的修正" );
		}

		$expected = [
			// 「我的課程」分頁：.active 的那一頁要顯示（inc/templates/components/tabs/content.php）
			'#tw :is(.\[\&_\.active\]\:\!tw-block .active){display:block!important}',
			// 教室頁圖示尺寸
			'#tw .size-3{',
			'#tw .sm\:size-4{',
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
		$files = [
			self::FRONT_CSS            => 'pnpm run build:wp',
			self::FRONT_COMPONENTS_CSS => 'pnpm run build:wp',
			self::ADMIN_CSS            => 'pnpm run build',
		];
		foreach ( $files as $path => $command ) {
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
	 * @test
	 * @group edge
	 * base 的 `--tw-*` 預設值必須包在 `@layer tailwind`（與 Powerhouse 同名）
	 *
	 * 不在 layer 裡的規則永遠勝過 layer 裡的規則：裸露的 `*{--tw-shadow:…}` 會把佈景主題 / 外掛
	 * 放在 cascade layer（例如 Tailwind v4 的 `@layer utilities`）裡設定的同名變數全部重設
	 */
	public function test_base變數包在tailwind_layer(): void {
		foreach ( [ self::FRONT_CSS => 'pnpm run build:wp', self::ADMIN_CSS => 'pnpm run build' ] as $path => $command ) {
			$css = $this->read_css( $path, $command );

			$this->assertStringContainsString( '@layer tailwind{*,:after,:before{--tw-', $css, "{$path} 的 base 應包在 @layer tailwind" );
			$this->assertStringNotContainsString(
				'*,:after,:before{--tw-',
				$this->remove_layer_block( $css, 'tailwind' ),
				"{$path} 不可在 layer 外輸出 --tw-* 預設值"
			);
		}
	}

	/**
	 * @test
	 * @group edge
	 * 後台的 daisyUI 元件必須全部包在 `@layer power-course-components`
	 *
	 * 後台 admin.min.css 是寫死在 <head> 的 <link>，我們只能排在它後面；
	 * 包進 layer 才能讓 Powerhouse 的元件與手寫修正優先
	 */
	public function test_後台元件包在layer裡(): void {
		$css = $this->read_css( self::ADMIN_CSS, 'pnpm run build' );

		$this->assertStringContainsString( '@layer power-course-components{#tw .pc-', $css, '後台元件應包在 @layer power-course-components' );
		$this->assertDoesNotMatchRegularExpression(
			'/#tw \.pc-[a-z]/',
			$this->remove_layer_block( $css, 'power-course-components' ),
			'後台不可在 layer 外輸出 daisyUI 元件'
		);
	}

	/**
	 * 移除 CSS 中第一個指定名稱的 `@layer name{…}` 區塊（minify 過的輸出）
	 *
	 * @param string $css  CSS 內容
	 * @param string $name layer 名稱
	 * @return string
	 */
	private function remove_layer_block( string $css, string $name ): string {
		$start = strpos( $css, "@layer {$name}{" );
		$this->assertNotFalse( $start, "找不到 @layer {$name}" );

		$depth  = 0;
		$length = strlen( $css );
		for ( $i = $start; $i < $length; $i++ ) {
			if ( '{' === $css[ $i ] ) {
				++$depth;
			} elseif ( '}' === $css[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $css, 0, $start ) . substr( $css, $i + 1 );
				}
			}
		}

		$this->fail( "@layer {$name} 的大括號沒有閉合" );
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
	 * dist 在 gitignore 裡：CI（pipe.yml）必須在 PHPUnit 之前跑 build:css:front / build:css:admin，
	 * 否則這整支測試會變成「綠燈但什麼都沒測」（PR #286 審查 S2）。
	 * 不用 getenv( 'CI' ) 改成失敗：PHPUnit 跑在 wp-env 容器裡，讀不到 runner 的環境變數
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
