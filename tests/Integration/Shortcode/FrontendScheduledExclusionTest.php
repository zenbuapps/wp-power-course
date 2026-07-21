<?php
/**
 * 前台課程列表排除排程 / 私密 / 草稿課程 —— 紅線 Regression Guard（Issue #256）
 *
 * Feature 對照：
 * - specs/features/course/查詢課程列表.feature（Background 註解明確聲明：
 *   「前台學員端課程列表走獨立查詢路徑（shortcode [pc_courses]，僅 publish + visible），
 *   排程課程不會外洩至前台」）
 * - specs/features/shortcode/課程列表分頁.feature（前台 [pc_courses] 查詢/渲染路徑）
 *
 * 測試對象：J7\PowerCourse\Shortcodes\General::get_courses_page( array $params ): array
 *
 * ⚠️ 與同目錄下 CourseListScheduledStatusTest 的差異：
 * 本檔案**不是** Red 階段測試，而是「紅線」regression guard。
 * General::get_courses_page() 目前已在方法內部強制
 * `$args['status'] = ['publish']`、`$args['visibility'] = 'visible'`，
 * 不接受外部覆寫（見 inc/classes/Shortcodes/General.php 內「公開端點防護」註解），
 * 因此本檔案的斷言在 Issue #256 修改後台查詢層「之前」與「之後」都應該是綠燈。
 *
 * 存在目的：Issue #256 把後台 status 預設集合擴充為
 * ['publish','draft','future','private'] 後，鎖住「前台查詢路徑天然隔離、
 * 絕不會因為後台改動而外洩排程/私密/草稿課程」這條紅線 —— 若未來有人不慎讓前台
 * 共用後台查詢邏輯、或誤刪 get_courses_page() 內強制覆寫 status 的兩行，
 * 這裡會立刻變紅，即時攔截洩漏。
 *
 * 斷言刻意避免退化成 assertGreaterThanOrEqual(0, ...) 這種永遠成立的寬鬆斷言
 * （TestCase::create_course() 已修正 product_type term 遺漏問題，
 * wc_get_products() 的 total 應可精確比對；html 亦直接斷言不含排程/私密/草稿課程名稱）。
 *
 * 測試指令：
 *   composer run test
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-course \
 *     -- vendor/bin/phpunit --group shortcode
 *
 * @group shortcode
 */

declare( strict_types=1 );

namespace Tests\Integration\Shortcode;

use Tests\Integration\TestCase;
use J7\PowerCourse\Shortcodes\General as Shortcodes;

/**
 * Class FrontendScheduledExclusionTest
 * 驗證前台 [pc_courses] 查詢路徑不受後台 status 預設集合擴充影響，
 * 排程 / 私密 / 草稿課程仍完全不外洩。
 */
class FrontendScheduledExclusionTest extends TestCase {

	/** 初始化依賴（本測試直接呼叫靜態方法，無需初始化 repository 或 service） */
	protected function configure_dependencies(): void {}

	// ========== Helpers ==========

	/**
	 * 建立「排程發佈」課程，繞過 wp_insert_post()/wp_update_post() 內建的
	 * 「post_date_gmt 若不夠未來（相對測試執行當下的真實系統時間）就自動降級為 publish」
	 * 轉態邏輯，直接寫入 wp_posts 確保 status 精準為 future
	 * （理由同 Tests\Integration\Course\CourseListScheduledStatusTest::create_scheduled_course）。
	 *
	 * @param array<string, mixed> $args      課程參數（同 TestCase::create_course()）。
	 * @param string               $post_date 排程發佈時間（Y-m-d H:i:s）。
	 * @return int 課程 ID
	 */
	private function create_scheduled_course( array $args, string $post_date ): int {
		$course_id = $this->create_course(
			array_merge( $args, [ 'post_status' => 'publish' ] )
		);

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			[
				'post_status'   => 'future',
				'post_date'     => $post_date,
				'post_date_gmt' => $post_date,
			],
			[ 'ID' => $course_id ]
		);
		\clean_post_cache( $course_id );

