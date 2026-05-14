<?php
/**
 * Issue #224：公告卡片內文折疊行數 filter 整合測試
 *
 * Feature: specs/features/announcement/銷售頁公告卡片內文折疊.feature
 *
 * 對應 Rule：
 *   後置（顯示）- 預設行數為 3，並可透過
 *   apply_filters('pc_announcement_collapse_lines', 3) 客製。
 *
 * 此測試以 PHPUnit 驅動 SSR 模板，不負責瀏覽器互動（屬 E2E 範疇）。
 *
 * @group announcement
 * @group ui
 * @group edge
 */

declare( strict_types=1 );

namespace Tests\Integration\Announcement;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Announcement\Core\CPT;

/**
 * Class AnnouncementCollapseFilterTest
 *
 * 覆蓋 apply_filters('pc_announcement_collapse_lines', N) 的四個邊界：
 * - 預設 3 行
 * - filter 客製 5 行
 * - filter 回傳負數 → 收斂為 1
 * - filter 回傳非整數型別 → 強制轉整數
 */
final class AnnouncementCollapseFilterTest extends TestCase {

	/** @var int 課程（WooCommerce product）ID */
	private int $course_id;

	/** @var \WC_Product 課程對應的 WC_Product 物件 */
	private \WC_Product $product;

	/**
	 * 不需要額外注入依賴
	 */
	protected function configure_dependencies(): void {
		// no-op
	}

	public function set_up(): void {
		parent::set_up();
		// 建立課程商品
		$this->course_id = $this->create_course(
			[
				'post_title' => 'Issue #224 折疊測試課程',
			]
		);
		$product         = \wc_get_product( $this->course_id );
		if ( ! ( $product instanceof \WC_Product ) ) {
			$this->fail( '建立的 course 無法以 wc_get_product() 取得 WC_Product' );
		}
		$this->product = $product;

		// 建立一則 publish + visibility=public 的公告，內文 5 行（觸發折疊）
		$this->insert_public_announcement(
			[
				'post_title'   => 'Issue #224 折疊長公告',
				'post_content' => '<p>L1<br>L2<br>L3<br>L4<br>L5</p>',
			]
		);

		// 以管理員身分執行（避免 user permission 干擾 announcement query）
		\wp_set_current_user(
			$this->factory()->user->create( [ 'role' => 'administrator' ] )
		);
	}

	public function tear_down(): void {
		// 移除所有測試掛載的 filter，避免污染其他測試
		\remove_all_filters( 'pc_announcement_collapse_lines' );
		parent::tear_down();
	}

	// =============================================================
	// 預設情境
	// =============================================================

