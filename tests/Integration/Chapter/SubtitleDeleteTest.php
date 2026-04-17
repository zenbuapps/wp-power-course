<?php
/**
 * 刪除章節字幕 整合測試
 * Feature: specs/features/chapter/刪除章節字幕.feature
 *
 * @group chapter
 * @group subtitle
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleDeleteTest
 * 測試章節字幕刪除業務邏輯
 */
class SubtitleDeleteTest extends TestCase {

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

	// ========== Helper Methods ==========

	/**
	 * 建立真實的 WP 附件並設定字幕 meta
	 *
	 * @param int    $chapter_id 章節 ID
	 * @param string $srclang    語言代碼
	 * @param string $label      語言顯示名稱
	 * @return int attachment_id
	 */
	private function create_subtitle_attachment( int $chapter_id, string $srclang, string $label ): int {
		// 建立暫存 VTT 檔案
		$vtt_content = "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nTest subtitle for {$srclang}\n";
		$upload      = \wp_upload_bits( "subtitle-{$srclang}.vtt", null, $vtt_content );

		// 建立 WP attachment
		$attachment_id = \wp_insert_attachment(
			[
				'post_title'     => "subtitle-{$srclang}",
				'post_mime_type' => 'text/vtt',
				'post_status'    => 'inherit',
			],
			$upload['file']
		);

		return $attachment_id;
	}

	/**
	 * 預設多筆字幕到章節（含真實附件）
	 *
	 * @param int                                       $chapter_id 章節 ID
	 * @param array<int, array{srclang: string, label: string}> $tracks 字幕軌道定義
	 * @return array<string, int> srclang -> attachment_id 映射
	 */
	private function seed_subtitles_with_attachments( int $chapter_id, array $tracks ): array {
		$subtitles      = [];
		$attachment_map = [];

		foreach ( $tracks as $track ) {
			$attachment_id = $this->create_subtitle_attachment( $chapter_id, $track['srclang'], $track['label'] );
			$upload_dir    = \wp_upload_dir();

			$subtitles[] = [
				'srclang'       => $track['srclang'],
				'label'         => $track['label'],
				'url'           => $upload_dir['url'] . "/subtitle-{$track['srclang']}.vtt",
				'attachment_id' => $attachment_id,
			];

			$attachment_map[ $track['srclang'] ] = $attachment_id;
		}

		\update_post_meta( $chapter_id, 'pc_subtitles_chapter_video', $subtitles );
		return $attachment_map;
	}

	// ========== 前置（狀態）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- post 必須存在且 post type 支援
	 * Example: 不存在的 post 刪除字幕失敗
	 */
	public function test_不存在的章節刪除字幕失敗(): void {
		// When 管理員刪除不存在的 post 9999 的字幕
		try {
			$this->services->subtitle->delete_subtitle( 9999, 'zh-TW', 'chapter_video' );
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
	 * Rule: 前置（狀態）- 指定語言的字幕必須存在
	 * Example: 刪除不存在的語言字幕失敗
	 */
	public function test_刪除不存在的語言字幕失敗(): void {
		// Given 章節有 zh-TW 和 en 字幕
		$this->seed_subtitles_with_attachments(
			$this->chapter_id,
			[
				[ 'srclang' => 'zh-TW', 'label' => '繁體中文' ],
				[ 'srclang' => 'en', 'label' => 'English' ],
			]
		);

		// When 管理員刪除不存在的 ja 語言字幕
		try {
			$this->services->subtitle->delete_subtitle( $this->chapter_id, 'ja', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤為「該語言字幕不存在」
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'Subtitle for this language does not exist' );
	}

	// ========== 前置（參數）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- 必須指定語言代碼
	 * Example: 未指定語言代碼時刪除失敗
	 */
	public function test_未指定語言代碼時刪除失敗(): void {
		// When 管理員未指定語言代碼刪除字幕
		try {
			$this->services->subtitle->delete_subtitle( $this->chapter_id, '', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作失敗，錯誤訊息包含 "srclang"
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'srclang' );
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 刪除指定語言的字幕及 WordPress 媒體庫附件
	 * Example: 成功刪除指定語言字幕
	 */
	public function test_成功刪除指定語言字幕(): void {
		// Given 章節已有 zh-TW 和 en 字幕（含真實附件）
		$attachment_map = $this->seed_subtitles_with_attachments(
			$this->chapter_id,
			[
				[ 'srclang' => 'zh-TW', 'label' => '繁體中文' ],
				[ 'srclang' => 'en', 'label' => 'English' ],
			]
		);

		$zh_attachment_id = $attachment_map['zh-TW'];

		// When 管理員刪除 zh-TW 字幕
		try {
			$this->services->subtitle->delete_subtitle( $this->chapter_id, 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 章節字幕列表應只包含 en
		$subtitles = $this->services->subtitle->get_subtitles( $this->chapter_id, 'chapter_video' );
		$this->assertCount( 1, $subtitles );

		$srclangs = array_column( $subtitles, 'srclang' );
		$this->assertContains( 'en', $srclangs );
		$this->assertNotContains( 'zh-TW', $srclangs );

		// And WordPress 媒體庫中 zh-TW 的 attachment 應已刪除
		$this->assertNull( \get_post( $zh_attachment_id ), 'zh-TW 附件應已被刪除' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 刪除最後一筆字幕後字幕列表為空
	 * Example: 刪除唯一字幕後列表為空
	 */
	public function test_刪除唯一字幕後列表為空(): void {
		// Given 章節只有 zh-TW 字幕
		$this->seed_subtitles_with_attachments(
			$this->chapter_id,
			[
				[ 'srclang' => 'zh-TW', 'label' => '繁體中文' ],
			]
		);

		// When 管理員刪除 zh-TW 字幕
		try {
			$this->services->subtitle->delete_subtitle( $this->chapter_id, 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And 章節字幕列表應為空
		$subtitles = $this->services->subtitle->get_subtitles( $this->chapter_id, 'chapter_video' );
		$this->assertEmpty( $subtitles, '字幕列表應為空' );
	}
}
