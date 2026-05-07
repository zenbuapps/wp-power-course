<?php
/**
 * 試看影片字幕 v1.3 → v1.4+ Lazy Migration 整合測試
 *
 * 測試 `pc_subtitles_trial_video`（v1.3 單一 meta）→ `pc_subtitles_trial_video_0`（v1.4+ 動態 slot）.
 * 的 lazy migration 行為，僅在 `trial_video_0` 操作時觸發.
 *
 * @group chapter
 * @group subtitle
 * @group migration
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleLegacyTrialVideoMigrationTest
 * 測試 trial_video_0 對舊 pc_subtitles_trial_video meta 的 lazy migration 行為.
 */
class SubtitleLegacyTrialVideoMigrationTest extends TestCase {

	private const LEGACY_META_KEY = 'pc_subtitles_trial_video';
	private const NEW_META_KEY    = 'pc_subtitles_trial_video_0';

	/** @var int 測試課程（product）ID */
	private int $course_id;

	/** @var SubtitleService 字幕服務 */
	private SubtitleService $subtitle_service;

	/**
	 * 初始化依賴.
	 */
	protected function configure_dependencies(): void {
		$this->subtitle_service   = new SubtitleService();
		$this->services->subtitle = $this->subtitle_service;
	}

	/**
	 * 每個測試前建立 product（試看影片只能掛在 product 上）.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->create_course(
			[
				'post_title'  => '試看影片 Migration 測試課程',
				'post_status' => 'publish',
				'_is_course'  => 'yes',
			]
		);
	}

	/**
	 * 清理 migration 測試殘留檔案.
	 */
	public function tear_down(): void {
		$tmp_dir = sys_get_temp_dir();
		foreach ( (array) glob( $tmp_dir . '/test-migration-*' ) as $file ) {
			@unlink( (string) $file );
		}
		parent::tear_down();
	}

	// ========== Helpers ==========

	/**
	 * 產生一筆模擬的字幕資料（不實際建立 attachment）.
	 *
	 * @param string $srclang BCP-47 語言代碼.
	 * @param string $label   顯示名稱.
	 * @return array{srclang: string, label: string, url: string, attachment_id: int}
	 */
	private function make_subtitle_track( string $srclang, string $label ): array {
		return [
			'srclang'       => $srclang,
			'label'         => $label,
			'url'           => "https://example.com/subtitle-{$srclang}.vtt",
			'attachment_id' => 0,
		];
	}

	/**
	 * 建立暫存 VTT 檔.
	 *
	 * @return string 檔案路徑.
	 */
	private function create_temp_vtt_file(): string {
		$content = "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello\n";
		$path    = tempnam( sys_get_temp_dir(), 'test-migration-' ) . '.vtt';
		file_put_contents( $path, $content );
		return $path;
	}

	// ========== Case 1: 只有舊資料，無新資料 ==========

	/**
	 * @test
	 * @testdox case 1: 只有舊 pc_subtitles_trial_video 資料時，get_subtitles(post, 'trial_video_0') 觸發 migration
	 */
	public function test_case1_只有舊資料時_get_subtitles_觸發_migration(): void {
		// Given post 有 v1.3 舊資料 pc_subtitles_trial_video，無新 pc_subtitles_trial_video_0.
		$legacy_subtitles = [
			$this->make_subtitle_track( 'zh-TW', '繁體中文' ),
		];
		\update_post_meta( $this->course_id, self::LEGACY_META_KEY, $legacy_subtitles );

		// When 透過 get_subtitles 以 trial_video_0 讀取.
		$result = $this->subtitle_service->get_subtitles( $this->course_id, 'trial_video_0' );

		// Then 回傳的字幕等於舊資料.
		$this->assertCount( 1, $result, '應回傳 1 筆字幕' );
		$this->assertSame( 'zh-TW', $result[0]['srclang'] );

		// And 舊 key 已清除.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertEmpty( $legacy_after, '舊 key pc_subtitles_trial_video 應已被清除' );

		// And 新 key 已寫入.
		$new_after = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertIsArray( $new_after, '新 key pc_subtitles_trial_video_0 應已存在' );
		$this->assertCount( 1, $new_after );
		$this->assertSame( 'zh-TW', $new_after[0]['srclang'] );
	}

