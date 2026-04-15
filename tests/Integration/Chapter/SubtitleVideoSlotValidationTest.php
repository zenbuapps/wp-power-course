<?php
/**
 * 字幕 Video Slot 驗證 整合測試
 * 測試 post type 與 video slot 的搭配驗證邏輯
 *
 * @group chapter
 * @group subtitle
 */

declare( strict_types=1 );

namespace Tests\Integration\Chapter;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Chapter\Service\Subtitle as SubtitleService;

/**
 * Class SubtitleVideoSlotValidationTest
 * 測試字幕的 video slot 驗證邏輯
 */
class SubtitleVideoSlotValidationTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 測試章節 ID（post_type = pc_chapter） */
	private int $chapter_id;

	/** @var SubtitleService 字幕服務 */
	private SubtitleService $subtitle_service;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		$this->subtitle_service    = new SubtitleService();
		$this->services->subtitle  = $this->subtitle_service;
	}

	/**
	 * 每個測試前建立 Background 資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background: 建立課程（post_type = product）
		$this->course_id = $this->create_course(
			[
				'post_title'  => 'PHP 基礎課',
				'post_status' => 'publish',
				'_is_course'  => 'yes',
			]
		);

		// Background: 建立章節（post_type = pc_chapter）
		$this->chapter_id = $this->create_chapter(
			$this->course_id,
			[
				'post_title' => '第一章',
			]
		);
	}

	// ========== chapter_video slot 搭配 pc_chapter ==========

	/**
	 * @test
	 * @testdox chapter_video slot 搭配 pc_chapter post type 驗證成功
	 */
	public function test_chapter_video_slot_配合_pc_chapter_成功(): void {
		// When 以 chapter_video slot 驗證 pc_chapter post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->chapter_id, 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證成功
		$this->assert_operation_succeeded();
	}

	// ========== feature_video slot 搭配 product ==========

	/**
	 * @test
	 * @testdox feature_video slot 搭配 product post type 驗證成功
	 */
	public function test_feature_video_slot_配合_product_成功(): void {
		// When 以 feature_video slot 驗證 product post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->course_id, 'feature_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證成功
		$this->assert_operation_succeeded();
	}

	// ========== trial_video slot 搭配 product ==========

	/**
	 * @test
	 * @testdox trial_video slot 搭配 product post type 驗證成功
	 */
	public function test_trial_video_slot_配合_product_成功(): void {
		// When 以 trial_video slot 驗證 product post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->course_id, 'trial_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證成功
		$this->assert_operation_succeeded();
	}

	// ========== 錯誤搭配：chapter 傳入 feature_video ==========

	/**
	 * @test
	 * @testdox chapter（pc_chapter）傳入 feature_video 應失敗
	 */
	public function test_chapter_傳入_feature_video_失敗(): void {
		// When 以 feature_video slot 驗證 pc_chapter post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->chapter_id, 'feature_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證失敗，錯誤為 invalid_video_slot
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'invalid_video_slot' );
	}

	// ========== 錯誤搭配：product 傳入 chapter_video ==========

	/**
	 * @test
	 * @testdox product 傳入 chapter_video 應失敗
	 */
	public function test_product_傳入_chapter_video_失敗(): void {
		// When 以 chapter_video slot 驗證 product post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->course_id, 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證失敗，錯誤為 invalid_video_slot
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'invalid_video_slot' );
	}

	// ========== 不支援的 post type ==========

	/**
	 * @test
	 * @testdox 不支援的 post type 應失敗
	 */
	public function test_不支援的_post_type_失敗(): void {
		// Given 建立一般文章（post_type = post）
		$post_id = $this->factory()->post->create(
			[
				'post_type'   => 'post',
				'post_title'  => '一般文章',
				'post_status' => 'publish',
			]
		);

		// When 以 chapter_video slot 驗證一般文章
		try {
			$this->subtitle_service->validate_post_and_slot( $post_id, 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證失敗
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'post_not_found' );
	}

	// ========== 不存在的 post ID ==========

	/**
	 * @test
	 * @testdox 不存在的 post ID 應失敗
	 */
	public function test_不存在的_post_id_失敗(): void {
		// When 以 chapter_video slot 驗證不存在的 post ID
		try {
			$this->subtitle_service->validate_post_and_slot( 999999, 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證失敗
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'post_not_found' );
	}

	// ========== 不在白名單的 videoSlot ==========

	/**
	 * @test
	 * @testdox 不在白名單的 videoSlot 應失敗
	 */
	public function test_不在白名單的_videoSlot_失敗(): void {
		// When 以不存在的 slot 驗證 pc_chapter post
		try {
			$this->subtitle_service->validate_post_and_slot( $this->chapter_id, 'nonexistent_slot' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 驗證失敗，錯誤為 invalid_video_slot
		$this->assert_operation_failed();
		$this->assert_operation_failed_with_message( 'invalid_video_slot' );
	}

	// ========== meta key 為 pc_subtitles_ 加 videoSlot ==========

	/**
	 * @test
	 * @testdox meta key 應為 pc_subtitles_ 加上 videoSlot
	 */
	public function test_meta_key_為_pc_subtitles_加_videoSlot(): void {
		// Given 建立暫存 VTT 檔案
		$vtt_content = "WEBVTT\n\n00:00:01.000 --> 00:00:04.000\nTest subtitle\n";
		$vtt_path    = tempnam( sys_get_temp_dir(), 'test-subtitle-' ) . '.vtt';
		file_put_contents( $vtt_path, $vtt_content );

		// When 上傳字幕到章節的 chapter_video slot
		try {
			$this->subtitle_service->upload_subtitle( $this->chapter_id, $vtt_path, 'subtitle.vtt', 'zh-TW', 'chapter_video' );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		// Then 操作成功
		$this->assert_operation_succeeded();

		// And meta key 應為 pc_subtitles_chapter_video
		$meta_value = \get_post_meta( $this->chapter_id, 'pc_subtitles_chapter_video', true );
		$this->assertIsArray( $meta_value, 'meta key pc_subtitles_chapter_video 應存在且為陣列' );
		$this->assertCount( 1, $meta_value, '字幕列表應有 1 筆' );
		$this->assertSame( 'zh-TW', $meta_value[0]['srclang'] );

		// And 舊的 chapter_subtitles meta key 不應被使用
		$old_meta = \get_post_meta( $this->chapter_id, 'chapter_subtitles', true );
		$this->assertEmpty( $old_meta, '不應使用舊的 chapter_subtitles meta key' );

		@unlink( $vtt_path );
	}
}