		$this->assertSame(
			'future',
			\get_post_status( $course_id ),
			'測試前提失敗：排程課程 post_status 必須為 future'
		);

		return $course_id;
	}

	/**
	 * 判斷 get_courses_page() 回傳的 html 是否包含指定課程名稱。
	 *
	 * @param array{html:string} $result get_courses_page() 回傳值。
	 * @param string             $name   課程名稱。
	 */
	private function html_contains_course_name( array $result, string $name ): bool {
		return false !== strpos( $result['html'], $name );
	}

	// ========== T8：前台第 1 頁不含排程課程 ==========

	/**
	 * @test
	 * @group security
	 * T8：前台第 1 頁（publish×3 + future×1）應只回 3 筆，且 html 不含排程課程名稱。
	 * 紅線：對應 查詢課程列表.feature Background 註解「排程課程不會外洩至前台」。
	 */
	public function test_前台第1頁不應包含排程中課程(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->create_course(
				[
					'post_title' => "前台公開課程 {$i}",
					'_is_course' => 'yes',
				]
			);
		}

		$scheduled_name = '前台不應出現的排程課程';
		$this->create_scheduled_course(
			[
				'post_title' => $scheduled_name,
				'_is_course' => 'yes',
			],
			'2025-08-01 09:00:00'
		);

		$result = Shortcodes::get_courses_page(
			[
				'limit' => 12,
				'page'  => 1,
			]
		);

		$this->assertSame(
			3,
			$result['total'],
			'前台第 1 頁 total 應只計入 3 筆 publish 課程，不含排程課程（若此處失敗，代表前台查詢已外洩排程課程，屬嚴重紅線問題）'
		);
		$this->assertFalse(
			$this->html_contains_course_name( $result, $scheduled_name ),
			'前台 html 絕不應出現排程課程名稱「' . $scheduled_name . '」'
		);
		$this->assertTrue(
			$this->html_contains_course_name( $result, '前台公開課程 1' ),
			'前台 html 應正常包含 publish 課程'
		);
	}

	// ========== T9：前台排除 private / draft 課程 ==========

	/**
	 * @test
	 * @group security
	 * T9：前台應排除 private 與 draft 課程，只回 publish 課程。
	 * 紅線：private / draft 課程不應出現在 [pc_courses] 前台列表。
	 */
	public function test_前台應排除private與draft課程(): void {
		$this->create_course(
			[
				'post_title' => '前台可見的公開課程',
				'_is_course' => 'yes',
			]
		);

		$private_name = '前台不應出現的私密課程';
		$this->create_course(
			[
				'post_title'  => $private_name,
				'post_status' => 'private',
				'_is_course'  => 'yes',
			]
		);

		$draft_name = '前台不應出現的草稿課程';
		$this->create_course(
			[
				'post_title'  => $draft_name,
				'post_status' => 'draft',
				'_is_course'  => 'yes',
			]
		);

		$result = Shortcodes::get_courses_page(
			[
				'limit' => 12,
				'page'  => 1,
			]
		);

		$this->assertSame(
			1,
			$result['total'],
			'前台 total 應只計入 1 筆 publish 課程，不含 private/draft（若此處失敗，代表前台查詢已外洩非公開課程，屬嚴重紅線問題）'
		);
		$this->assertFalse(
			$this->html_contains_course_name( $result, $private_name ),
			'前台 html 不應出現私密課程名稱「' . $private_name . '」'
		);
		$this->assertFalse(
			$this->html_contains_course_name( $result, $draft_name ),
			'前台 html 不應出現草稿課程名稱「' . $draft_name . '」'
		);
		$this->assertTrue(
			$this->html_contains_course_name( $result, '前台可見的公開課程' ),
			'前台 html 應正常包含 publish 課程'
		);
	}
}
