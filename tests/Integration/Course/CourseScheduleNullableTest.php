<?php
/**
 * 課程開課時間 nullable 行為整合測試 (Issue #222)
 *
 * 對應 specs/features/course/開課時間DatePicker顯示.feature
 *
 * 覆蓋範圍：
 * - course_schedule meta 為 '' / '0' / 0 / 不存在 → API 回傳 null
 * - course_schedule meta 為合法 timestamp → API 回傳 int
 * - 避免前端 DatePicker 把 0/'0' 誤解為 1970-01-01 或顯示 "Invalid date"
 *
 * @group course
 * @group issue-222
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class CourseScheduleNullableTest
 */
class CourseScheduleNullableTest extends TestCase {

	/** @var CourseApi */
	private CourseApi $api;

	protected function configure_dependencies(): void {
		$this->api = CourseApi::instance();
	}

	/**
	 * 透過 format_course_base_records 取得 course_schedule
	 *
	 * 注意：這裡刻意**不能**用 `$formatted['course_schedule'] ?? '__MISSING__'`。
	 * PHP 的 null 合併運算子對「key 不存在」與「key 存在但值為 null」回傳同一個結果，
	 * 會把生產碼「正確回傳 null」誤判成「欄位消失」，讓斷言永遠失敗。
	 * 要區分這兩種語意只能用 array_key_exists()。
	 *
	 * @param int $course_id Course (product) ID.
	 * @return mixed 欄位值；欄位不存在時回傳 '__MISSING__' sentinel。
	 */
	private function get_formatted_course_schedule( int $course_id ): mixed {
		$product   = \wc_get_product( $course_id );
		$formatted = $this->api->format_course_base_records( $product );

		if ( ! array_key_exists( 'course_schedule', $formatted ) ) {
			return '__MISSING__';
		}

		return $formatted['course_schedule'];
	}

	// ========== Happy Path ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_meta不存在時API回傳null(): void {
		$course_id = $this->create_course( [ 'post_title' => '新建課程預設無 meta' ] );
		\delete_post_meta( $course_id, 'course_schedule' );

		$schedule = $this->get_formatted_course_schedule( $course_id );

		$this->assertNull( $schedule, 'meta 不存在時 course_schedule 應為 null' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_meta為合法timestamp時API回傳對應integer(): void {
		$course_id = $this->create_course( [ 'post_title' => '已排程開課' ] );
		\update_post_meta( $course_id, 'course_schedule', 1735689600 );

		$schedule = $this->get_formatted_course_schedule( $course_id );

		$this->assertIsInt( $schedule, 'course_schedule 應為 int' );
		$this->assertSame( 1735689600, $schedule, 'course_schedule 應為 1735689600' );
	}

	// ========== Edge Cases（Issue #222 雙保險） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_meta為空字串時API回傳null(): void {
		$course_id = $this->create_course( [ 'post_title' => '開課時間空字串' ] );
		\update_post_meta( $course_id, 'course_schedule', '' );

		$schedule = $this->get_formatted_course_schedule( $course_id );

		$this->assertNull( $schedule, 'course_schedule meta 為空字串應視為未設定 (null)' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_meta為字串0時API回傳null(): void {
		$course_id = $this->create_course( [ 'post_title' => '開課時間為字串 0' ] );
		\update_post_meta( $course_id, 'course_schedule', '0' );

		$schedule = $this->get_formatted_course_schedule( $course_id );

		$this->assertNull(
			$schedule,
			'course_schedule meta 為字串 "0" 應視為未設定 (null)，避免前端 DatePicker 解讀為 1970-01-01'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_meta為數字0時API回傳null(): void {
		$course_id = $this->create_course( [ 'post_title' => '開課時間為數字 0' ] );
		\update_post_meta( $course_id, 'course_schedule', 0 );

		$schedule = $this->get_formatted_course_schedule( $course_id );

		$this->assertNull(
			$schedule,
			'course_schedule meta 為數字 0 應視為未設定 (null)，避免前端 DatePicker 解讀為 1970-01-01'
		);
	}
}
