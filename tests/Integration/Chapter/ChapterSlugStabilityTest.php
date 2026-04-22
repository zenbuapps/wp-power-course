<?php
/**
 * 中文章節 slug 儲存穩定性測試
 *
 * Feature: specs/features/chapter/中文章節slug穩定性.feature
 *
 * 驗證中文 slug 經過 sanitize_text_field_deep 多次處理後保持穩定。
 *
 * @group chapter
 * @group slug
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;

/**
 * Class ChapterSlugStabilityTest
 * 測試中文章節 slug 在多次儲存後保持穩定
 */
class ChapterSlugStabilityTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 直接使用 WordPress APIs
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->create_course(
			[
				'post_title'  => '測試課程',
				'post_status' => 'publish',
			]
		);
	}

	/**
	 * 模擬 Chapter API separator() 中 sanitize 流程
	 *
	 * @param array<string, mixed> $body_params 前端送來的參數
	 * @param string[]             $skip_keys   要跳過 sanitize 的 keys
	 * @return array<string, mixed> sanitize 後的參數
	 */
	private function simulate_separator_sanitize( array $body_params, array $skip_keys ): array {
		$body_params = ChapterUtils::converter( $body_params );
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params, true, $skip_keys );
		return $body_params;
	}

	/**
	 * 測試：中文章節 slug 多次儲存不變
	 */
	public function test_chinese_slug_stable_after_multiple_saves(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '新章節',
				'post_name'  => '%e6%96%b0%e7%ab%a0%e7%af%80',
			]
		);

		$original_slug = get_post_field( 'post_name', $chapter_id );

		$skip_keys = [ 'chapter_video', 'post_content', 'post_name' ];

		for ( $i = 0; $i < 3; $i++ ) {
			$params    = $this->simulate_separator_sanitize(
				[ 'slug' => $original_slug, 'name' => '新章節' ],
				$skip_keys
			);
			$params['ID'] = $chapter_id;
			wp_update_post( $params );
		}

		$final_slug = get_post_field( 'post_name', $chapter_id );
		$this->assertSame( $original_slug, $final_slug, '中文 slug 在 3 次儲存後應保持不變' );
	}

	/**
	 * 測試：同名章節帶後綴的 slug 多次儲存不變
	 */
	public function test_chinese_slug_with_suffix_stable_after_multiple_saves(): void {
		$this->create_chapter(
			$this->course_id,
			[
				'post_title' => '新章節',
				'post_name'  => '%e6%96%b0%e7%ab%a0%e7%af%80',
			]
		);

		$chapter2_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '新章節',
				'post_name'  => '%e6%96%b0%e7%ab%a0%e7%af%80-2',
			]
		);

		$original_slug = get_post_field( 'post_name', $chapter2_id );

		$skip_keys = [ 'chapter_video', 'post_content', 'post_name' ];

		for ( $i = 0; $i < 3; $i++ ) {
			$params    = $this->simulate_separator_sanitize(
				[ 'slug' => $original_slug, 'name' => '新章節' ],
				$skip_keys
			);
			$params['ID'] = $chapter2_id;
			wp_update_post( $params );
		}

		$final_slug = get_post_field( 'post_name', $chapter2_id );
		$this->assertSame( $original_slug, $final_slug, '帶後綴的中文 slug 在 3 次儲存後應保持不變' );
	}

	/**
	 * 測試：只更新標題不送 slug 時 slug 不變
	 */
	public function test_slug_unchanged_when_only_title_updated(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '新章節',
				'post_name'  => '%e6%96%b0%e7%ab%a0%e7%af%80',
			]
		);

		$original_slug = get_post_field( 'post_name', $chapter_id );

		$skip_keys = [ 'chapter_video', 'post_content', 'post_name' ];

		$params    = $this->simulate_separator_sanitize(
			[ 'name' => '更新後的標題' ],
			$skip_keys
		);
		$params['ID'] = $chapter_id;
		wp_update_post( $params );

		$final_slug = get_post_field( 'post_name', $chapter_id );
		$this->assertSame( $original_slug, $final_slug, '只更新標題時 slug 不應變更' );
	}

	/**
	 * 測試：英文章節 slug 多次儲存不變（對照組）
	 */
	public function test_english_slug_stable_after_multiple_saves(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => 'Introduction',
				'post_name'  => 'introduction',
			]
		);

		$original_slug = get_post_field( 'post_name', $chapter_id );

		$skip_keys = [ 'chapter_video', 'post_content', 'post_name' ];

		for ( $i = 0; $i < 3; $i++ ) {
			$params    = $this->simulate_separator_sanitize(
				[ 'slug' => $original_slug, 'name' => 'Introduction' ],
				$skip_keys
			);
			$params['ID'] = $chapter_id;
			wp_update_post( $params );
		}

		$final_slug = get_post_field( 'post_name', $chapter_id );
		$this->assertSame( $original_slug, $final_slug, '英文 slug 在多次儲存後應保持不變' );
	}

	/**
	 * 測試：管理員修改中文 slug 後多次儲存不再破壞
	 */
	public function test_manually_fixed_slug_stays_stable(): void {
		$chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '新章節',
				'post_name'  => '2-3',
			]
		);

		$new_slug  = '%e6%96%b0%e7%ab%a0%e7%af%80';
		$skip_keys = [ 'chapter_video', 'post_content', 'post_name' ];

		$params    = $this->simulate_separator_sanitize(
			[ 'slug' => $new_slug, 'name' => '新章節' ],
			$skip_keys
		);
		$params['ID'] = $chapter_id;
		wp_update_post( $params );

		$fixed_slug = get_post_field( 'post_name', $chapter_id );

		for ( $i = 0; $i < 3; $i++ ) {
			$params    = $this->simulate_separator_sanitize(
				[ 'slug' => $fixed_slug, 'name' => '新章節' ],
				$skip_keys
			);
			$params['ID'] = $chapter_id;
			wp_update_post( $params );
		}

		$final_slug = get_post_field( 'post_name', $chapter_id );
		$this->assertSame( $fixed_slug, $final_slug, '手動修正後的 slug 在多次儲存後應保持穩定' );
	}

	/**
	 * 測試：缺少 post_name skip key 時 slug 會被破壞（重現 bug）
	 */
	public function test_slug_corrupted_without_post_name_skip_key(): void {
		$slug      = '%e6%96%b0%e7%ab%a0%e7%af%80-2';
		$skip_keys = [ 'chapter_video', 'post_content' ];

		$params = $this->simulate_separator_sanitize(
			[ 'slug' => $slug, 'name' => '新章節' ],
			$skip_keys
		);

		$this->assertNotSame( $slug, $params['post_name'] ?? '', '缺少 post_name skip key 時，中文 slug 會被 sanitize 破壞' );
	}
}
