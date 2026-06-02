<?php
/**
 * 免費課程卡片「隱藏單堂課購買」整合測試
 * Issue #231 Bug #1：免費課程 + 隱藏單堂課 + 有銷售方案時，免費卡片仍顯示 → 隱藏失效
 *
 * 根因：卡片路由 single-product.php 在 is_free=yes 時載入 single-product-free.php 並 return，
 * 而 hide_single_course 的隱藏判斷只寫在 single-product-sale.php，免費卡片從未檢查此開關。
 *
 * 修訂後驗收標準（clarify Q1 A / Q7 A）：
 * - AC1：免費 + 隱藏 + 有方案 → 不顯示免費卡片
 * - AC2：免費 + 隱藏 + 無方案 → 也不顯示免費卡片（admin 刻意決策，不 fallback）
 * - AC3：免費 + 未開隱藏 → 免費卡片正常顯示（行為不變）
 *
 * @group course
 * @group free-course
 * @group happy
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;

/**
 * Class FreeCardHideTest
 * 測試免費課程卡片在 hide_single_course 開關下的渲染行為
 *
 * 渲染策略：透過 Plugin::load_template( 'card/single-product', ..., false ) 走完整卡片路由，
 * 捕捉輸出字串後斷言免費卡片 wrapper 是否存在。
 * 使用免費卡片 printf format 內的靜態 class「bg-base-100 shadow-lg rounded p-6」作為標記，
 * 此字串為 single-product-free.php 專屬、且不受語系翻譯影響（非 __() 字串）。
 */
class FreeCardHideTest extends TestCase {

	/** @var string 免費卡片 wrapper 的專屬標記（locale-independent） */
	private const FREE_CARD_MARKER = 'bg-base-100 shadow-lg rounded p-6';

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 無需額外依賴，直接渲染模板
	}

	/**
	 * 建立免費課程商品
	 *
	 * @param string $hide_single_course hide_single_course meta 值（'yes' | 'no'）
	 * @return \WC_Product 免費課程商品
	 */
	private function create_free_course_product( string $hide_single_course ): \WC_Product {
		$course_id = $this->create_course(
			[
				'post_title' => '免費課程測試',
				'_is_course' => 'yes',
				'price'      => '0',
			]
		);

		// 免費課程開關
		update_post_meta( $course_id, 'is_free', 'yes' );
		// 隱藏單堂課購買開關
		update_post_meta( $course_id, 'hide_single_course', $hide_single_course );

		$product = wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product::class, $product, '應能取得課程商品' );

		return $product;
	}

	/**
	 * 渲染卡片路由並回傳輸出字串
	 *
	 * @param \WC_Product $product 課程商品
	 * @return string 渲染輸出
	 */
	private function render_single_product_card( \WC_Product $product ): string {
		$output = Plugin::load_template(
			'card/single-product',
			[
				'product' => $product,
			],
			false
		);

		return (string) $output;
	}

	// ========== AC1 / AC2：免費 + 隱藏 → 不顯示免費卡片 ==========

	/**
	 * @test
	 * @group happy
	 * AC1：免費課程 + 隱藏單堂課（有/無方案皆同）→ 免費卡片不應渲染
	 *
	 * 此為 Red 斷言：修復前免費卡片無 hide 檢查，wrapper 會出現 → 失敗。
	 */
	public function test_免費課程開啟隱藏單堂課時不渲染免費卡片(): void {
		$product = $this->create_free_course_product( 'yes' );

		$output = $this->render_single_product_card( $product );

		$this->assertStringNotContainsString(
			self::FREE_CARD_MARKER,
			$output,
			'免費課程開啟「隱藏單堂課購買」時，免費卡片 wrapper 不應出現於輸出'
		);
	}

	/**
	 * @test
	 * @group edge
	 * AC2：免費課程 + 隱藏單堂課 + 完全沒有銷售方案 → 仍不渲染免費卡片
	 *
	 * 本測試不建立任何 bundle，等同於 0 已發佈方案的情境（admin 刻意決策，不 fallback）。
	 */
	public function test_免費課程隱藏且無方案時仍不渲染免費卡片(): void {
		$product = $this->create_free_course_product( 'yes' );

		$output = $this->render_single_product_card( $product );

		$this->assertStringNotContainsString(
			self::FREE_CARD_MARKER,
			$output,
			'免費課程 + 隱藏 + 無方案時，免費卡片 wrapper 不應出現（不 fallback 顯示）'
		);
	}

	// ========== AC3：免費 + 未開隱藏 → 正常顯示（回歸保護）==========

	/**
	 * @test
	 * @group happy
	 * AC3：免費課程未開啟隱藏單堂課 → 免費卡片正常渲染
	 *
	 * 回歸保護：確保修復不會誤把「未開 hide」的免費卡片也隱藏。
	 */
	public function test_免費課程未開啟隱藏時正常渲染免費卡片(): void {
		$product = $this->create_free_course_product( 'no' );

		$output = $this->render_single_product_card( $product );

		$this->assertStringContainsString(
			self::FREE_CARD_MARKER,
			$output,
			'免費課程未開啟「隱藏單堂課購買」時，免費卡片 wrapper 應正常出現'
		);
	}

	/**
	 * @test
	 * @group edge
	 * AC3 補強：hide_single_course meta 完全未設定（空字串）時，視同 'no'，免費卡片應顯示
	 */
	public function test_免費課程未設定hide_meta時視同未隱藏並渲染(): void {
		$course_id = $this->create_course(
			[
				'post_title' => '免費課程無hide_meta',
				'_is_course' => 'yes',
				'price'      => '0',
			]
		);
		update_post_meta( $course_id, 'is_free', 'yes' );
		// 刻意不設定 hide_single_course meta

		$product = wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		$output = $this->render_single_product_card( $product );

		$this->assertStringContainsString(
			self::FREE_CARD_MARKER,
			$output,
			'免費課程未設定 hide_single_course meta 時應視同未隱藏，免費卡片正常渲染'
		);
	}
}
