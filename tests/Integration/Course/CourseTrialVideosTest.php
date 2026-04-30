<?php
/**
 * 課程多影片試看 (Issue #10) 整合測試
 *
 * 對應 specs/features/course/多影片試看.feature
 *
 * 覆蓋範圍：
 * - trial_videos 讀取（lazy migration、向下相容）
 * - trial_videos 寫入驗證（陣列 / 上限 / 缺欄位 / type=none 過濾）
 * - 寫入 trial_videos 同時刪除舊 trial_video
 *
 * @group course
 * @group issue-10
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class CourseTrialVideosTest
 */
class CourseTrialVideosTest extends TestCase {

	/** @var int */
	private int $course_id;

	/** @var CourseApi */
	private CourseApi $api;

	protected function configure_dependencies(): void {
		$this->api = CourseApi::instance();
	}

	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->create_course(
			[
				'post_title' => 'Issue #10 多影片試看測試',
				'_is_course' => 'yes',
			]
		);
	}

	/**
	 * 透過 REST API 更新課程
	 *
	 * @param array<string, mixed> $body 請求 body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_course_via_api( array $body ) {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/courses/' . $this->course_id );
		$request->set_url_params( [ 'id' => (string) $this->course_id ] );
		$request->set_body_params( $body );
		return $this->api->post_courses_with_id_callback( $request );
	}

	/**
	 * 從 format_course_records 抓取 trial_videos
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function get_trial_videos_from_api(): array {
		$product = \wc_get_product( $this->course_id );
		$formatted = $this->api->format_course_records( $product );
		return $formatted['trial_videos'] ?? [];
	}

	// ========== 寫入驗證 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_新增6部試看影片成功(): void {
		$videos = [
			[ 'type' => 'bunny-stream-api', 'id' => 'b1', 'meta' => [] ],
			[ 'type' => 'bunny-stream-api', 'id' => 'b2', 'meta' => [] ],
			[ 'type' => 'youtube', 'id' => 'yt1', 'meta' => [] ],
			[ 'type' => 'youtube', 'id' => 'yt2', 'meta' => [] ],
			[ 'type' => 'vimeo', 'id' => 'vm1', 'meta' => [] ],
			[ 'type' => 'vimeo', 'id' => 'vm2', 'meta' => [] ],
		];
		$result = $this->update_course_via_api( [ 'trial_videos' => $videos ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result, '6 部影片應寫入成功' );

		$saved = $this->get_trial_videos_from_api();
		$this->assertCount( 6, $saved );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_新增7部試看影片失敗_HTTP_400(): void {
		$videos = array_fill( 0, 7, [ 'type' => 'bunny-stream-api', 'id' => 'b1', 'meta' => [] ] );
		$result = $this->update_course_via_api( [ 'trial_videos' => $videos ] );

		$this->assertInstanceOf( \WP_Error::class, $result, '7 部影片應失敗' );
		$status = $result instanceof \WP_Error ? $result->get_error_data()['status'] ?? null : null;
		$this->assertSame( 400, $status );
		$this->assertStringContainsString( 'trial videos', (string) $result->get_error_message() );
	}

	/**
	 * @test
	 */
	public function test_trial_videos_非陣列被拒絕(): void {
		$result = $this->update_course_via_api(
			[ 'trial_videos' => [ 'type' => 'bunny-stream-api', 'id' => 'x' ] ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$status = $result instanceof \WP_Error ? $result->get_error_data()['status'] ?? null : null;
		$this->assertSame( 400, $status );
	}

	/**
	 * @test
	 */
	public function test_缺少type欄位的影片被拒絕(): void {
		$result = $this->update_course_via_api(
			[ 'trial_videos' => [ [ 'id' => 'xxx-1' ] ] ]
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$status = $result instanceof \WP_Error ? $result->get_error_data()['status'] ?? null : null;
		$this->assertSame( 400, $status );
	}

	/**
	 * @test
	 */
	public function test_type為none的項目自動過濾(): void {
		$videos = [
			[ 'type' => 'bunny-stream-api', 'id' => 'b-1', 'meta' => [] ],
			[ 'type' => 'none', 'id' => '', 'meta' => [] ],
		];
		$result = $this->update_course_via_api( [ 'trial_videos' => $videos ] );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$saved = $this->get_trial_videos_from_api();
		$this->assertCount( 1, $saved );
		$this->assertSame( 'bunny-stream-api', $saved[0]['type'] );
	}

	// ========== 後置（狀態）==========

	/**
	 * @test
	 */
	public function test_儲存時_寫入JSON陣列至postmeta(): void {
		$videos = [
			[ 'type' => 'bunny-stream-api', 'id' => 'b-1', 'meta' => [] ],
			[ 'type' => 'youtube', 'id' => 'yt-001', 'meta' => [] ],
			[ 'type' => 'vimeo', 'id' => 'vm-001', 'meta' => [] ],
		];
		$this->update_course_via_api( [ 'trial_videos' => $videos ] );

		$raw = \get_post_meta( $this->course_id, 'trial_videos', true );
		$this->assertIsString( $raw );
		$decoded = json_decode( (string) $raw, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 3, $decoded );
		$this->assertSame( 'b-1', $decoded[0]['id'] );
		$this->assertSame( 'yt-001', $decoded[1]['id'] );
		$this->assertSame( 'vm-001', $decoded[2]['id'] );
	}

	/**
	 * @test
	 */
	public function test_寫入trial_videos時_自動刪除舊trial_video_meta(): void {
		// Given 舊資料
		\update_post_meta(
			$this->course_id,
			'trial_video',
			[ 'type' => 'bunny-stream-api', 'id' => 'old-1', 'meta' => [] ]
		);

		// When 寫入新欄位
		$this->update_course_via_api(
			[
				'trial_videos' => [
					[ 'type' => 'bunny-stream-api', 'id' => 'new-1', 'meta' => [] ],
				],
			]
		);

		// Then 舊 meta 不應存在；新 meta 為長度 1 的 JSON
		$legacy = \get_post_meta( $this->course_id, 'trial_video', true );
		$this->assertSame( '', $legacy, '舊 trial_video meta 應被刪除' );

		$raw = \get_post_meta( $this->course_id, 'trial_videos', true );
		$decoded = json_decode( (string) $raw, true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 'new-1', $decoded[0]['id'] );
	}

	/**
	 * @test
	 */
	public function test_全部清空trial_videos_寫入空陣列且舊meta刪除(): void {
		// Given 已有資料
		\update_post_meta(
			$this->course_id,
			'trial_video',
			[ 'type' => 'bunny-stream-api', 'id' => 'old', 'meta' => [] ]
		);

		// When 清空
		$this->update_course_via_api( [ 'trial_videos' => [] ] );

		$raw = \get_post_meta( $this->course_id, 'trial_videos', true );
		$this->assertSame( '[]', $raw, 'trial_videos 應為 JSON 空陣列' );
		$legacy = \get_post_meta( $this->course_id, 'trial_video', true );
		$this->assertSame( '', $legacy );
	}

	// ========== 向下相容 ==========

	/**
	 * @test
	 */
	public function test_向下相容_僅有舊trial_video時GET回傳長度1陣列(): void {
		\update_post_meta(
			$this->course_id,
			'trial_video',
			[ 'type' => 'bunny-stream-api', 'id' => 'legacy-1', 'meta' => [] ]
		);

		$videos = $this->get_trial_videos_from_api();
		$this->assertCount( 1, $videos );
		$this->assertSame( 'bunny-stream-api', $videos[0]['type'] );
		$this->assertSame( 'legacy-1', $videos[0]['id'] );
	}

	/**
	 * @test
	 */
	public function test_向下相容_舊trial_video為none視為空(): void {
		\update_post_meta(
			$this->course_id,
			'trial_video',
			[ 'type' => 'none', 'id' => '', 'meta' => [] ]
		);

		$videos = $this->get_trial_videos_from_api();
		$this->assertSame( [], $videos );
	}

	/**
	 * @test
	 */
	public function test_新舊meta同時存在時_trial_videos優先(): void {
		\update_post_meta(
			$this->course_id,
			'trial_video',
			[ 'type' => 'bunny-stream-api', 'id' => 'legacy-1', 'meta' => [] ]
		);
		\update_post_meta(
			$this->course_id,
			'trial_videos',
			(string) wp_json_encode(
				[
					[ 'type' => 'youtube', 'id' => 'new-1', 'meta' => [] ],
				]
			)
		);

		$videos = $this->get_trial_videos_from_api();
		$this->assertCount( 1, $videos );
		$this->assertSame( 'new-1', $videos[0]['id'] );
	}
}
