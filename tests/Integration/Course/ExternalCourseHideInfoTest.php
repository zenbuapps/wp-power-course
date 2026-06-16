<?php
/**
 * 外部課程隱藏「課程資訊」區塊整合測試（Issue #219）
 *
 * 背景：外部課程（WC_Product_External）沒有站內章節、學員、觀看期限與開課時間，
 * 過去 body.php 對每個統計項無條件塞「-」，導致前台一定出現一整排橫線。
 *
 * 站長決策（Issue #219 留言 2026-06-16）：
 *   「不用做成開關，如果是外部課程，就整個區域隱藏。」
 *
 * 修訂後驗收標準：
 * - AC1：外部課程銷售頁完全不渲染「課程資訊」整塊（含標題），不出現任何 - 佔位符。
 * - AC2：外部課程殘留站內 show_* meta（部分為 yes）時，前台仍整塊隱藏（無 migration）。
 * - AC3：站內課程「課程資訊」區塊維持原行為，仍正常顯示。
 * - AC4：站內課程逐項 show_* 開關行為不變（關閉某項僅隱藏該項，區塊仍在）。
 *
 * 渲染策略：透過 Plugin::load_template( 'course-product/body', ..., false ) 渲染整段 body，
 * 捕捉輸出字串後斷言「課程資訊」區塊是否存在。
 *
 * 標記策略：
 * - 結構標記 COURSE_INFO_MARKER 取自 course-product/info.php 內每個統計項的圖示 badge
 *   class「pc-badge pc-badge-primary size-8」，此字串為課程資訊區塊專屬、不受語系翻譯影響。
 * - 區塊標題「課程資訊」為 zh_TW 測試語系（tests/bootstrap.php 強制 locale=zh_TW）下
 *   typography/title 的輸出，作為輔助斷言。
 *
 * @group course
 * @group external-course
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;

/**
 * Class ExternalCourseHideInfoTest
 * 測試外部課程銷售頁的「課程資訊」區塊渲染行為
 */
class ExternalCourseHideInfoTest extends TestCase {

	/** @var string 課程資訊區塊統計項的專屬結構標記（locale-independent） */
	private const COURSE_INFO_MARKER = 'pc-badge pc-badge-primary size-8';

