<?php
/**
 * 更新學員觀看期限：重複儲存相同值（Issue #292）
 *
 * 問題：AVLCourseMeta::update() 底層 $wpdb->update() 寫入同值時回傳 0（受影響列數），
 * save_meta_update_student() 以 ! $success 判斷，把「同值」當成寫入失敗並丟例外，
 * 批次更新也因此中斷，後面的學員都不會被處理。
 *
 * @group student
 * @group issue-292
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;
use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * Class UpdateStudentSameExpireDateTest
 */
class UpdateStudentSameExpireDateTest extends TestCase {

	/** @var int 測試課程 ID */
	private int $course_id;

	/** @var int 已是無限制的學員 */
	private int $unlimited_id;

	/** @var int 仍有期限的學員 */
	private int $limited_id;

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 直接觸發 action hook 與 REST callback
	}

	/** 每個測試前建立資料 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id    = $this->create_course( [ 'post_title' => '同值更新測試課' ] );
		$this->unlimited_id = $this->factory()->user->create( [ 'user_login' => 'unlimited_' . uniqid() ] );
		$this->limited_id   = $this->factory()->user->create( [ 'user_login' => 'limited_' . uniqid() ] );

		$this->enroll_user_to_course( $this->unlimited_id, $this->course_id, 0 );
		$this->enroll_user_to_course( $this->limited_id, $this->course_id, 1893456000 );
	}

	/**
	 * 取得該學員該課程最新一筆 log 標題
	 *
	 * @param int $user_id 學員 ID
	 * @return string
	 */
	private function latest_log_title( int $user_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT title FROM {$table} WHERE user_id = %d AND course_id = %d ORDER BY id DESC LIMIT 1", // phpcs:ignore
				$user_id,
				$this->course_id
			)
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_已是無限制再存無限制_視為成功不丟例外(): void {
		try {
			do_action( LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION, $this->unlimited_id, $this->course_id, 0 );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assertSame( '0', (string) $this->get_course_meta( $this->course_id, $this->unlimited_id, 'expire_date' ) );
		$expected = sprintf(
			/* translators: 1: 課程 ID, 2: 到期日 */
			esc_html__( 'Admin manually updated course #%1$d expire date to %2$s successfully', 'power-course' ),
			$this->course_id,
			( new \J7\PowerCourse\Resources\Course\ExpireDate( 0 ) )->expire_date_label
		);
		$this->assertSame( $expected, $this->latest_log_title( $this->unlimited_id ), '同值更新應記錄為成功' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_已改成功後重試同一期限_視為成功(): void {
		$timestamp = 1924992000;
		do_action( LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION, $this->limited_id, $this->course_id, $timestamp );

		try {
			do_action( LifeCycle::AFTER_UPDATE_STUDENT_FROM_COURSE_ACTION, $this->limited_id, $this->course_id, $timestamp );
			$this->lastError = null;
		} catch ( \Throwable $e ) {
			$this->lastError = $e;
		}

		$this->assert_operation_succeeded();
		$this->assertSame( (string) $timestamp, (string) $this->get_course_meta( $this->course_id, $this->limited_id, 'expire_date' ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_批次名單先遇到同值學員_後續有期限學員仍被更新(): void {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );

		$request = new \WP_REST_Request( 'POST', '/power-course/courses/update-students' );
		$request->set_body_params(
			[
				'user_ids'   => [ (string) $this->unlimited_id, (string) $this->limited_id ],
				'course_ids' => [ (string) $this->course_id ],
				'timestamp'  => '0',
			]
		);

		$response = CourseApi::instance()->post_courses_update_students_callback( $request );

		$this->assertSame( 200, $response->get_status(), '批次更新應回傳成功：' . wp_json_encode( $response->get_data() ) );
		$this->assertSame( '0', (string) $this->get_course_meta( $this->course_id, $this->limited_id, 'expire_date' ), '同值學員之後的學員應被更新為無限制' );
	}
}
