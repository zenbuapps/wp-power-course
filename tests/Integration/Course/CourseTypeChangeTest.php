<?php
/**
 * 課程類型切換 (Issue #235) 整合測試
 *
 * 對應 specs/features/external-course/切換課程類型.feature
 * 對應 specs/api/api.yml — POST /courses/{id} 的 confirm_type_change 旗標
 *
 * 覆蓋範圍：
 * - confirm_type_change 旗標契約（向下相容、白名單、no-op）
 * - WC_Product class 切換（Simple ↔ External）
 * - 資料保留語意（章節、學員授權、product_url meta）
 * - format_course_records 新增 student_count / chapter_count 欄位
 *
 * @group course
 * @group issue-235
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;
use J7\PowerCourse\Plugin;

/**
 * Class CourseTypeChangeTest
 */
class CourseTypeChangeTest extends TestCase {

	/** @var CourseApi */
	private CourseApi $api;

	protected function configure_dependencies(): void {
		$this->api = CourseApi::instance();
	}

	/**
	 * 透過 REST API 更新課程
	 *
	 * @param int                  $course_id 課程 ID
	 * @param array<string, mixed> $body 請求 body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_course_via_api( int $course_id, array $body ) {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/courses/' . $course_id );
		$request->set_url_params( [ 'id' => (string) $course_id ] );
		$request->set_body_params( $body );
		return $this->api->post_courses_with_id_callback( $request );
	}

	/**
	 * 建立站內課程（simple product + _is_course = yes）
	 *
	 * @param string $title 課程標題
	 * @return int
	 */
	private function create_simple_course( string $title = 'Issue #235 站內課程測試' ): int {
		$course_id = $this->create_course(
			[
				'post_title' => $title,
				'_is_course' => 'yes',
			]
		);
		// 設定 product_type taxonomy 為 simple
		\wp_set_object_terms( $course_id, 'simple', 'product_type' );
		\wc_delete_product_transients( $course_id );
		return $course_id;
	}

	/**
	 * 建立外部平台課程（external product + _is_course = yes）
	 *
	 * @param string $title 課程標題
	 * @return int
	 */
	private function create_external_course( string $title = 'Issue #235 外部平台課程測試' ): int {
		$course_id = $this->create_course(
			[
				'post_title' => $title,
				'_is_course' => 'yes',
			]
		);
		\wp_set_object_terms( $course_id, 'external', 'product_type' );
		\update_post_meta( $course_id, '_product_url', 'https://hahow.in/courses/12345' );
		\update_post_meta( $course_id, '_button_text', '前往課程' );
		\wc_delete_product_transients( $course_id );
		return $course_id;
	}