	/**
	 * 預設無 filter 掛載時，折疊行數應為 3
	 *
	 * @test
	 * @group happy
	 */
	public function test_default_collapse_lines_is_3(): void {
		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'data-collapse-lines="3"',
			$html,
			'預設應輸出 data-collapse-lines="3"（apply_filters 預設值）'
		);
	}

	/**
	 * 預設情境也應同步輸出 CSS 變數 --pc-collapse-lines: 3
	 *
	 * @test
	 * @group happy
	 */
	public function test_default_emits_css_variable(): void {
		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'--pc-collapse-lines: 3',
			$html,
			'預設應輸出 CSS 變數 --pc-collapse-lines: 3（給 line-clamp 用）'
		);
	}

	// =============================================================
	// Filter 客製情境
	// =============================================================

	/**
	 * filter 回傳 5 時，data-collapse-lines 應為 5
	 *
	 * @test
	 * @group happy
	 */
	public function test_filter_customizes_to_5_lines(): void {
		\add_filter(
			'pc_announcement_collapse_lines',
			static function (): int {
				return 5;
			}
		);

		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'data-collapse-lines="5"',
			$html,
			'filter 回傳 5 時，輸出應為 data-collapse-lines="5"'
		);
		$this->assertStringContainsString(
			'--pc-collapse-lines: 5',
			$html,
			'filter 回傳 5 時，CSS 變數也應同步'
		);
	}

	// =============================================================
	// Filter 邊界 / 防呆情境
	// =============================================================

	/**
	 * filter 回傳負數時應收斂為 1（避免 line-clamp: 0 或負數）
	 *
	 * @test
	 * @group edge
	 */
	public function test_filter_negative_value_clamps_to_1(): void {
		\add_filter(
			'pc_announcement_collapse_lines',
			static function (): int {
				return -10;
			}
		);

		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'data-collapse-lines="1"',
			$html,
			'filter 回傳負數應收斂為 1（max(1, (int) $value)）'
		);
		$this->assertStringNotContainsString(
			'data-collapse-lines="-10"',
			$html,
			'負數絕對不可直接輸出（會導致 CSS line-clamp 失效）'
		);
	}

	/**
	 * filter 回傳 0 時也應收斂為 1
	 *
	 * @test
	 * @group edge
	 */
	public function test_filter_zero_value_clamps_to_1(): void {
		\add_filter(
			'pc_announcement_collapse_lines',
			static function (): int {
				return 0;
			}
		);

		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'data-collapse-lines="1"',
			$html,
			'filter 回傳 0 應收斂為 1（line-clamp 至少 1 行）'
		);
	}

	/**
	 * filter 回傳字串（非整數）時應強制轉整數
	 *
	 * @test
	 * @group edge
	 */
	public function test_filter_string_value_coerces_to_int(): void {
		\add_filter(
			'pc_announcement_collapse_lines',
			static function (): string {
				return '4.7';
			}
		);

		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'data-collapse-lines="4"',
			$html,
			'filter 回傳 "4.7" 應 cast 為整數 4'
		);
		$this->assertStringNotContainsString(
			'data-collapse-lines="4.7"',
			$html,
			'非整數字串不可直接輸出到 HTML（PHPStan / output 安全）'
		);
	}

	// =============================================================
	// 結構性斷言：模板必含關鍵 marker class
	// =============================================================

	/**
	 * 公告卡片內文必須包含 .pc-announcement-content 容器
	 * （供 JS 偵測 scrollHeight > clientHeight）
	 *
	 * @test
	 * @group happy
	 */
	public function test_template_contains_collapse_content_container(): void {
		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'pc-announcement-content',
			$html,
			'模板必須包含 .pc-announcement-content marker class 供 JS 偵測'
		);
	}

	/**
	 * 公告卡片必須包含 .pc-announcement-toggle 切換容器
	 * 且預設帶 hidden 屬性（PHP 端 SSR 時保持隱藏，待 JS 偵測超出後才顯示）
	 *
	 * @test
	 * @group happy
	 */
	public function test_template_contains_toggle_container_with_hidden(): void {
		$html = $this->render_announcement_template();

		$this->assertStringContainsString(
			'pc-announcement-toggle',
			$html,
			'模板必須輸出 .pc-announcement-toggle 容器'
		);
		$this->assertMatchesRegularExpression(
			'/class="[^"]*pc-announcement-toggle[^"]*"[^>]*\bhidden\b/',
			$html,
			'.pc-announcement-toggle 預設必須帶 hidden 屬性，避免 FOUC（R1 風險緩解）'
		);
	}

	/**
	 * 內文容器必須有 aria-expanded="false" 屬性（無障礙）
	 *
	 * @test
	 * @group happy
	 */
	public function test_content_container_has_aria_expanded_false(): void {
		$html = $this->render_announcement_template();

		$this->assertMatchesRegularExpression(
			'/class="[^"]*pc-announcement-content[^"]*"[^>]*aria-expanded="false"/',
			$html,
			'.pc-announcement-content 必須帶 aria-expanded="false" 預設折疊狀態'
		);
	}

	/**
	 * 切換按鈕必須是 <button type="button">（無障礙，可鍵盤聚焦）
	 *
	 * @test
	 * @group happy
	 */
	public function test_toggle_button_is_real_button_element(): void {
		$html = $this->render_announcement_template();

		$this->assertMatchesRegularExpression(
			'/<button\b[^>]*type="button"[^>]*>[^<]*Expand content/',
			$html,
			'切換元素必須是 <button type="button">（不可用 <div>）且文字為 Expand content'
		);
	}

	// =============================================================
	// Helper：渲染 announcement template 取得 HTML 字串
	// =============================================================

	/**
	 * 插入一則公開公告（visibility=public + publish）
	 *
	 * @param array<string, mixed> $args 參數
	 * @return int post id
	 */
	private function insert_public_announcement( array $args ): int {
		$defaults = [
			'post_type'   => CPT::POST_TYPE,
			'post_status' => 'publish',
			'post_parent' => $this->course_id,
			'post_title'  => 'Test announcement',
		];
		$args     = array_merge( $defaults, $args );
		$id       = $this->factory()->post->create( $args );
		\update_post_meta( $id, 'parent_course_id', $this->course_id );
		\update_post_meta( $id, 'visibility', 'public' );
		return $id;
	}

	/**
	 * 渲染 announcement.php 取得 HTML 字串
	 *
	 * 直接以 require + ob_start 模擬 Plugin::load_template 行為，
	 * 將 $args 設為 local scope 變數，符合 template 期望的 wp_parse_args($args, ...) 介面。
	 */
	private function render_announcement_template(): string {
		$template = WP_PLUGIN_DIR . '/power-course/inc/templates/pages/course-product/announcement.php';
		if ( ! is_readable( $template ) ) {
			// CI / wp-env 不一定把外掛符號連結到 wp-content/plugins/power-course
			// 退而用 repo 內絕對路徑
			$template = dirname( __DIR__, 3 ) . '/inc/templates/pages/course-product/announcement.php';
		}
		if ( ! is_readable( $template ) ) {
			$this->fail( '找不到 announcement.php 模板' );
		}

		$args = [ 'product' => $this->product ];

		ob_start();
		try {
			require $template;
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
		$html = (string) ob_get_clean();

		// 標準化：移除多餘換行 / 空白讓 regex 更穩定
		return preg_replace( '/\s+/', ' ', $html ) ?? $html;
	}
}