	// ========== Case 2: 同時有舊資料與新資料 ==========

	/**
	 * @test
	 * @testdox case 2: 同時有舊 key 與新 key 資料時，migration 不覆蓋新 key、僅清除舊 key
	 */
	public function test_case2_同時有舊與新資料時_不覆蓋新_key(): void {
		// Given post 同時有舊 key 與新 key.
		$legacy_subtitles = [
			$this->make_subtitle_track( 'zh-TW', '繁體中文（舊）' ),
		];
		$new_subtitles    = [
			$this->make_subtitle_track( 'en', 'English（新）' ),
		];
		\update_post_meta( $this->course_id, self::LEGACY_META_KEY, $legacy_subtitles );
		\update_post_meta( $this->course_id, self::NEW_META_KEY, $new_subtitles );

		// When 透過 get_subtitles 以 trial_video_0 讀取.
		$result = $this->subtitle_service->get_subtitles( $this->course_id, 'trial_video_0' );

		// Then 回傳的字幕為新 key 資料（不被舊 key 覆蓋）.
		$this->assertCount( 1, $result );
		$this->assertSame( 'en', $result[0]['srclang'], '應回傳新 key 資料而非舊 key' );

		// And 舊 key 已被清除（避免之後重複觸發 migration）.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertEmpty( $legacy_after, '舊 key 應已被清除' );

		// And 新 key 內容保持原樣.
		$new_after = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertIsArray( $new_after );
		$this->assertCount( 1, $new_after );
		$this->assertSame( 'en', $new_after[0]['srclang'] );
	}

	// ========== Case 3: 只有新 key 資料 ==========

	/**
	 * @test
	 * @testdox case 3: 只有新 key 資料時，migration 不動作
	 */
	public function test_case3_只有新_key_資料時_不動作(): void {
		// Given post 只有新 key 資料.
		$new_subtitles = [
			$this->make_subtitle_track( 'ja', '日本語' ),
		];
		\update_post_meta( $this->course_id, self::NEW_META_KEY, $new_subtitles );

		// When 透過 get_subtitles 以 trial_video_0 讀取.
		$result = $this->subtitle_service->get_subtitles( $this->course_id, 'trial_video_0' );

		// Then 正常讀回新 key 資料.
		$this->assertCount( 1, $result );
		$this->assertSame( 'ja', $result[0]['srclang'] );

		// And 舊 key 始終為空.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertEmpty( $legacy_after );

		// And 新 key 內容不變.
		$new_after = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertIsArray( $new_after );
		$this->assertCount( 1, $new_after );
		$this->assertSame( 'ja', $new_after[0]['srclang'] );
	}

	// ========== Case 4: upload_subtitle 觸發 migration ==========

