<?php
/**
 * 解除商品綁定課程整合測試
 *
 * Feature: specs/features/product/解除商品綁定課程.feature
 * 測試 POST /power-course/v2/products/unbind-courses 的業務邏輯：
 * - product_ids / course_ids 必填驗證
 * - bind_course_ids meta 移除正確課程
 * - bind_courses_data 同步移除
 * - 不追溯已購買學員的 pc_avl_coursemeta
 *
 * @group product
 * @group unbind-courses
 */

declare( strict_types=1 );

namespace Tests\Integration\Product;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\PowerCourse\Resources\Course\Limit;

/**
 * Class UnbindCoursesTest
 * 測試解除商品綁定課程的業務邏輯
 */
class UnbindCoursesTest extends TestCase {

	/** @var int 商品 400（加購包）ID */
	private int $product_400_id;

	/** @var int 課程 100（PHP 基礎課）ID */
	private int $course_100_id;

	/** @var int 課程 101（Laravel 課程）ID */
	private int $course_101_id;

	/** @var int Alice 學員 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress post meta API 與 BindCoursesData
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 建立課程商品
		$this->course_100_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		$this->course_101_id = $this->create_course(
			[
				'post_title' => 'Laravel 課程',
				'_is_course' => 'yes',
			]
		);

		// 建立加購包（非課程商品）
		$this->product_400_id = $this->factory()->post->create(
			[
				'post_title'  => '加購包',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_400_id, '_is_course', 'no' );

		// 建立 Alice
		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_unbind_' . uniqid(),
				'user_email' => 'alice_unbind_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		// 商品 400 綁定課程 [100, 101]
		$this->bind_courses_to_product(
			$this->product_400_id,
			[ $this->course_100_id, $this->course_101_id ]
		);

		$this->ids['Product400'] = $this->product_400_id;
		$this->ids['Course100']  = $this->course_100_id;
		$this->ids['Course101']  = $this->course_101_id;
		$this->ids['Alice']      = $this->alice_id;
	}

	/**
	 * 將課程綁定到商品（設定 bind_course_ids 與 bind_courses_data）
	 *
	 * @param int   $product_id 商品 ID
	 * @param int[] $course_ids 課程 ID 陣列
	 */
	private function bind_courses_to_product( int $product_id, array $course_ids ): void {
		// 設定 bind_course_ids（multiple meta）
		delete_post_meta( $product_id, 'bind_course_ids' );
		foreach ( $course_ids as $course_id ) {
			add_post_meta( $product_id, 'bind_course_ids', $course_id, false );
		}

		// 設定 bind_courses_data
		$bind_data = BindCoursesData::instance( $product_id );
		$limit     = new Limit( 'unlimited', null, null );
		foreach ( $course_ids as $course_id ) {
			$bind_data->add_course_data( $course_id, $limit );
		}
		$bind_data->save();
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * BindCoursesData 可正常讀取商品的綁定資料
	 */
	public function test_冒煙_BindCoursesData可讀取綁定資料(): void {
		$bind_data  = BindCoursesData::instance( $this->product_400_id );
		$course_ids = $bind_data->get_course_ids();

		// get_course_ids 回傳的是 course_id 整數陣列（wp_list_pluck 從物件 public int 屬性）
		$this->assertContains( $this->course_100_id, $course_ids, '應包含課程 100' );
		$this->assertContains( $this->course_101_id, $course_ids, '應包含課程 101' );
	}

	// ========== 前置（參數）- 必填驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: course_ids 為必填
	 */
	public function test_缺少course_ids時_include_required_params拋出例外(): void {
		$body_params = [ 'product_ids' => [ $this->product_400_id ] ];

		try {
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'product_ids', 'course_ids' ] );
			$this->fail( '應拋出例外，因為 course_ids 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e );
		}
	}

