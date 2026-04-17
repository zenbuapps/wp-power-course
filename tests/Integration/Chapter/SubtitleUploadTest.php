<?php
/**
 * 上傳章節字幕 整合測試
 * Feature: specs/features/chapter/上傳章節字幕.feature
 *
 * @group chapter
 * @group subtitle
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleUploadTest
 * 測試章節字幕上傳業務邏輯
 */
class SubtitleUploadTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試章節 ID */
	private int $chapter_id;

	/** @var int 管理員 ID */
	private int $admin_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		$this->services->subtitle = new SubtitleService();
	}

	/**
	 * 每個測試前建立 Background 資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background: 系統中有以下用戶
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_' . uniqid(),
				'user_email' => 'admin_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;

		// Background: 系統中有以下課程
		$this->course_id = $this->create_course(
			[
				'post_title'  => 'PHP 基礎課',
				'post_status' => 'publish',
				'_is_course'  => 'yes',
			]
		);

		// Background: 課程有以下章節（Bunny 影片源）
		$this->chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第一章',
			]
		);
		\update_post_meta(
			$this->chapter_id,
			'chapter_video',
			[
				'type' => 'bunny',
				'id'   => 'abc-123-def-456',
			]
		);
	}

	/**
	 * 清理測試暫存檔案
	 */
	public function tear_down(): void {
		// 清理可能殘留的暫存字幕檔
		$tmp_dir = sys_get_temp_dir();
		foreach ( glob( $tmp_dir . '/test-subtitle-*' ) as $file ) {
			@unlink( $file );
		}
		parent::tear_down();
	}

	// ========== Helper Methods ==========

	/**
	 * 建立暫存 SRT 字幕檔
	 *
	 * @param string $content SRT 內容（可選，預設為基本字幕）
	 * @return string 暫存檔案路徑
	 */
	private function create_temp_srt_file( string $content = '' ): string {
		if ( empty( $content ) ) {
			$content = "1\r\n00:00:01,000 --> 00:00:04,000\r\n你好世界\r\n\r\n2\r\n00:00:05,000 --> 00:00:08,000\r\n這是字幕測試\r\n";
		}
		$path = tempnam( sys_get_temp_dir(), 'test-subtitle-' ) . '.srt';
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * 建立暫存 VTT 字幕檔
	 *
	 * @param string $content VTT 內容（可選，預設為基本字幕）
	 * @return string 暫存檔案路徑
	 */
	private function create_temp_vtt_file( string $content = '' ): string {
		if ( empty( $content ) ) {
			$content = "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nHello World\n\n00:00:05.000 --> 00:00:08.000\nThis is a subtitle test\n";
		}
		$path = tempnam( sys_get_temp_dir(), 'test-subtitle-' ) . '.vtt';
		file_put_contents( $path, $content );
		return $path;
	}

	/**
	 * 預設字幕到章節 postmeta（模擬已存在的字幕）
	 *
	 * @param int   $chapter_id 章節 ID
	 * @param array<int, array{srclang: string, label: string, url: string, attachment_id: int}> $subtitles 字幕資料
	 */
	private function seed_subtitles( int $chapter_id, array $subtitles ): void {
		\update_post_meta( $chapter_id, 'pc_subtitles_chapter_video', $subtitles );
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- post 必須存在且 post type 支援
	 * Example: 不存在的 post 上傳字幕失敗
	 */
	public function test_不存在的章節上傳字幕失敗(): void {
		// Given 暫存 SRT 檔案
		$srt_path = $this->create_temp_srt_file();

		// When 管理員為不存在的 post 9999 上傳字幕
		try {
			$this->services->subtitle->upload_subtitle( 9999, $srt_path, 'subtitle.srt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤訊息包含 post_not_found
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'post_not_found' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 同語言字幕不可重複上傳（需先刪除再上傳）
	 * Example: 重複上傳相同語言字幕失敗
	 */
	public function test_重複上傳相同語言字幕失敗(): void {
		// Given 章節已有 zh-TW 字幕
		$this->seed_subtitles(
			$this->chapter_id,
			[
				[
					'srclang'       => 'zh-TW',
					'label'         => '繁體中文',
					'url'           => 'https://example.com/subtitle.vtt',
					'attachment_id' => 301,
				],
			]
		);

		$srt_path = $this->create_temp_srt_file();

		// When 管理員為同章節上傳相同語言字幕
		try {
			$this->services->subtitle->upload_subtitle( $this->chapter_id, $srt_path, 'new-subtitle.srt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「該語言字幕已存在，請先刪除再上傳」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Subtitle for this language already exists' );
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- 必須提供字幕檔案
	 * Example: 未提供檔案時上傳失敗
	 */
	public function test_未提供檔案時上傳失敗(): void {
		// When 管理員未提供檔案上傳字幕
		try {
			$this->services->subtitle->upload_subtitle( $this->chapter_id, '', 'subtitle.srt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「必須提供字幕檔案」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Subtitle file is required' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- 必須指定語言代碼（srclang）
	 * Example: 未提供語言代碼時上傳失敗
	 */
	public function test_未提供語言代碼時上傳失敗(): void {
		$srt_path = $this->create_temp_srt_file();

		// When 管理員未提供語言代碼上傳字幕
		try {
			$this->services->subtitle->upload_subtitle( $this->chapter_id, $srt_path, 'subtitle.srt', '', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「必須指定字幕語言」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Subtitle language is required' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- 僅接受 .srt 和 .vtt 格式
	 * Example: 上傳不支援的格式失敗
	 */
	public function test_上傳不支援的格式失敗(): void {
		// 建立 .txt 暫存檔
		$txt_path = tempnam( sys_get_temp_dir(), 'test-subtitle-' ) . '.txt';
		file_put_contents( $txt_path, 'some text content' );

		// When 管理員上傳不支援的格式
		try {
			$this->services->subtitle->upload_subtitle( $this->chapter_id, $txt_path, 'subtitle.txt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「僅支援 .srt 和 .vtt 格式」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Only .srt and .vtt formats are supported' );

		@unlink( $txt_path );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- srclang 必須為有效的 BCP-47 語言代碼
	 * Example: 無效的語言代碼上傳失敗
	 */
	public function test_無效的語言代碼上傳失敗(): void {
		$srt_path = $this->create_temp_srt_file();

		// When 管理員使用無效的語言代碼上傳字幕
		try {
			$this->services->subtitle->upload_subtitle( $this->chapter_id, $srt_path, 'subtitle.srt', 'zzz', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「無效的語言代碼」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Invalid language code' );
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- SRT 檔案自動轉換為 WebVTT 後儲存到 WordPress 媒體庫
	 * Example: 成功上傳 SRT 字幕（自動轉換為 WebVTT）
	 */
	public function test_成功上傳SRT字幕_自動轉換為WebVTT(): void {
		$srt_path = $this->create_temp_srt_file();

		// When 管理員為章節上傳 SRT 字幕
		try {
			$result          = $this->services->subtitle->upload_subtitle( $this->chapter_id, $srt_path, 'subtitle.srt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
			$result          = null;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();
		$this->assertNotNull( $result );

		// And 章節的字幕列表應包含 zh-TW
		$subtitles = $this->services->subtitle->get_subtitles( $this->chapter_id, 'chapter_video' );
		$zh_track  = array_filter( $subtitles, fn( $s ) => $s['srclang'] === 'zh-TW' );
		$this->assertCount( 1, $zh_track, '字幕列表應包含 zh-TW' );

		$track = array_values( $zh_track )[0];
		$this->assertSame( '繁體中文', $track['label'] );

		// And 回應中應包含 attachment_id（正整數）
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );

		// And 回應中應包含 url（.vtt 檔案 URL）
		$this->assertArrayHasKey( 'url', $result );
		$this->assertStringEndsWith( '.vtt', $result['url'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- WebVTT 檔案直接儲存到 WordPress 媒體庫
	 * Example: 成功上傳 WebVTT 字幕
	 */
	public function test_成功上傳WebVTT字幕(): void {
		$vtt_path = $this->create_temp_vtt_file();

		// When 管理員為章節上傳 VTT 字幕
		try {
			$result          = $this->services->subtitle->upload_subtitle( $this->chapter_id, $vtt_path, 'subtitle.vtt', 'en', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
			$result          = null;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();
		$this->assertNotNull( $result );

		// And 章節的字幕列表應包含 en
		$subtitles = $this->services->subtitle->get_subtitles( $this->chapter_id, 'chapter_video' );
		$en_track  = array_filter( $subtitles, fn( $s ) => $s['srclang'] === 'en' );
		$this->assertCount( 1, $en_track, '字幕列表應包含 en' );

		$track = array_values( $en_track )[0];
		$this->assertSame( 'English', $track['label'] );

		// And 回應中應包含 attachment_id（正整數）
		$this->assertArrayHasKey( 'attachment_id', $result );
		$this->assertGreaterThan( 0, $result['attachment_id'] );

		// And 回應中應包含 url（.vtt 檔案 URL）
		$this->assertArrayHasKey( 'url', $result );
		$this->assertStringEndsWith( '.vtt', $result['url'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 支援同一章節上傳多語言字幕
	 * Example: 成功為同一章節上傳第二語言字幕
	 */
	public function test_成功為同一章節上傳第二語言字幕(): void {
		// Given 章節已有 zh-TW 字幕
		$this->seed_subtitles(
			$this->chapter_id,
			[
				[
					'srclang'       => 'zh-TW',
					'label'         => '繁體中文',
					'url'           => 'https://example.com/subtitle-zh.vtt',
					'attachment_id' => 301,
				],
			]
		);

		$vtt_path = $this->create_temp_vtt_file();

		// When 管理員為同章節上傳英文字幕
		try {
			$result          = $this->services->subtitle->upload_subtitle( $this->chapter_id, $vtt_path, 'subtitle-en.vtt', 'en', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
			$result          = null;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 章節的字幕列表應包含兩種語言
		$subtitles = $this->services->subtitle->get_subtitles( $this->chapter_id, 'chapter_video' );
		$this->assertCount( 2, $subtitles, '字幕列表應有 2 個語言' );

		$srclangs = array_column( $subtitles, 'srclang' );
		$this->assertContains( 'zh-TW', $srclangs );
		$this->assertContains( 'en', $srclangs );
	}
}