	/**
	 * @test
	 * @testdox case 4: upload_subtitle('trial_video_0') 觸發 migration，先搬舊資料再 append 新字幕
	 */
	public function test_case4_upload_subtitle_觸發_migration_並_append(): void {
		// Given post 有 v1.3 舊資料（zh-TW）.
		$legacy_subtitles = [
			$this->make_subtitle_track( 'zh-TW', '繁體中文' ),
		];
		\update_post_meta( $this->course_id, self::LEGACY_META_KEY, $legacy_subtitles );

		// When 對 trial_video_0 上傳新語言（en）字幕.
		$vtt_path = $this->create_temp_vtt_file();
		try {
			$this->subtitle_service->upload_subtitle(
				$this->course_id,
				$vtt_path,
				'subtitle.vtt',
				'en',
				'trial_video_0'
			);
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 上傳成功.
		$this->assert_operation_succeeded();

		// And 新 key 同時包含舊資料（zh-TW）與新上傳資料（en）.
		$new_after = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertIsArray( $new_after );
		$this->assertCount( 2, $new_after, '應同時包含舊 zh-TW 與新 en 兩筆字幕' );

		$srclangs = array_column( $new_after, 'srclang' );
		$this->assertContains( 'zh-TW', $srclangs, 'migration 後應保留舊 zh-TW' );
		$this->assertContains( 'en', $srclangs, '新上傳的 en 應已加入' );

		// And 舊 key 已清除.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertEmpty( $legacy_after, '舊 key 應已被清除' );

		@unlink( $vtt_path );
	}

	// ========== Case 5: delete_subtitle 觸發 migration ==========

	/**
	 * @test
	 * @testdox case 5: delete_subtitle('trial_video_0', 'zh-TW') 在 migration 後能刪掉原本只在舊 key 的字幕
	 */
	public function test_case5_delete_subtitle_可刪除舊_key_中的字幕(): void {
		// Given post 有 v1.3 舊資料（含 zh-TW）.
		$legacy_subtitles = [
			$this->make_subtitle_track( 'zh-TW', '繁體中文' ),
			$this->make_subtitle_track( 'en', 'English' ),
		];
		\update_post_meta( $this->course_id, self::LEGACY_META_KEY, $legacy_subtitles );

		// When 對 trial_video_0 刪除 zh-TW 字幕.
		try {
			$this->subtitle_service->delete_subtitle( $this->course_id, 'zh-TW', 'trial_video_0' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功.
		$this->assert_operation_succeeded();

		// And 新 key 只剩 en（zh-TW 已被刪除）.
		$new_after = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertIsArray( $new_after );
		$this->assertCount( 1, $new_after, '新 key 應只剩 1 筆（en）' );
		$this->assertSame( 'en', $new_after[0]['srclang'] );

		// And 舊 key 已清除.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertEmpty( $legacy_after, '舊 key 應已被清除' );
	}

	// ========== Case 6: trial_video_1 ~ 5 不觸發 migration ==========

	/**
	 * @test
	 * @testdox case 6: trial_video_{N>0} 操作不觸發 migration（不影響舊 key）
	 * @dataProvider provideNonZeroTrialVideoIndexes
	 *
	 * @param int $index Trial video 索引（1 ~ 5）.
	 */
	public function test_case6_trial_video_N_大於0_不觸發_migration( int $index ): void {
		// Given post 有 v1.3 舊資料.
		$legacy_subtitles = [
			$this->make_subtitle_track( 'zh-TW', '繁體中文' ),
		];
		\update_post_meta( $this->course_id, self::LEGACY_META_KEY, $legacy_subtitles );

		$slot = "trial_video_{$index}";

		// When 對 trial_video_{N>0} 讀取字幕.
		$result = $this->subtitle_service->get_subtitles( $this->course_id, $slot );

		// Then 該 slot 字幕為空（沒有混入舊 key 資料）.
		$this->assertSame( [], $result, "trial_video_{$index} 不應讀到舊 key 資料" );

		// And 舊 key 仍保持原樣（沒有被搬走）.
		$legacy_after = \get_post_meta( $this->course_id, self::LEGACY_META_KEY, true );
		$this->assertIsArray( $legacy_after, '舊 key 應保持原樣（沒被 migration）' );
		$this->assertCount( 1, $legacy_after );
		$this->assertSame( 'zh-TW', $legacy_after[0]['srclang'] );

		// And 新 trial_video_0 key 也沒被寫入（因為 N>0 不觸發 migration）.
		$new_zero = \get_post_meta( $this->course_id, self::NEW_META_KEY, true );
		$this->assertEmpty( $new_zero, 'pc_subtitles_trial_video_0 不應被寫入' );
	}

	/**
	 * 提供 N > 0 的 trial_video 索引（不應觸發 migration）.
	 *
	 * @return array<string, array{int}>
	 */
	public function provideNonZeroTrialVideoIndexes(): array {
		return [
			'index 1' => [ 1 ],
			'index 2' => [ 2 ],
			'index 3' => [ 3 ],
			'index 4' => [ 4 ],
			'index 5' => [ 5 ],
		];
	}
}
