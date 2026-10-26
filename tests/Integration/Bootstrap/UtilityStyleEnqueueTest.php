<?php
/**
 * Power Course 自產 utility CSS 載入整合測試（Issue #285）
 *
 * 背景：前台模板與後台 SPA 的 Tailwind / daisyUI class 原本全靠 Powerhouse 的
 * front.min.css / admin.min.css。Powerhouse 3.6.0 建置時沒掃到 power-course，
 * 漏了 786 / 787 條規則 → 學員「我的課程」整片空白、教室 SVG 圖示爆大。
 *
 * 規則：
 * - 前台全站載入兩份：front-components（daisyUI 元件）與 front-utilities（base + utility）
 * - Powerhouse 的 CSS 完整時，兩份都排在 powerhouse_front **之前**：utility 若排在後面，
 *   會蓋掉姊妹外掛的響應式 class（PR #286 審查 B2）
 * - Powerhouse 的 CSS 不完整（或讀不到）時，元件在前、utility 在後：Power Course 元素上的
 *   utility 不被 Powerhouse 的基底 class 蓋回
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
use J7\PowerCourse\Templates\UtilityStyle;
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

	/** 本支測試寫出的假 Powerhouse CSS（tear_down 時刪除） */
	private string $fake_powerhouse_css = '';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接呼叫 enqueue 方法與讀取建置產物，不需額外依賴
	}

	/**
	 * 每個測試前：重建乾淨的 WP_Scripts / WP_Styles、清掉覆蓋率快取
	 */
	public function set_up(): void {
		parent::set_up();

		// 前一支測試 enqueue 過的 handle 會殘留在全域 queue，不重建會造成假綠燈
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		\delete_option( UtilityStyle::COVERAGE_OPTION );
	}

	/**
	 * 每個測試後：還原 WP_Scripts / WP_Styles、刪掉假的 Powerhouse CSS
	 */
	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		if ( '' !== $this->fake_powerhouse_css && file_exists( $this->fake_powerhouse_css ) ) {
			\wp_delete_file( $this->fake_powerhouse_css );
		}

		parent::tear_down();
	}

	/**
	 * @test
	 * @group happy
	 * Powerhouse 完整時：兩份都排在 powerhouse_front 之前（不論 Powerhouse 先或後 enqueue）
	 */
	public function test_powerhouse完整時兩份都排在powerhouse_front之前(): void {
		$this->skip_if_front_css_missing();
		$this->use_fake_powerhouse_css( $this->own_front_css() );

		$this->assertSame( UtilityStyle::MODE_BEFORE, UtilityStyle::get_mode(), '前置條件：Powerhouse 含全部選擇器時應判定為完整' );

		foreach ( $this->render_front_styles() as $scenario => $html ) {
			$this->assert_order(
				$html,
				[ UtilityStyle::COMPONENT_HANDLE, UtilityStyle::UTILITY_HANDLE, UtilityStyle::POWERHOUSE_HANDLE ],
				"{$scenario}：utility 排在 powerhouse_front 之後會蓋掉姊妹外掛的響應式 class"
			);
		}

		$this->assertSame( [], \wp_styles()->registered[ UtilityStyle::UTILITY_HANDLE ]->deps, '完整時 utility 不可相依 powerhouse_front（否則會排到它後面）' );
	}

	/**
	 * @test
	 * @group edge
	 * Powerhouse 不完整時：元件在前、utility 在後（不論 Powerhouse 先或後 enqueue）
	 */
	public function test_powerhouse不完整時元件在前utility在後(): void {
		$this->skip_if_front_css_missing();
		// 只含一個無關選擇器：等同 3.6.0 那種整批漏掉的建置
		$this->use_fake_powerhouse_css( '#tw .unrelated{color:red}' );

		$this->assertSame( UtilityStyle::MODE_SANDWICH, UtilityStyle::get_mode(), '前置條件：Powerhouse 缺大量選擇器時應判定為不完整' );

		foreach ( $this->render_front_styles() as $scenario => $html ) {
			$this->assert_order(
				$html,
				[ UtilityStyle::COMPONENT_HANDLE, UtilityStyle::POWERHOUSE_HANDLE, UtilityStyle::UTILITY_HANDLE ],
				"{$scenario}：不完整時 utility 必須排在 powerhouse_front 之後，元件必須排在之前"
			);
		}
	}

	/**
	 * @test
	 * @group edge
	 * 缺漏比例在門檻內（版本錯位：新模板多了幾個 Powerhouse 還沒有的 class）仍算完整
	 */
	public function test_少量缺漏仍判定為完整(): void {
		$this->skip_if_front_css_missing();
		$own = $this->own_front_css();
		// 拿掉一條規則（約 0.1%）
		$this->use_fake_powerhouse_css( str_replace( '#tw .size-3{', '#tw .removed-for-test{', $own ) );

		$coverage = UtilityStyle::get_coverage();
		$this->assertNotNull( $coverage );
		$this->assertGreaterThan( 0, $coverage['missing'], '前置條件：應偵測到缺漏' );
		$this->assertSame( UtilityStyle::MODE_BEFORE, UtilityStyle::get_mode() );
	}

	/**
	 * @test
	 * @group edge
	 * 讀不到 Powerhouse 的 CSS 檔時無法判斷，當成不完整（保住 Power Course 自己的頁面）
	 */
	public function test_讀不到powerhouse_css時當成不完整(): void {
		$this->skip_if_front_css_missing();
		\add_filter( 'power_course_powerhouse_front_css_path', static fn(): string => '/nonexistent/front.min.css' );

		$this->assertNull( UtilityStyle::get_coverage() );
		$this->assertSame( UtilityStyle::MODE_SANDWICH, UtilityStyle::get_mode() );
	}

	/**
	 * @test
	 * @group edge
	 * 覆蓋率結果有快取；Powerhouse 的檔案變動（更新版本）後會重算
	 */
	public function test_覆蓋率快取在檔案變動後重算(): void {
		$this->skip_if_front_css_missing();
		$this->use_fake_powerhouse_css( $this->own_front_css() );

		$first = UtilityStyle::get_coverage();
		$this->assertSame( 0, $first['missing'] ?? null );
		$cached = \get_option( UtilityStyle::COVERAGE_OPTION );
		$this->assertIsArray( $cached, '結果應寫入 option 快取' );
		$this->assertSame( 0, $cached['missing'] );

		// 模擬 Powerhouse 更新成壞掉的版本：內容與 mtime 都變
		file_put_contents( $this->fake_powerhouse_css, '#tw .unrelated{color:red}' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		touch( $this->fake_powerhouse_css, time() + 60 );
		clearstatcache();

		$second = UtilityStyle::get_coverage();
		$this->assertNotNull( $second );
		$this->assertSame( $second['total'], $second['missing'], '檔案變動後必須重算，不可沿用舊快取' );
	}

	/**
	 * @test
	 * @group edge
	 * 站長可用 filter 強制指定載入模式；不合法的值會被忽略
	 */
	public function test_載入模式可用filter覆寫(): void {
		$this->skip_if_front_css_missing();
		$this->use_fake_powerhouse_css( $this->own_front_css() );

		\add_filter( 'power_course_front_utility_style_mode', static fn(): string => UtilityStyle::MODE_SANDWICH );
		$this->assertSame( UtilityStyle::MODE_SANDWICH, UtilityStyle::get_mode() );

		\remove_all_filters( 'power_course_front_utility_style_mode' );
		\add_filter( 'power_course_front_utility_style_mode', static fn(): string => 'nonsense' );
		$this->assertSame( UtilityStyle::MODE_BEFORE, UtilityStyle::get_mode(), '不合法的值應退回自動判斷的結果' );
	}

	/**
	 * @test
	 * @group edge
	 * 重複觸發（例如別的外掛又跑一次 wp_enqueue_scripts）不會重複加相依
	 */
	public function test_重複呼叫不重複加相依(): void {
		$this->skip_if_front_css_missing();
		$this->use_fake_powerhouse_css( $this->own_front_css() );
		$this->register_fake_powerhouse_front();

		UtilityStyle::enqueue();
		UtilityStyle::enqueue();

		$this->assertSame(
			[ UtilityStyle::COMPONENT_HANDLE, UtilityStyle::UTILITY_HANDLE ],
			\wp_styles()->registered[ UtilityStyle::POWERHOUSE_HANDLE ]->deps
		);
		$this->assertSame( [], \wp_styles()->registered[ UtilityStyle::COMPONENT_HANDLE ]->deps, '元件 CSS 本身不可相依 powerhouse_front，否則會形成循環相依' );
		$this->assertSame( (string) filemtime( Plugin::$dir . self::FRONT_CSS ), \wp_styles()->registered[ UtilityStyle::UTILITY_HANDLE ]->ver, 'ver 用檔案 mtime，build 後自動 cache bust' );
	}

	/**
	 * @test
	 * @group edge
	 * Powerhouse 沒註冊 powerhouse_front 時，兩份照樣載入且能輸出（不能被未註冊的相依拖累）
	 */
	public function test_沒有powerhouse_front時仍載入且能輸出(): void {
		$this->skip_if_front_css_missing();

		$this->assertFalse( \wp_style_is( UtilityStyle::POWERHOUSE_HANDLE, 'registered' ), '前置條件：乾淨的 WP_Styles 不應有 powerhouse_front' );

		UtilityStyle::enqueue();

		$html = $this->capture_output(
			static function (): void {
				\wp_styles()->do_items();
			}
		);
		$this->assert_order( $html, [ UtilityStyle::COMPONENT_HANDLE, UtilityStyle::UTILITY_HANDLE ], '沒有 powerhouse_front 時兩份都要自己載入' );
		$this->assertSame( [], \wp_styles()->registered[ UtilityStyle::UTILITY_HANDLE ]->deps, '沒有 powerhouse_front 時不可宣告相依，否則 WP 會整個略過不輸出' );
	}

	/**
	 * @test
	 * @group edge
	 * 抽選擇器：最外層逗號才拆、:is() 與跳脫的逗號不拆、at-rule 不算選擇器
	 */
	public function test_抽選擇器的拆分規則(): void {
		$css = '@layer tailwind{*,:after{--tw-a:0}}#tw .a,#tw :is(.b,.c){x:1}@media (min-width:640px){#tw .sm\:d{x:2}}#tw .e\,f{x:3}';

		$this->assertSame(
			[ '*', ':after', '#tw .a', '#tw :is(.b,.c)', '#tw .sm\:d', '#tw .e\,f' ],
			UtilityStyle::extract_selectors( $css )
		);
		// `#tw .flex` 不可被當成 `#tw .flex-col` 的一部分
		$this->assertSame( 1, UtilityStyle::count_missing( '#tw .flex-col{x:1}', [ '#tw .flex' ] ) );
		$this->assertSame( 0, UtilityStyle::count_missing( '#tw .flex,#tw .grid{x:1}', [ '#tw .flex', '#tw .grid' ] ) );
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

			// 主題色由 Powerhouse 依站長設定輸出；我們若也輸出，排在後面時會把主題色蓋回預設
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
		\wp_register_style( UtilityStyle::POWERHOUSE_HANDLE, 'https://example.com/powerhouse/front.min.css', [], '3.6.0' );
	}

	/**
	 * 讓 UtilityStyle 拿指定內容當成 Powerhouse 的 front.min.css 判斷完整度
	 *
	 * @param string $content 假的 Powerhouse CSS 內容
	 */
	private function use_fake_powerhouse_css( string $content ): void {
		$this->fake_powerhouse_css = (string) tempnam( \get_temp_dir(), 'pc-ph-front-' );
		file_put_contents( $this->fake_powerhouse_css, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$path = $this->fake_powerhouse_css;
		\add_filter( 'power_course_powerhouse_front_css_path', static fn(): string => $path );
	}

	/**
	 * 我們自產的兩份前台 CSS 接在一起（當成「完整的 Powerhouse CSS」用）
	 *
	 * @return string
	 */
	private function own_front_css(): string {
		return $this->read_css( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' ) . $this->read_css( self::FRONT_CSS, 'pnpm run build:wp' );
	}

	/**
	 * 分別在「Powerhouse 先 enqueue」與「後 enqueue」兩種情況下輸出前台 <link>
	 *
	 * 實際站台上 Powerhouse 在 wp_enqueue_scripts:-100、我們在 -10，是「先」；兩種都測，
	 * 確保順序來自相依關係而不是剛好的 enqueue 先後
	 *
	 * @return array<string, string> 情境 => 輸出的 HTML
	 */
	private function render_front_styles(): array {
		$result = [];
		foreach ( [ 'Powerhouse 先 enqueue' => true, 'Powerhouse 後 enqueue' => false ] as $scenario => $powerhouse_first ) {
			$GLOBALS['wp_styles'] = null;
			$this->register_fake_powerhouse_front();

			if ( $powerhouse_first ) {
				\wp_enqueue_style( UtilityStyle::POWERHOUSE_HANDLE );
			}
			UtilityStyle::enqueue();
			if ( ! $powerhouse_first ) {
				\wp_enqueue_style( UtilityStyle::POWERHOUSE_HANDLE );
			}

			$result[ $scenario ] = $this->capture_output(
				static function (): void {
					\wp_styles()->do_items();
				}
			);
		}
		return $result;
	}

	/**
	 * 斷言 handle 的 <link> 依指定順序出現
	 *
	 * @param string   $html    do_items() 的輸出
	 * @param string[] $handles 預期順序
	 * @param string   $message 失敗訊息
	 */
	private function assert_order( string $html, array $handles, string $message ): void {
		$positions = [];
		foreach ( $handles as $handle ) {
			$position = strpos( $html, "id='{$handle}-css'" );
			$this->assertNotFalse( $position, "{$message}（{$handle} 沒有輸出）" );
			$positions[] = $position;
		}

		$sorted = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, $message . '；預期順序：' . implode( ' → ', $handles ) );
	}

	/**
	 * 前台兩份 CSS 都要有建置產物
	 */
	private function skip_if_front_css_missing(): void {
		$this->skip_if_missing( self::FRONT_COMPONENTS_CSS, 'pnpm run build:wp' );
		$this->skip_if_missing( self::FRONT_CSS, 'pnpm run build:wp' );
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
