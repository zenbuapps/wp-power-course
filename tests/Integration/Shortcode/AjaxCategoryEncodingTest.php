<?php
/**
 * AJAX 翻頁端點中文分類／標籤過濾迴歸測試（Issue #254）
 *
 * Bug: [pc_courses] AJAX 翻頁後中文分類／標籤過濾失效
 *      （sanitize_text_field 剝空 percent-encoded slug）
 *
 * 根因：sanitize_text_field() 會把 percent-encoded octets（例如中文分類
 * 「前端」slug 為 %e5%89%8d%e7%ab%af）當非法字元剝除，清成空字串；
 * category/tag 被清空後 General::get_courses_page() 判定為「未過濾」，
 * 回傳全部課程。修復方式：Shortcode::sanitize_slug_list() 改逐一對逗號
 * 分隔的 slug 清單套用 sanitize_title()（WordPress 產生 term slug 用的
 * 同一函式），正確保留 %xx。
 *
 * @group shortcode
 * @group api
 * @group issue-254
 */

declare( strict_types=1 );

namespace Tests\Integration\Shortcode;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Shortcode;

/**
 * Class AjaxCategoryEncodingTest
 */
class AjaxCategoryEncodingTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/**
	 * 初始化依賴（本測試直接呼叫方法，無需注入 repository/service）
	 */
	protected function configure_dependencies(): void {
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_i254_' . uniqid(),
				'user_email' => 'admin_i254_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $this->admin_id );
	}

	/**
	 * 透過 Reflection 呼叫 private 方法 Shortcode::sanitize_slug_list()。
	 *
	 * @param string $value 輸入字串。
	 * @return string
	 */
	private function invoke_sanitize_slug_list( string $value ): string {
		$shortcode = Shortcode::instance();
		$method    = new \ReflectionMethod( Shortcode::class, 'sanitize_slug_list' );
		$method->setAccessible( true );
		/** @var string $result */
		$result = $method->invoke( $shortcode, $value );
		return $result;
	}

	// ========== 核心迴歸：sanitize_slug_list() ==========

	/**
	 * @test
	 * @group happy
	 * @group issue-254
	 * Rule: 中文分類的 percent-encoded slug 必須被保留，不能被剝成空字串
	 */
	public function test_迴歸_中文percent_encoded_slug不被剝空(): void {
		$encoded = '%e5%89%8d%e7%ab%af'; // 「前端」的 percent-encoded slug

		// 對照組：修復前用的 sanitize_text_field() 會把這個字串剝成空字串（Issue #254 根因）
		$this->assertSame(
			'',
			\sanitize_text_field( $encoded ),
			'（對照組）sanitize_text_field() 對 percent-encoded 字串的既有剝除行為'
		);

		// 修復後：sanitize_slug_list() 內部改用 sanitize_title()，必須保留原字串
		$this->assertSame(
			$encoded,
			$this->invoke_sanitize_slug_list( $encoded ),
			'sanitize_slug_list() 必須保留 percent-encoded slug，不可被剝空'
		);
	}

	/**
	 * @test
	 * @group happy
	 * @group issue-254
	 * Rule: 逗號分隔的多個 slug（中英文混合）皆須逐一保留
	 */
	public function test_迴歸_逗號分隔中英文混合slug清單(): void {
		$input = 'shirts,%e5%89%8d%e7%ab%af';

		$this->assertSame(
			'shirts,%e5%89%8d%e7%ab%af',
			$this->invoke_sanitize_slug_list( $input )
		);
	}

	/**
	 * @test
	 * @group edge
	 * @group issue-254
	 * Rule: 空字串輸入應維持回傳空字串（向下相容 General::get_courses_page 的
	 *       「空字串 unset，避免污染查詢」邏輯）
	 */
	public function test_邊緣_空字串輸入回傳空字串(): void {
		$this->assertSame( '', $this->invoke_sanitize_slug_list( '' ) );
	}

	/**
	 * @test
	 * @group security
	 * @group issue-254
	 * Rule: 惡意輸入不應崩潰，且危險字元不應原樣保留
	 */
	public function test_安全_XSS輸入不崩潰且被過濾(): void {
		$result = $this->invoke_sanitize_slug_list( '<script>alert(1)</script>' );

		$this->assertIsString( $result );
		$this->assertStringNotContainsString( '<script>', $result );
	}
}
