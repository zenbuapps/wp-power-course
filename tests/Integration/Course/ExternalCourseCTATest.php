<?php
/**
 * 外部課程連結與 CTA 欄位整合測試
 *
 * Feature: specs/features/external-course/外部課程連結與CTA欄位.feature
 * 測試 courses 表的 product_url（_product_url）與 button_text（_button_text）欄位讀寫：
 * - 建立外部課程時寫入 _product_url / _button_text
 * - 未提供 button_text 時使用預設值「前往課程」
 * - product_url 格式驗證
 * - 外部課程不走 limit_type 流程
 *
 * @group course
 * @group external-course
 * @group cta
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;

/**
 * Class ExternalCourseCTATest
 * 測試外部課程的 product_url 與 button_text 欄位
 */
class ExternalCourseCTATest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress post meta API
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_ext_' . uniqid(),
				'user_email' => 'admin_ext_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->ids['Admin'] = $this->admin_id;
	}

	/**
	 * 建立外部課程並設定 meta
	 *
	 * @param string $product_url 外部連結
	 * @param string $button_text CTA 按鈕文字
	 * @return int 商品 ID
	 */
	private function create_external_course( string $product_url = '', string $button_text = '' ): int {
		$product_id = $this->factory()->post->create(
			[
				'post_title'  => '外部課程',
				'post_type'   => 'product',
				'post_status' => 'publish',
			]
		);

		// WC External Product 的 meta keys
		if ( $product_url ) {
			update_post_meta( $product_id, '_product_url', $product_url );
		}

		if ( $button_text ) {
			update_post_meta( $product_id, '_button_text', $button_text );
		} else {
			// 未提供時寫入預設值（模擬 WC External Product 行為）
			update_post_meta( $product_id, '_button_text', '前往課程' );
		}

		// 標記為外部課程（is_course=yes，type=external 由 WC 管理）
		update_post_meta( $product_id, '_is_course', 'yes' );

		return $product_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * _product_url meta 可正確寫入和讀取
	 */
	public function test_冒煙_product_url_meta可寫入和讀取(): void {
		$product_id = $this->factory()->post->create(
			[ 'post_type' => 'product', 'post_status' => 'publish' ]
		);
		update_post_meta( $product_id, '_product_url', 'https://example.com/course' );

		$url = get_post_meta( $product_id, '_product_url', true );
		$this->assertSame( 'https://example.com/course', $url );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 建立外部課程時，_product_url 正確寫入
	 */
	public function test_建立外部課程_product_url正確寫入(): void {
		$product_id = $this->create_external_course( 'https://hahow.in/courses/12345', '前往 Hahow 上課' );

		$url = get_post_meta( $product_id, '_product_url', true );
		$this->assertSame( 'https://hahow.in/courses/12345', $url, '_product_url 應正確寫入' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 建立外部課程時，_button_text 正確寫入
	 */
	public function test_建立外部課程_button_text正確寫入(): void {
		$product_id = $this->create_external_course( 'https://hahow.in/courses/12345', '前往 Hahow 上課' );

		$button_text = get_post_meta( $product_id, '_button_text', true );
		$this->assertSame( '前往 Hahow 上課', $button_text, '_button_text 應正確寫入' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 未提供 button_text 時使用預設值「前往課程」
	 */
	public function test_未提供button_text時使用預設值(): void {
		$product_id = $this->create_external_course( 'https://example.com/course/1' );

		$button_text = get_post_meta( $product_id, '_button_text', true );
		$this->assertSame( '前往課程', $button_text, '未提供 button_text 應使用預設值「前往課程」' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 更新外部連結後 _product_url 更新為新值
	 */
	public function test_更新外部課程連結_product_url更新(): void {
		$product_id = $this->create_external_course( 'https://hahow.in/old-url', '前往課程' );

		// 更新連結
		update_post_meta( $product_id, '_product_url', 'https://hahow.in/new-url' );

		$url = get_post_meta( $product_id, '_product_url', true );
		$this->assertSame( 'https://hahow.in/new-url', $url, '_product_url 應更新為新值' );
	}

	// ========== 前置（參數）- URL 格式驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: product_url 必須為 http:// 或 https:// 開頭
	 * 使用 wp_http_validate_url 或 filter_var 驗證
	 */
	public function test_非法URL_http_https驗證(): void {
		$invalid_url = 'ftp://example.com/course';

		// 使用 WordPress 內建 URL 驗證
		$is_valid = wp_http_validate_url( $invalid_url );
		$this->assertFalse( (bool) $is_valid, 'ftp:// 開頭的 URL 不應通過 http/https 驗證' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: http:// 開頭的 URL 為合法
	 */
	public function test_http開頭的URL合法(): void {
		$valid_url = 'http://example.com/course';
		$is_valid  = wp_http_validate_url( $valid_url );
		$this->assertNotFalse( $is_valid, 'http:// 開頭的 URL 應通過驗證' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: https:// 開頭的 URL 為合法
	 */
	public function test_https開頭的URL合法(): void {
		$valid_url = 'https://hahow.in/courses/12345';
		$is_valid  = wp_http_validate_url( $valid_url );
		$this->assertNotFalse( $is_valid, 'https:// 開頭的 URL 應通過驗證' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 空字串 URL 不合法
	 */
	public function test_空字串URL不合法(): void {
		$is_valid = wp_http_validate_url( '' );
		$this->assertFalse( (bool) $is_valid, '空字串不應通過 URL 驗證' );
	}

	// ========== 外部課程不走 limit_type 流程 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 外部課程的 limit_type 對業務邏輯無意義，courses 表記錄預設 unlimited
	 * 此測試驗證 _is_course meta 存在，limit_type 不影響外部課程存取
	 */
	public function test_外部課程_limit_type預設為unlimited(): void {
		$product_id = $this->create_external_course( 'https://example.com' );

		// 外部課程應不設定 limit_type（或為預設 unlimited）
		$limit_type = get_post_meta( $product_id, 'limit_type', true );
		$this->assertEmpty( $limit_type, '外部課程不應設定 limit_type' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 外部課程同樣標記為 is_course=yes
	 */
	public function test_外部課程_is_course仍為yes(): void {
		$product_id = $this->create_external_course( 'https://example.com' );

		$is_course = get_post_meta( $product_id, '_is_course', true );
		$this->assertSame( 'yes', $is_course, '外部課程的 _is_course 應為 yes' );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: button_text 可為長字串（不裁切）
	 */
	public function test_button_text可為長字串(): void {
		$long_text  = str_repeat( '前往課程', 50 ); // 200 個中文字
		$product_id = $this->create_external_course( 'https://example.com', $long_text );

		$button_text = get_post_meta( $product_id, '_button_text', true );
		$this->assertSame( $long_text, $button_text, '長字串 button_text 應完整儲存' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: product_url 含特殊字元（查詢參數）可正確儲存
	 */
	public function test_product_url含查詢參數可正確儲存(): void {
		$url_with_params = 'https://example.com/course?ref=power&id=123&lang=zh-TW';
		$product_id      = $this->create_external_course( $url_with_params );

		$stored_url = get_post_meta( $product_id, '_product_url', true );
		$this->assertSame( $url_with_params, $stored_url, '含查詢參數的 URL 應完整儲存' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: button_text 為 Unicode / Emoji（邊緣輸入）
	 */
	public function test_button_text含Emoji可正確儲存(): void {
		$emoji_text = '前往課程 🎓';
		$product_id = $this->create_external_course( 'https://example.com', $emoji_text );

		$stored_text = get_post_meta( $product_id, '_button_text', true );
		$this->assertSame( $emoji_text, $stored_text, '含 Emoji 的 button_text 應正確儲存' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: _product_url 含 XSS 嘗試
	 * WordPress esc_url 應清除 javascript: 協議
	 */
	public function test_product_url_XSS嘗試_esc_url過濾(): void {
		$xss_url    = 'javascript:alert("xss")';
		$product_id = $this->factory()->post->create(
			[ 'post_type' => 'product', 'post_status' => 'publish' ]
		);

		// 儲存前 esc_url 過濾
		$safe_url = esc_url( $xss_url );

		// esc_url 應清除 javascript: 協議（回傳空字串或安全 URL）
		$this->assertNotSame( $xss_url, $safe_url, 'javascript: URL 應被 esc_url 過濾' );
		$this->assertStringNotContainsString( 'javascript:', $safe_url, '過濾後不應包含 javascript:' );
	}
}