	// ========== confirm_type_change 旗標契約 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_未帶confirm_type_change時忽略type欄位(): void {
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type' => 'external',
				'name' => '改名後的站內課程',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '一般更新（無 confirm_type_change）不應失敗' );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertSame( 'simple', $product->get_type(), '未帶旗標時，type 變更必須被忽略' );
		$this->assertSame( '改名後的站內課程', $product->get_name(), 'name 仍應更新' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_帶confirm_type_change_simple切external_class切換成功(): void {
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'external',
				'confirm_type_change' => 'true',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '切換為 external 應成功' );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertSame( 'external', $product->get_type(), 'product_type taxonomy 應切換為 external' );
		$this->assertInstanceOf( \WC_Product_External::class, $product, 'wc_get_product() 應回傳 WC_Product_External 實例' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_帶confirm_type_change_external切simple_class切換成功(): void {
		$course_id = $this->create_external_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'simple',
				'confirm_type_change' => 'true',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '切換為 simple 應成功' );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertSame( 'simple', $product->get_type(), 'product_type taxonomy 應切換為 simple' );
		$this->assertInstanceOf( \WC_Product_Simple::class, $product, 'wc_get_product() 應回傳 WC_Product_Simple 實例' );
	}

	// ========== 資料保留語意 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_外部切站內後product_url_meta保留(): void {
		$course_id = $this->create_external_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'simple',
				'confirm_type_change' => 'true',
			]
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$this->assertSame(
			'https://hahow.in/courses/12345',
			\get_post_meta( $course_id, '_product_url', true ),
			'_product_url meta 應在切換為 simple 後仍保留'
		);
		$this->assertSame(
			'前往課程',
			\get_post_meta( $course_id, '_button_text', true ),
			'_button_text meta 應在切換為 simple 後仍保留'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_切回外部時product_url自動帶回(): void {
		$course_id = $this->create_external_course();

		// 先切為 simple
		$this->update_course_via_api(
			$course_id,
			[
				'type'                => 'simple',
				'confirm_type_change' => 'true',
			]
		);
		\wc_delete_product_transients( $course_id );

		// 再切回 external
		$this->update_course_via_api(
			$course_id,
			[
				'type'                => 'external',
				'confirm_type_change' => 'true',
			]
		);
		\wc_delete_product_transients( $course_id );

		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product_External::class, $product );
		$this->assertSame(
			'https://hahow.in/courses/12345',
			$product->get_product_url(),
			'切回 external 後，product_url 應自動帶回原值'
		);
		$this->assertSame(
			'前往課程',
			$product->get_button_text(),
			'切回 external 後，button_text 應自動帶回原值'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_切換站內課程後章節資料保留(): void {
		$course_id = $this->create_simple_course();
		// 預先建 3 個章節
		$chapter_ids = [];
		for ( $i = 1; $i <= 3; $i++ ) {
			$chapter_ids[] = $this->create_chapter(
				$course_id,
				[
					'post_title'  => '章節 ' . $i,
					'post_parent' => $course_id,
				]
			);
		}

		// 切換為 external
		$this->update_course_via_api(
			$course_id,
			[
				'type'                => 'external',
				'confirm_type_change' => 'true',
			]
		);

		// 章節在 DB 中仍存在
		foreach ( $chapter_ids as $chapter_id ) {
			$post = \get_post( $chapter_id );
			$this->assertNotNull( $post, "章節 {$chapter_id} 不應被刪除" );
			$this->assertSame( 'pc_chapter', $post->post_type );
		}
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_切換站內課程後學員授權保留(): void {
		global $wpdb;

		$course_id = $this->create_simple_course();
		$user_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		// 預先建立學員授權記錄
		$this->enroll_user_to_course( $user_id, $course_id, 0 );

		$rows_before = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}" . Plugin::COURSE_TABLE_NAME . ' WHERE post_id = %d',
				$course_id
			)
		);
		$this->assertGreaterThan( 0, $rows_before, '前置條件：應有學員授權記錄' );

		// 切換為 external
		$this->update_course_via_api(
			$course_id,
			[
				'type'                => 'external',
				'confirm_type_change' => 'true',
			]
		);

		$rows_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}" . Plugin::COURSE_TABLE_NAME . ' WHERE post_id = %d',
				$course_id
			)
		);
		$this->assertSame( $rows_before, $rows_after, '學員授權記錄應在切換後保留' );
	}

	// ========== no-op 與錯誤情境 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_同類型切換為no_op(): void {
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'simple',
				'confirm_type_change' => 'true',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$data = $result->get_data();
		$this->assertTrue(
			(bool) ( $data['type_change_skipped'] ?? false ),
			'同類型切換時 response 應包含 type_change_skipped: true'
		);

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertSame( 'simple', $product->get_type(), '同類型不應更動 taxonomy' );
	}

	/**
	 * @test
	 * @group sad
	 */
	public function test_type非合法值回400(): void {
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'bundle',
				'confirm_type_change' => 'true',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result, '非法 type 應回 WP_Error' );
		$error_data = $result->get_error_data();
		$this->assertSame( 400, $error_data['status'] ?? null, '應回 HTTP 400' );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertSame( 'simple', $product->get_type(), '非法 type 應不切換' );
	}

	// ========== format_course_records 新欄位 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_format_course_records含student_count_chapter_count(): void {
		$course_id = $this->create_simple_course();

		// 建立 2 個章節
		$this->create_chapter( $course_id, [ 'post_parent' => $course_id ] );
		$this->create_chapter( $course_id, [ 'post_parent' => $course_id ] );

		// 加入 3 個學員
		for ( $i = 0; $i < 3; $i++ ) {
			$user_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
			$this->enroll_user_to_course( $user_id, $course_id, 0 );
		}

		\wc_delete_product_transients( $course_id );
		$product   = \wc_get_product( $course_id );
		$formatted = $this->api->format_course_records( $product );

		$this->assertArrayHasKey( 'student_count', $formatted, 'format_course_records 應包含 student_count 欄位' );
		$this->assertArrayHasKey( 'chapter_count', $formatted, 'format_course_records 應包含 chapter_count 欄位' );
		$this->assertSame( 3, (int) $formatted['student_count'], 'student_count 應為 3' );
		$this->assertSame( 2, (int) $formatted['chapter_count'], 'chapter_count 應為 2' );
	}
}