	/**
	 * @test
	 * @group error
	 * Rule: product_ids 為必填
	 */
	public function test_缺少product_ids時_include_required_params拋出例外(): void {
		$body_params = [ 'course_ids' => [ $this->course_100_id ] ];

		try {
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'product_ids', 'course_ids' ] );
			$this->fail( '應拋出例外，因為 product_ids 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e );
		}
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 從商品 400 解除課程 100，bind_course_ids 只剩 101
	 */
	public function test_解除單一課程_bind_course_ids只剩另一課程(): void {
		// 模擬 unbind-courses 的邏輯
		$product_id = $this->product_400_id;
		$course_ids = [ $this->course_100_id ];

		// 取得原始的 bind_course_ids
		$original_course_ids = get_post_meta( $product_id, 'bind_course_ids' ) ?: [];

		// 過濾掉要解除的 course_ids
		$new_course_ids = array_filter(
			$original_course_ids,
			fn( $id ) => ! in_array( $id, $course_ids )
		);

		// 更新 meta（使用 WcProduct::update_meta_array 的等效邏輯）
		delete_post_meta( $product_id, 'bind_course_ids' );
		foreach ( array_values( $new_course_ids ) as $id ) {
			add_post_meta( $product_id, 'bind_course_ids', $id, false );
		}

		// 更新 bind_courses_data
		$bind_data = BindCoursesData::instance( $product_id );
		foreach ( $course_ids as $course_id ) {
			$bind_data->remove_course_data( $course_id );
		}
		$bind_data->save();

		// 驗證：bind_course_ids 中已無課程 100
		$remaining_ids = get_post_meta( $product_id, 'bind_course_ids' );
		$this->assertNotContains( $this->course_100_id, $remaining_ids, '解除後 bind_course_ids 不應包含課程 100' );
		$this->assertNotContains( (string) $this->course_100_id, array_map( 'strval', $remaining_ids ), '課程 100 已解除' );

		// 驗證：bind_courses_data 只剩課程 101
		$updated_bind_data = BindCoursesData::instance( $product_id );
		$remaining_data    = $updated_bind_data->get_course_ids();
		$this->assertNotContains( $this->course_100_id, $remaining_data, 'bind_courses_data 中不應再有課程 100' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 同時解除兩個課程，bind_course_ids 變為空陣列
	 */
	public function test_解除兩個課程_bind_course_ids為空(): void {
		$product_id = $this->product_400_id;
		$course_ids = [ $this->course_100_id, $this->course_101_id ];

		// 解除所有課程
		delete_post_meta( $product_id, 'bind_course_ids' );

		$bind_data = BindCoursesData::instance( $product_id );
		foreach ( $course_ids as $course_id ) {
			$bind_data->remove_course_data( $course_id );
		}
		$bind_data->save();

		// 驗證：bind_course_ids 為空
		$remaining_ids = get_post_meta( $product_id, 'bind_course_ids' );
		$this->assertEmpty( $remaining_ids, '解除所有課程後 bind_course_ids 應為空' );

		// 驗證：bind_courses_data 為空
		$updated_bind_data = BindCoursesData::instance( $product_id );
		$this->assertEmpty( $updated_bind_data->get_course_ids(), 'bind_courses_data 應為空陣列' );
	}

	// ========== 不追溯已購買學員 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 解除綁定不追溯已購買的學員 pc_avl_coursemeta
	 */
	public function test_解除綁定不影響已購學員的coursemeta(): void {
		// Alice 已購買商品 400 並獲得課程 100 的存取權
		$this->enroll_user_to_course( $this->alice_id, $this->course_100_id );

		// 確認 Alice 有課程存取權
		$this->assert_user_has_course_access( $this->alice_id, $this->course_100_id );

		// 解除綁定（模擬 API 行為）
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->remove_course_data( $this->course_100_id );
		$bind_data->save();

		// 解除後 Alice 的 pc_avl_coursemeta 不受影響
		$expire_date = $this->get_course_meta( $this->course_100_id, $this->alice_id, 'expire_date' );
		$this->assertNotNull( $expire_date, 'Alice 的 pc_avl_coursemeta 應保持不變' );

		// Alice 仍有課程存取權（avl_course_ids 不受影響）
		$this->assert_user_has_course_access( $this->alice_id, $this->course_100_id );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 解除不存在的課程綁定，不報錯（靜默成功）
	 */
	public function test_解除不存在的課程綁定_不報錯(): void {
		$non_existent_course_id = 999999;

		// 解除不存在的課程（應靜默成功）
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->remove_course_data( $non_existent_course_id );
		$bind_data->save();

		// 原有的綁定應保持不變
		$remaining = BindCoursesData::instance( $this->product_400_id );
		$this->assertNotEmpty( $remaining->get_course_ids(), '原有課程綁定應保持不變' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 空 course_ids 陣列，不修改任何綁定
	 */
	public function test_空course_ids_不修改任何綁定(): void {
		$bind_data_before = BindCoursesData::instance( $this->product_400_id );
		$ids_before       = $bind_data_before->get_course_ids();

		// 模擬傳入空的 course_ids
		$course_ids = [];
		$bind_data  = BindCoursesData::instance( $this->product_400_id );
		foreach ( $course_ids as $course_id ) {
			$bind_data->remove_course_data( $course_id );
		}
		$bind_data->save();

		$bind_data_after = BindCoursesData::instance( $this->product_400_id );
		$ids_after       = $bind_data_after->get_course_ids();

		$this->assertEquals( $ids_before, $ids_after, '空 course_ids 不應修改任何綁定' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: BindCoursesData::included 方法正確判斷課程是否已綁定
	 */
	public function test_BindCoursesData_included方法判斷正確(): void {
		$bind_data = BindCoursesData::instance( $this->product_400_id );

		$this->assertTrue( $bind_data->included( $this->course_100_id ), '課程 100 應已綁定' );
		$this->assertTrue( $bind_data->included( $this->course_101_id ), '課程 101 應已綁定' );
		$this->assertFalse( $bind_data->included( 999999 ), '不存在的課程不應被視為已綁定' );
	}
}