	/** @var string 課程資訊區塊標題（zh_TW 測試語系） */
	private const COURSE_INFO_TITLE = '課程資訊';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 無需額外依賴，直接渲染模板
	}

	/**
	 * 建立外部課程商品（WC_Product_External）
	 *
	 * @param array<string, string> $extra_meta 額外要寫入的 meta（如殘留的 show_* 開關）
	 * @return \WC_Product_External 外部課程商品
	 */
	private function create_external_course( array $extra_meta = [] ): \WC_Product_External {
		$course_id = $this->create_course(
			[
				'post_title' => '外部課程測試',
				'_is_course' => 'yes',
			]
		);

		// 設定 product_type taxonomy 為 external，讓 wc_get_product() 回傳 WC_Product_External
		\wp_set_object_terms( $course_id, 'external', 'product_type' );
		update_post_meta( $course_id, '_product_url', 'https://hahow.in/courses/12345' );
		update_post_meta( $course_id, '_button_text', '前往 Hahow 上課' );

		foreach ( $extra_meta as $key => $value ) {
			update_post_meta( $course_id, $key, $value );
		}

		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product_External::class, $product, 'wc_get_product() 應回傳 WC_Product_External 實例' );

		return $product;
	}

	/**
	 * 建立站內課程商品（WC_Product_Simple）
	 *
	 * @param array<string, string> $extra_meta 額外要寫入的 meta（如逐項 show_* 開關）
	 * @return \WC_Product 站內課程商品
	 */
	private function create_simple_course( array $extra_meta = [] ): \WC_Product {
		$course_id = $this->create_course(
			[
				'post_title' => '站內課程測試',
				'_is_course' => 'yes',
			]
		);

		\wp_set_object_terms( $course_id, 'simple', 'product_type' );

		foreach ( $extra_meta as $key => $value ) {
			update_post_meta( $course_id, $key, $value );
		}

		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product::class, $product, '應能取得站內課程商品' );

		return $product;
	}

	/**
	 * 渲染課程銷售頁 body 並回傳輸出字串
	 *
	 * @param \WC_Product $product 課程商品
	 * @return string 渲染輸出
	 */
	private function render_body( \WC_Product $product ): string {
		$output = Plugin::load_template(
			'course-product/body',
			[
				'product' => $product,
			],
			false
		);

		return (string) $output;
	}

	// ========== AC1：外部課程整塊隱藏 ==========

	/**
	 * @test
	 * @group happy
	 * AC1：全新外部課程銷售頁不應渲染「課程資訊」整塊區域
	 *
	 * Red 斷言：修復前 body.php 對外部課程仍建立統計項並渲染標題 → 此斷言失敗。
	 */
	public function test_外部課程不渲染課程資訊區塊(): void {
		$product = $this->create_external_course();

		$output = $this->render_body( $product );

		$this->assertStringNotContainsString(
			self::COURSE_INFO_MARKER,
			$output,
			'外部課程銷售頁不應出現課程資訊區塊的統計項'
		);
		$this->assertStringNotContainsString(
			self::COURSE_INFO_TITLE,
			$output,
			'外部課程銷售頁不應出現「課程資訊」區塊標題'
		);
	}

	/**
	 * @test
	 * @group happy
	 * AC1：外部課程銷售頁不應出現任何 "-" 佔位符（連帶移除佔位邏輯）
	 */
	public function test_外部課程不出現橫線佔位符(): void {
		$product = $this->create_external_course();

		$output = $this->render_body( $product );

		// 課程資訊統計項的值在修復前為 "-"，包在 font-semibold 容器內；
		// 區塊整塊隱藏後，連帶不會再出現此佔位輸出。
		$this->assertStringNotContainsString(
			'<div class="font-semibold">' . "\n\t\t\t\t\t\t\t" . '-',
			$output,
			'外部課程銷售頁不應出現 "-" 佔位統計值'
		);
	}

	// ========== AC2：殘留 meta 仍隱藏（無 migration）==========

	/**
	 * @test
	 * @group edge
	 * AC2：外部課程殘留站內預設 show_* meta（部分為 yes）時，前台仍整塊隱藏
	 *
	 * 守衛在 show_* 判斷之前，殘留 meta 不影響隱藏結果，故無需資料 migration。
	 */
	public function test_外部課程殘留show_meta仍整塊隱藏(): void {
		$product = $this->create_external_course(
			[
				'show_total_student'   => 'yes',
				'show_course_schedule' => 'yes',
				'show_course_chapters' => 'yes',
			]
		);

		$output = $this->render_body( $product );

		$this->assertStringNotContainsString(
			self::COURSE_INFO_MARKER,
			$output,
			'外部課程即使殘留 show_* = yes meta，仍不應出現課程資訊區塊'
		);
		$this->assertStringNotContainsString(
			self::COURSE_INFO_TITLE,
			$output,
			'外部課程即使殘留 show_* = yes meta，仍不應出現「課程資訊」標題'
		);
	}

	// ========== AC3 / AC4：站內課程回歸保護 ==========

	/**
	 * @test
	 * @group happy
	 * AC3：站內課程銷售頁仍正常顯示「課程資訊」區塊（回歸保護）
	 */
	public function test_站內課程仍顯示課程資訊區塊(): void {
		$product = $this->create_simple_course();

		$output = $this->render_body( $product );

		$this->assertStringContainsString(
			self::COURSE_INFO_MARKER,
			$output,
			'站內課程銷售頁應正常出現課程資訊區塊統計項'
		);
		$this->assertStringContainsString(
			self::COURSE_INFO_TITLE,
			$output,
			'站內課程銷售頁應出現「課程資訊」區塊標題'
		);
	}

	/**
	 * @test
	 * @group edge
	 * AC4：站內課程逐項 show_* 開關行為不變
	 * 關閉某統計項時區塊仍在，僅該項被隱藏。
	 *
	 * 全部統計項中保留「章節數量」（show_course_chapters=yes），
	 * 確認區塊仍渲染（逐項開關邏輯未被本次修改誤動）。
	 */
	public function test_站內課程逐項開關行為不變(): void {
		$product = $this->create_simple_course(
			[
				'show_course_complete' => 'no',
				'show_course_schedule' => 'no',
				'show_course_time'     => 'no',
				'show_course_chapters' => 'yes',
				'show_course_limit'    => 'no',
				'show_total_student'   => 'no',
			]
		);

		$output = $this->render_body( $product );

		// 至少保留一項（章節數量）→ 區塊與標題仍應渲染
		$this->assertStringContainsString(
			self::COURSE_INFO_MARKER,
			$output,
			'站內課程保留任一統計項時，課程資訊區塊仍應渲染'
		);
		$this->assertStringContainsString(
			self::COURSE_INFO_TITLE,
			$output,
			'站內課程保留任一統計項時，「課程資訊」標題仍應渲染'
		);
	}
}
