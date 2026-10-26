<?php
/**
 * 教室頁首「前往下一個章節」按鈕整合測試（Issue #287）
 *
 * 背景：`inc/templates/pages/classroom/header.php` 用 `$index ? … : false` 判斷目前章節的位置，
 * 第一章的 `array_search()` 回傳 0 被當成 false → 第一章永遠顯示「No more chapters」。
 *
 * 規則：
 * - 後面還有章節（含第一章）→ 顯示「Go to next chapter」並連到下一章
 * - 最後一章、或目前章節不在課程章節清單內 → 顯示停用的「No more chapters」
 *
 * @group happy
 * @group issue-287
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use J7\PowerCourse\Plugin;
use Tests\Integration\TestCase;

/**
 * Class ClassroomHeaderNextChapterTest
 * 驗證教室頁首的下一章節按鈕
 */
class ClassroomHeaderNextChapterTest extends TestCase {

	/** @var int 課程 ID */
	private int $course_id;

	/** @var array<int, int> 依順序排列的三個章節 ID */
	private array $chapter_ids = [];

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接渲染模板，不需額外依賴
	}

	/**
	 * 每個測試前：建立一門有三個章節的課程
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id   = $this->create_course( [ 'post_title' => 'Issue287 課程' ] );
		$this->chapter_ids = [];
		foreach ( [ '第一章', '第二章', '第三章' ] as $i => $title ) {
			$this->chapter_ids[] = $this->create_chapter(
				$this->course_id,
				[
					'post_title' => $title,
					'menu_order' => $i,
				]
			);
		}

		// 頁首會讀目前學員的章節完成狀態，必須有登入的學員
		$student_id = $this->factory()->user->create( [ 'role' => 'customer' ] );
		$this->enroll_user_to_course( $student_id, $this->course_id );
		\wp_set_current_user( $student_id );

		// get_flatten_post_ids() 以 course_id 為 key 快取在 prev_next group，跨測試會殘留
		\wp_cache_flush();
	}

	/**
	 * 每個測試後：清掉模板讀的全域變數
	 */
	public function tear_down(): void {
		unset( $GLOBALS['course'], $GLOBALS['chapter'] );

		parent::tear_down();
	}

	/**
	 * @test
	 * @group smoke
	 * 第一章（index 0）後面還有章節：要顯示「前往下一個章節」並連到第二章
	 */
	public function test_第一章顯示前往下一個章節(): void {
		$html = $this->render_header( $this->chapter_ids[0] );

		$this->assert_next_button_links_to( $html, $this->chapter_ids[1] );
	}

	/**
	 * @test
	 * 中間章節：連到下一章
	 */
	public function test_中間章節連到下一章(): void {
		$html = $this->render_header( $this->chapter_ids[1] );

		$this->assert_next_button_links_to( $html, $this->chapter_ids[2] );
	}

	/**
	 * @test
	 * @group edge
	 * 最後一章：顯示停用的「沒有更多章節」
	 */
	public function test_最後一章顯示沒有更多章節(): void {
		$html = $this->render_header( $this->chapter_ids[2] );

		$this->assert_no_more_chapters( $html );
	}

	/**
	 * @test
	 * @group edge
	 * 目前章節不在課程章節清單內（array_search 回 false）：顯示停用的「沒有更多章節」，不能連到第一章
	 */
	public function test_章節不在課程內顯示沒有更多章節(): void {
		$other_course_id  = $this->create_course( [ 'post_title' => '別的課程' ] );
		$other_chapter_id = $this->create_chapter( $other_course_id, [ 'post_title' => '別的章節' ] );
		\wp_cache_flush();

		$html = $this->render_header( $other_chapter_id );

		$this->assert_no_more_chapters( $html );
		$this->assertStringNotContainsString(
			(string) \get_permalink( $this->chapter_ids[0] ),
			$html,
			'不在清單內的章節不可被當成 index 0，連到課程第一章'
		);
	}

	/**
	 * 渲染教室頁首
	 *
	 * @param int $chapter_id 目前章節 ID
	 * @return string
	 */
	private function render_header( int $chapter_id ): string {
		$product = \wc_get_product( $this->course_id );
		$chapter = \get_post( $chapter_id );
		$this->assertInstanceOf( \WC_Product::class, $product, '前置條件：課程商品應存在' );
		$this->assertInstanceOf( \WP_Post::class, $chapter, '前置條件：章節應存在' );

		// 模板的預設參數會讀這兩個全域變數
		$GLOBALS['course']  = $product;
		$GLOBALS['chapter'] = $chapter;

		$html = (string) Plugin::load_template(
			'classroom/header',
			[
				'product' => $product,
				'chapter' => $chapter,
			],
			false
		);

		$this->assertStringContainsString( 'pc-classroom-header', $html, '前置條件：應渲染出教室頁首，否則本測試沒碰到被測對象' );

		return $html;
	}

	/**
	 * 斷言顯示「前往下一個章節」且連到指定章節
	 *
	 * @param string $html       頁首 HTML
	 * @param int    $chapter_id 預期的下一章
	 */
	private function assert_next_button_links_to( string $html, int $chapter_id ): void {
		// 測試站台語系可能是 zh_TW，用翻譯後的字串比對
		$this->assertStringContainsString( \__( 'Go to next chapter', 'power-course' ), $html, '應顯示「前往下一個章節」' );
		$this->assertStringNotContainsString( \__( 'No more chapters', 'power-course' ), $html, '後面還有章節時不可顯示「沒有更多章節」' );
		$this->assertStringContainsString(
			'href="' . \esc_url( (string) \get_permalink( $chapter_id ) ) . '"',
			$html,
			'按鈕應連到下一章'
		);
	}

	/**
	 * 斷言顯示停用的「沒有更多章節」
	 *
	 * @param string $html 頁首 HTML
	 */
	private function assert_no_more_chapters( string $html ): void {
		$this->assertStringContainsString( \__( 'No more chapters', 'power-course' ), $html, '應顯示「沒有更多章節」' );
		$this->assertStringContainsString( 'aria-disabled="true"', $html, '「沒有更多章節」應為停用狀態' );
		$this->assertStringNotContainsString( \__( 'Go to next chapter', 'power-course' ), $html );
	}
}
