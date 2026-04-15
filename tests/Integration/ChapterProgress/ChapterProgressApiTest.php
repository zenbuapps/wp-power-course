<?php
/**
 * ChapterProgress API 整合測試
 * Feature: specs/features/progress/紀錄章節續播秒數.feature
 * 測試 REST API POST/GET /power-course/v2/chapters/{id}/progress
 *
 * @group chapter-progress
 * @group api
 */

declare( strict_types=1 );

namespace Tests\Integration\ChapterProgress;

use Tests\Integration\TestCase;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\ChapterProgress\Service\Repository as ChapterProgressRepository;
use J7\PowerCourse\Resources\ChapterProgress\Service\Service as ChapterProgressService;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;

/**
 * Class ChapterProgressApiTest
 * 透過 WP REST API 測試章節續播秒數寫入/讀取
 */
final class ChapterProgressApiTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int Bunny 章節 ID */
	private int $chapter_bunny_id;

	/** @var int None 章節 ID */
	private int $chapter_none_id;

	/** @var int YouTube 章節 ID */
	private int $chapter_youtube_id;

	/** @var int Code 章節 ID */
	private int $chapter_code_id;

	/** @var int Alice 用戶 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// Service 與 Repository 皆為靜態方法類別
	}

	/**
	 * 每個測試前建立 Background 資料
	 */
	public function set_up(): void {
		parent::set_up();

		// Background: 建立課程
		$this->course_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		// Background: 建立章節（各種 video type）
		$this->chapter_bunny_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第一章' ]
		);
		update_post_meta( $this->chapter_bunny_id, 'chapter_video', [ 'type' => 'bunny', 'id' => 'bunny-video-id' ] );

		$this->chapter_none_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第二章' ]
		);
		update_post_meta( $this->chapter_none_id, 'chapter_video', [ 'type' => 'none' ] );

		$this->chapter_youtube_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第三章' ]
		);
		update_post_meta( $this->chapter_youtube_id, 'chapter_video', [ 'type' => 'youtube', 'id' => 'yt-video-id' ] );

		$this->chapter_code_id = $this->create_chapter(
			$this->course_id,
			[ 'post_title' => '第四章' ]
		);
		update_post_meta( $this->chapter_code_id, 'chapter_video', [ 'type' => 'code' ] );

		// Background: 建立 Alice 用戶
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_' . uniqid(),
				'user_email' => 'alice_' . uniqid() . '@test.com',
			]
		);
		$this->ids['Alice'] = $this->alice_id;

		// Background: Alice 加入課程（永久存取）
		$this->enroll_user_to_course( $this->alice_id, $this->course_id, 0 );
	}

	/**
	 * 清理 chapter_progress 表
	 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore
		parent::tear_down();
	}

	/**
	 * 呼叫 REST API POST /chapters/{id}/progress
	 *
	 * @param int   $chapter_id            章節 ID
	 * @param float $last_position_seconds 秒數
	 * @param int   $user_id               呼叫用戶 ID（0 = 未登入）
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_post_progress( int $chapter_id, float $last_position_seconds, int $user_id = 0 ): \WP_REST_Response|\WP_Error {
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		} else {
			wp_set_current_user( 0 );
		}

		$request = new \WP_REST_Request(
			'POST',
			"/power-course/chapters/{$chapter_id}/progress"
		);
		$request->set_param( 'last_position_seconds', $last_position_seconds );
		$request->set_param( 'course_id', $this->course_id );

		$response = rest_do_request( $request );
		return rest_ensure_response( $response );
	}

	/**
	 * 呼叫 REST API GET /chapters/{id}/progress
	 *
	 * @param int $chapter_id 章節 ID
	 * @param int $user_id    呼叫用戶 ID
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function call_get_progress( int $chapter_id, int $user_id = 0 ): \WP_REST_Response|\WP_Error {
		if ( $user_id > 0 ) {
			wp_set_current_user( $user_id );
		} else {
			wp_set_current_user( 0 );
		}

		$request  = new \WP_REST_Request(
			'GET',
			"/power-course/chapters/{$chapter_id}/progress"
		);
		$response = rest_do_request( $request );
		return rest_ensure_response( $response );
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Service 類別存在
	 */
	public function test_service_類別存在(): void {
		$this->assertTrue(
			class_exists( ChapterProgressService::class ),
			'ChapterProgressService 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 API 端點已註冊
	 */
	public function test_api_端點已註冊(): void {
		// REST routes 需要初始化
		do_action( 'rest_api_init' );

		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey(
			'/power-course/chapters/(?P<id>\d+)/progress',
			$routes,
			'章節 progress API 端點未被註冊'
		);
	}

	// ========== 權限測試（Q14）==========

	/**
	 * @test
	 * @group auth
	 * 未登入呼叫 POST progress 應回 403
	 * Example: 未登入呼叫回 403
	 */
	public function test_未登入呼叫POST回403(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 30.0, 0 );

		$this->assertSame(
			403,
			$response->get_status(),
			'未登入呼叫應回 403，實際回 ' . $response->get_status()
		);
	}

	/**
	 * @test
	 * @group auth
	 * 已登入但未擁有課程授權的用戶呼叫 POST progress 應回 403
	 * Example: 已登入但未擁有課程授權回 403
	 */
	public function test_無授權用戶呼叫POST回403(): void {
		// Bob 未被加入課程
		$bob_id = $this->factory()->user->create(
			[
				'user_login' => 'bob_' . uniqid(),
				'user_email' => 'bob_' . uniqid() . '@test.com',
			]
		);

		$response = $this->call_post_progress( $this->chapter_bunny_id, 30.0, $bob_id );

		$this->assertSame(
			403,
			$response->get_status(),
			'無授權用戶應回 403，實際回 ' . $response->get_status()
		);

		// 確認資料庫中未寫入
		$record = ChapterProgressRepository::find( $bob_id, $this->chapter_bunny_id );
		$this->assertNull( $record, '無授權用戶的 progress 不應被寫入' );
	}

	/**
	 * @test
	 * @group auth
	 * 課程已到期的學員呼叫 POST progress 應回 403
	 * Example: 課程已到期的學員回 403
	 */
	public function test_課程已到期呼叫POST回403(): void {
		// 設定 Alice 的課程已到期（過去的 timestamp：2021-01-01）
		AVLCourseMeta::update( $this->course_id, $this->alice_id, 'expire_date', 1609459200 );

		$response = $this->call_post_progress( $this->chapter_bunny_id, 30.0, $this->alice_id );

		$this->assertSame(
			403,
			$response->get_status(),
			'課程已到期應回 403，實際回 ' . $response->get_status()
		);
	}

	/**
	 * @test
	 * @group auth
	 * 未登入呼叫 GET progress 應回 403
	 */
	public function test_未登入呼叫GET回403(): void {
		$response = $this->call_get_progress( $this->chapter_bunny_id, 0 );

		$this->assertSame(
			403,
			$response->get_status(),
			'未登入 GET 應回 403，實際回 ' . $response->get_status()
		);
	}

	// ========== <5 秒規則測試（Q4）==========

	/**
	 * @test
	 * @group happy
	 * <5 秒時 API 回 200 但 written = false，不寫入 DB
	 * Example: server 端 <5s 靜默略過
	 */
	public function test_秒數小於5秒時written為false(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 3.0, $this->alice_id );

		$this->assertSame( 200, $response->get_status(), '應回 200' );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'data', $data, 'response 應有 data 欄位' );
		$this->assertFalse(
			$data['data']['written'] ?? true,
			'<5s 時 written 應為 false'
		);

		// 確認 DB 無紀錄
		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNull( $record, '<5s 時不應寫入 DB' );
	}

	/**
	 * @test
	 * @group happy
	 * 恰好 5 秒應寫入（邊界值）
	 */
	public function test_秒數等於5秒時應寫入(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 5.0, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertTrue(
			$data['data']['written'] ?? false,
			'5s 時 written 應為 true'
		);

		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record, '5s 時應寫入 DB' );
	}

	// ========== 四捨五入測試（Q3）==========

	/**
	 * @test
	 * @group happy
	 * server 端四捨五入：120.7 → 121
	 * Example: 前端傳 120.7 秒，DB 儲存為 121
	 */
	public function test_秒數四捨五入為整數(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 120.7, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );

		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record );
		$this->assertSame( 121, $record->last_position_seconds, '120.7 應四捨五入為 121' );
	}

	/**
	 * @test
	 * @group happy
	 * 四捨五入：30.4 → 30（捨去）
	 */
	public function test_秒數捨去小數(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 30.4, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );

		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record );
		$this->assertSame( 30, $record->last_position_seconds, '30.4 應四捨五入為 30' );
	}

	// ========== Video Type 白名單測試（Q5）==========

	/**
	 * @test
	 * @group happy
	 * Bunny 類型可正常寫入
	 */
	public function test_bunny類型可寫入(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 60.0, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['data']['written'] ?? false );
	}

	/**
	 * @test
	 * @group happy
	 * YouTube 類型可正常寫入
	 * Example: YouTube 影片寫入正常
	 */
	public function test_youtube類型可寫入(): void {
		$response = $this->call_post_progress( $this->chapter_youtube_id, 60.0, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['data']['written'] ?? false );
	}

	/**
	 * @test
	 * @group error
	 * code embed 類型呼叫 POST progress 應回 400
	 * Example: code embed 章節不記錄（API 層拒絕）
	 */
	public function test_code類型呼叫POST回400(): void {
		$response = $this->call_post_progress( $this->chapter_code_id, 60.0, $this->alice_id );

		$this->assertSame(
			400,
			$response->get_status(),
			'code 類型應回 400，實際回 ' . $response->get_status()
		);
	}

	/**
	 * @test
	 * @group error
	 * none 類型呼叫 POST progress 應回 400
	 * Example: 無影片章節不記錄
	 */
	public function test_none類型呼叫POST回400(): void {
		$response = $this->call_post_progress( $this->chapter_none_id, 60.0, $this->alice_id );

		$this->assertSame(
			400,
			$response->get_status(),
			'none 類型應回 400，實際回 ' . $response->get_status()
		);
	}

	// ========== Upsert 與 last_visit_info 同步測試（Q13）==========

	/**
	 * @test
	 * @group happy
	 * 首次寫入應建立新列
	 * Example: 首次寫入建立新列
	 */
	public function test_首次呼叫POST建立新列(): void {
		// Given: 無既有紀錄

		// When: POST progress
		$response = $this->call_post_progress( $this->chapter_bunny_id, 42.0, $this->alice_id );

		// Then: 操作成功
		$this->assertSame( 200, $response->get_status() );

		// And: DB 應有一列
		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record );
		$this->assertSame( 42, $record->last_position_seconds );
	}

	/**
	 * @test
	 * @group happy
	 * 既有紀錄 upsert 更新
	 * Example: 既有紀錄更新不產生重複列
	 */
	public function test_既有紀錄upsert更新不重複(): void {
		// Given: 先寫入 60 秒
		$this->call_post_progress( $this->chapter_bunny_id, 60.0, $this->alice_id );

		// When: 再寫入 120 秒
		$response = $this->call_post_progress( $this->chapter_bunny_id, 120.0, $this->alice_id );

		// Then: 操作成功
		$this->assertSame( 200, $response->get_status() );

		// And: DB 應僅有一列
		global $wpdb;
		$table = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND chapter_id = %d", // phpcs:ignore
				$this->alice_id,
				$this->chapter_bunny_id
			)
		);
		$this->assertSame( 1, $count, '不應產生重複列' );

		// And: 秒數應更新為 120
		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record );
		$this->assertSame( 120, $record->last_position_seconds );
	}

	/**
	 * @test
	 * @group happy
	 * POST progress 同時更新 course 層 last_visit_info.chapter_id
	 * Example: 同時更新 course 層 last_chapter_id 指標
	 */
	public function test_POST同時更新last_visit_info(): void {
		// Given: Alice 在課程 100 的 last_visit_info.chapter_id 為另一個章節
		AVLCourseMeta::update(
			$this->course_id,
			$this->alice_id,
			'last_visit_info',
			[ 'chapter_id' => 9999, 'last_visit_at' => '2026-01-01 00:00:00' ]
		);

		// When: POST chapter_bunny_id 的 progress
		$response = $this->call_post_progress( $this->chapter_bunny_id, 30.0, $this->alice_id );

		// Then: 操作成功
		$this->assertSame( 200, $response->get_status() );

		// And: last_visit_info.chapter_id 應更新為 chapter_bunny_id
		$last_visit_info = AVLCourseMeta::get( $this->course_id, $this->alice_id, 'last_visit_info', true );
		$this->assertIsArray( $last_visit_info );
		$this->assertSame(
			$this->chapter_bunny_id,
			(int) ( $last_visit_info['chapter_id'] ?? 0 ),
			'last_visit_info.chapter_id 應更新為 chapter_bunny_id'
		);
	}

	// ========== 已完成章節後仍繼續記錄（Q6/Q12）==========

	/**
	 * @test
	 * @group happy
	 * 95% 完成後秒數持續更新（不凍結）
	 * Example: 95% 完成後秒數持續更新
	 */
	public function test_已完成章節後仍可寫入progress(): void {
		// Given: Alice 章節已完成
		$this->set_chapter_finished( $this->chapter_bunny_id, $this->alice_id, '2026-04-15 10:00:00' );

		// And: 先寫入 570 秒
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_bunny_id, $this->course_id, 570 );

		// When: 繼續 POST 580 秒
		$response = $this->call_post_progress( $this->chapter_bunny_id, 580.0, $this->alice_id );

		// Then: 操作成功
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['data']['written'] ?? false, '已完成章節仍應寫入' );

		// And: DB 應更新為 580
		$record = ChapterProgressRepository::find( $this->alice_id, $this->chapter_bunny_id );
		$this->assertNotNull( $record );
		$this->assertSame( 580, $record->last_position_seconds, '已完成章節後 progress 應更新' );
	}

	// ========== GET /progress 測試 ==========

	/**
	 * @test
	 * @group happy
	 * GET progress 無紀錄時回傳 last_position_seconds = 0
	 */
	public function test_GET無紀錄回傳秒數0(): void {
		$response = $this->call_get_progress( $this->chapter_bunny_id, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'data', $data );
		$this->assertSame(
			0,
			(int) ( $data['data']['last_position_seconds'] ?? -1 ),
			'無紀錄時 last_position_seconds 應為 0'
		);
	}

	/**
	 * @test
	 * @group happy
	 * GET progress 有紀錄時回傳正確秒數
	 */
	public function test_GET有紀錄回傳正確秒數(): void {
		// Given: 先寫入 120 秒
		ChapterProgressRepository::upsert( $this->alice_id, $this->chapter_bunny_id, $this->course_id, 120 );

		// When: GET progress
		$response = $this->call_get_progress( $this->chapter_bunny_id, $this->alice_id );

		// Then: 回傳 120
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			120,
			(int) ( $data['data']['last_position_seconds'] ?? -1 ),
			'GET 應回傳 120 秒'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Response body 應包含必要欄位
	 */
	public function test_POST回應body包含必要欄位(): void {
		$response = $this->call_post_progress( $this->chapter_bunny_id, 42.0, $this->alice_id );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();

		$this->assertArrayHasKey( 'data', $data );
		$required_keys = [ 'chapter_id', 'course_id', 'last_position_seconds', 'written' ];
		foreach ( $required_keys as $key ) {
			$this->assertArrayHasKey(
				$key,
				$data['data'],
				"Response data 缺少欄位：{$key}"
			);
		}
	}
}
