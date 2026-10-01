<?php
/**
 * 前台 React bundle 按需載入整合測試
 *
 * 背景：Bootstrap 原本把 enqueue_script() 掛在 wp_enqueue_scripts，
 * 前台每一頁（首頁、文章、短碼頁）都會載入整包 React bundle（JS 4MB+ / CSS 700KB+），
 * 但前台只有影片播放器（.pc-vidstack，App2）用得到。
 *
 * 規則：前台只在 components/video/vidstack 模板實際輸出播放器時才載入 bundle。
 *
 * @group smoke
 * @group performance
 */

declare( strict_types=1 );

namespace Tests\Integration\Bootstrap;

use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\Plugin;
use Tests\Integration\TestCase;

/**
 * Class FrontendEnqueueOnDemandTest
 * 驗證前台 React bundle 只在渲染影片播放器時載入
 */
class FrontendEnqueueOnDemandTest extends TestCase {

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接渲染模板與檢查 hook，不需額外依賴
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
	 * @group smoke
	 * wp_enqueue_scripts 上不再掛任何 Bootstrap callback（前台不全站載入 bundle）
	 */
	public function test_前台wp_enqueue_scripts不掛Bootstrap全站載入(): void {
		// 健全性檢查：Bootstrap 的 hook 確實有註冊，否則下面「沒掛」的斷言沒有意義
		$this->assertNotEmpty(
			$this->get_bootstrap_callbacks( 'admin_enqueue_scripts' ),
			'Bootstrap 應掛在 admin_enqueue_scripts，否則代表 Bootstrap 未初始化，本測試沒碰到被測對象'
		);

		$this->assertSame(
			[],
			$this->get_bootstrap_callbacks( 'wp_enqueue_scripts' ),
			'前台不得在 wp_enqueue_scripts 全站載入 React bundle，應由 vidstack 模板按需載入'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 渲染 vidstack 播放器模板時才 enqueue React bundle
	 */
	public function test_渲染vidstack模板時才載入bundle(): void {
		$this->skip_if_no_manifest();

		$this->assertFalse( \wp_script_is( Plugin::$kebab, 'enqueued' ), '渲染前不應已載入 bundle' );

		$html = $this->render_vidstack();

		$this->assertStringContainsString( 'pc-vidstack', $html, '模板應輸出播放器容器，否則本測試沒走到 enqueue' );
		$this->assertTrue( \wp_script_is( Plugin::$kebab, 'enqueued' ), '輸出播放器後應載入 React bundle' );
	}

	/**
	 * @test
	 * @group edge
	 * 影片資料不完整（輸出 404 模板、沒有播放器）時不載入 bundle
	 */
	public function test_影片缺id輸出404時不載入bundle(): void {
		$this->skip_if_no_manifest();

		$html = $this->render_vidstack( '' );

		$this->assertStringNotContainsString( 'pc-vidstack', $html );
		$this->assertFalse( \wp_script_is( Plugin::$kebab, 'enqueued' ), '沒有輸出播放器時不應載入 bundle' );
	}

	/**
	 * @test
	 * @group edge
	 * 同一頁多個播放器（例如試看影片輪播）只注入一次 localize 資料
	 */
	public function test_同頁多個播放器只注入一次localize資料(): void {
		$this->skip_if_no_manifest();

		$this->render_vidstack();
		$this->render_vidstack( 'M7lc1UVf-VE' );
		$this->render_vidstack( 'aqz-KE-bpKQ' );

		$raw = (string) \wp_scripts()->get_data( Plugin::$kebab, 'data' );

		$this->assertSame(
			1,
			substr_count( $raw, 'var ' . Plugin::$snake . '_data' ),
			'多個播放器只能 enqueue 一次，否則 power_course_data 會重複輸出'
		);
	}

	/**
	 * @test
	 * @group happy
	 * wp_head 輸出後才渲染播放器：bundle 與 CSS 改由 wp_footer 輸出
	 * （教室頁就是先 wp_head() 再渲染 body，這條路徑必須能動）
	 */
	public function test_wp_head之後才渲染播放器_bundle與CSS於footer輸出(): void {
		$this->skip_if_no_manifest();

		// 模擬 wp_head 已印出 head 區的樣式與腳本
		// 直接呼叫 do_items / do_head_items：wp_print_styles() 會觸發核心已棄用的 print_emoji_styles
		$head          = $this->capture_output(
			static function (): void {
				\wp_styles()->do_items();
				\wp_scripts()->do_head_items();
			}
		);
		$styles_before = \wp_styles()->queue;

		$this->render_vidstack();

		$late_styles = array_values( array_diff( \wp_styles()->queue, $styles_before ) );
		$footer      = $this->capture_output(
			static function (): void {
				\wp_print_footer_scripts();
			}
		);

		$script_id_pattern = '/id=[\'"]' . preg_quote( Plugin::$kebab, '/' ) . '-js[\'"]/';
		$this->assertDoesNotMatchRegularExpression( $script_id_pattern, $head, 'head 輸出時尚未渲染播放器，不應有 bundle' );
		$this->assertMatchesRegularExpression( $script_id_pattern, $footer, 'bundle 應在 footer 輸出' );
		$this->assertStringContainsString( 'var ' . Plugin::$snake . '_data', $footer, 'env 資料應隨 bundle 在 footer 輸出' );

		$this->assertNotEmpty( $late_styles, 'manifest 的 entry 應帶 CSS，否則本測試沒測到 late styles' );
		foreach ( $late_styles as $handle ) {
			$this->assertMatchesRegularExpression(
				'/id=[\'"]' . preg_quote( $handle, '/' ) . '-css[\'"]/',
				$footer,
				"head 之後才 enqueue 的 CSS（{$handle}）應由 print_late_styles() 補印在 footer"
			);
		}
	}

	/**
	 * 渲染 vidstack 模板並回傳 HTML
	 *
	 * @param string $video_id YouTube 影片 ID，空字串會走 404 模板
	 * @return string
	 */
	private function render_vidstack( string $video_id = 'dQw4w9WgXcQ' ): string {
		return (string) Plugin::load_template(
			'video/vidstack',
			[
				'video_info' => [
					'type' => 'youtube',
					'id'   => $video_id,
					'meta' => [],
				],
			],
			false
		);
	}

	/**
	 * 取得掛在指定 hook 上、屬於 Bootstrap 的 callback
	 *
	 * @param string $hook_name hook 名稱
	 * @return array<int, string> callback 描述（類別::方法）
	 */
	private function get_bootstrap_callbacks( string $hook_name ): array {
		global $wp_filter;

		$found = [];
		if ( ! isset( $wp_filter[ $hook_name ] ) ) {
			return $found;
		}

		foreach ( $wp_filter[ $hook_name ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( ! is_array( $function ) ) {
					continue;
				}
				$class = is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0];
				if ( Bootstrap::class === $class ) {
					$found[] = $class . '::' . (string) $function[1];
				}
			}
		}

		return $found;
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

	/**
	 * 沒有 build 產物（js/dist/manifest.json）時跳過：vite-for-wp 讀不到 manifest 就不會 enqueue
	 */
	private function skip_if_no_manifest(): void {
		if ( ! file_exists( Plugin::$dir . '/js/dist/manifest.json' ) ) {
			$this->markTestSkipped( '缺少 js/dist/manifest.json，請先 pnpm run build' );
		}
	}
}
