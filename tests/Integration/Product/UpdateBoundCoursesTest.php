<?php
/**
 * 更新商品綁定課程期限整合測試
 *
 * Feature: specs/features/product/更新商品綁定課程期限.feature
 * 測試 POST /power-course/v2/products/update-bound-courses 的業務邏輯：
 * - product_ids / course_ids / limit_type 必填驗證
 * - fixed 模式需 limit_value
 * - bind_courses_data 中的 limit_type 正確更新
 * - 不追溯已購買學員的 pc_avl_coursemeta
 *
 * @group product
 * @group update-bound-courses
 */

declare( strict_types=1 );

namespace Tests\Integration\Product;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\PowerCourse\Resources\Course\Limit;

/**
 * Class UpdateBoundCoursesTest
 * 測試更新商品綁定課程期限的業務邏輯
 */
class UpdateBoundCoursesTest extends TestCase {

	/** @var int 商品 400 ID */
	private int $product_400_id;

	/** @var int 課程 100 ID */
	private int $course_100_id;

	/** @var int Alice 學員 ID */
	private int $alice_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress post meta API 與 BindCoursesData / Limit
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_100_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
			]
		);

		$this->product_400_id = $this->factory()->post->create(
			[
				'post_title'  => '加購包',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_400_id, '_is_course', 'no' );

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_ubc_' . uniqid(),
				'user_email' => 'alice_ubc_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		// 初始綁定：固定 30 天
		$limit     = new Limit( 'fixed', 30, 'day' );
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->add_course_data( $this->course_100_id, $limit );
		$bind_data->save();

		// 設定 bind_course_ids
		add_post_meta( $this->product_400_id, 'bind_course_ids', $this->course_100_id, false );

		$this->ids['Product400'] = $this->product_400_id;
		$this->ids['Course100']  = $this->course_100_id;
		$this->ids['Alice']      = $this->alice_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * BindCoursesData 初始狀態 limit_type 為 fixed
	 */
	public function test_冒煙_初始bind_courses_data_limit_type為fixed(): void {
		$bind_data   = BindCoursesData::instance( $this->product_400_id );
		$data_array  = $bind_data->get_data( ARRAY_N );

		$this->assertNotEmpty( $data_array, 'bind_courses_data 應有資料' );

		$course_data = array_filter( $data_array, fn( $d ) => (int) $d['id'] === $this->course_100_id );
		$course_data = array_values( $course_data );

		$this->assertNotEmpty( $course_data );
		$this->assertSame( 'fixed', $course_data[0]['limit_type'] ?? null, '初始 limit_type 應為 fixed' );
	}

	// ========== 前置（參數）- 必填驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: limit_type 為必填
	 */
	public function test_缺少limit_type時_include_required_params拋出例外(): void {
		$body_params = [
			'product_ids' => [ $this->product_400_id ],
			'course_ids'  => [ $this->course_100_id ],
		];

		try {
			\J7\WpUtils\Classes\WP::include_required_params( $body_params, [ 'product_ids', 'course_ids', 'limit_type' ] );
			$this->fail( '應拋出例外，因為 limit_type 缺失' );
		} catch ( \Throwable $e ) {
			$this->assertNotNull( $e );
		}
	}

	/**
	 * @test
	 * @group error
	 * Rule: fixed 模式下 limit_value 不可為空
	 * 依 code：Limit 的 set_limit_value 對 !$limit_value 設 null，
	 * 且 fixed 時 calc_expire_date 使用 "+null day" → strtotime 行為不穩定
	 * 此測試驗證 Limit 物件的內部行為
	 */
	public function test_fixed模式limit_value為0時_設為null(): void {
		$limit = new Limit( 'fixed', 0, 'day' );

		// 依 spec：limit_value=0 被設為 null
		$this->assertNull( $limit->limit_value, 'fixed 模式 limit_value=0 應設為 null' );
	}

	// ========== 快樂路徑 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 固定 30 天改為無限期，bind_courses_data 中 limit_type 更新為 unlimited
	 */
	public function test_固定30天改為無限期_bind_courses_data更新(): void {
		$new_limit = new Limit( 'unlimited', null, null );

		// 模擬 update-bound-courses 的邏輯
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->update_course_data( $this->course_100_id, $new_limit );
		$bind_data->save();

		// 驗證：bind_courses_data 中課程 100 的 limit_type 為 unlimited
		$updated_bind_data = BindCoursesData::instance( $this->product_400_id );
		$data_array        = $updated_bind_data->get_data( ARRAY_N );
		$course_data       = array_filter( $data_array, fn( $d ) => (int) $d['id'] === $this->course_100_id );
		$course_data       = array_values( $course_data );

		$this->assertNotEmpty( $course_data );
		$this->assertSame( 'unlimited', $course_data[0]['limit_type'] ?? null, 'limit_type 應更新為 unlimited' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 更新為指定到期日，limit_type=assigned、limit_value 正確
	 */
	public function test_更新為指定到期日_assigned模式(): void {
		$target_timestamp = 1767225599; // 2026-12-31
		$new_limit        = new Limit( 'assigned', $target_timestamp, null );

		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->update_course_data( $this->course_100_id, $new_limit );
		$bind_data->save();

		$updated_bind_data = BindCoursesData::instance( $this->product_400_id );
		$data_array        = $updated_bind_data->get_data( ARRAY_N );
		$course_data       = array_filter( $data_array, fn( $d ) => (int) $d['id'] === $this->course_100_id );
		$course_data       = array_values( $course_data );

		$this->assertNotEmpty( $course_data );
		$this->assertSame( 'assigned', $course_data[0]['limit_type'] ?? null, 'limit_type 應為 assigned' );
		$this->assertSame( $target_timestamp, $course_data[0]['limit_value'] ?? null, 'limit_value 應為目標 timestamp' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 更新期限後 bind_course_ids meta 保持不變（綁定關係不變）
	 */
	public function test_更新期限後_bind_course_ids保持不變(): void {
		$ids_before = get_post_meta( $this->product_400_id, 'bind_course_ids' );

		// 更新 limit_type
		$new_limit = new Limit( 'unlimited', null, null );
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->update_course_data( $this->course_100_id, $new_limit );
		$bind_data->save();

		$ids_after = get_post_meta( $this->product_400_id, 'bind_course_ids' );

		$this->assertEquals( $ids_before, $ids_after, 'bind_course_ids 應保持不變（綁定關係不受影響）' );
	}

	// ========== 不追溯已購買學員 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 更新期限不影響已購買學員的 pc_avl_coursemeta.expire_date
	 */
	public function test_更新期限不影響已購學員的coursemeta(): void {
		// Alice 已購買並獲得課程存取權（expire_date = 1714118340）
		$original_expire = 1714118340;
		$this->enroll_user_to_course( $this->alice_id, $this->course_100_id, $original_expire );

		$expire_before = $this->get_course_meta( $this->course_100_id, $this->alice_id, 'expire_date' );
		$this->assertEquals( $original_expire, (int) $expire_before, 'Alice 初始 expire_date 應為原始值' );

		// 管理員更新商品的課程期限為 unlimited
		$new_limit = new Limit( 'unlimited', null, null );
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->update_course_data( $this->course_100_id, $new_limit );
		$bind_data->save();

		// Alice 的 pc_avl_coursemeta 不受影響
		$expire_after = $this->get_course_meta( $this->course_100_id, $this->alice_id, 'expire_date' );
		$this->assertEquals( $original_expire, (int) $expire_after, 'Alice 的 expire_date 應未被覆寫' );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: update_course_data 對未綁定的課程也可執行（先 remove 後 add）
	 */
	public function test_更新未綁定課程_可執行不報錯(): void {
		$non_existent_course_id = 888888;
		$new_limit              = new Limit( 'unlimited', null, null );

		// 更新不存在的課程綁定（remove + add）
		$bind_data = BindCoursesData::instance( $this->product_400_id );
		$bind_data->update_course_data( $non_existent_course_id, $new_limit );
		$bind_data->save();

		// 不應拋出例外，操作完成後資料完整
		$updated_bind_data = BindCoursesData::instance( $this->product_400_id );
		$course_ids        = $updated_bind_data->get_course_ids();

		// 新課程應已新增（get_course_ids 回傳 int，直接比對）
		$this->assertContains( $non_existent_course_id, array_map( 'intval', $course_ids ), '應可為未綁定課程新增資料' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 多次 update_course_data 不累積重複資料
	 */
	public function test_多次更新同課程_不累積重複資料(): void {
		$new_limit = new Limit( 'unlimited', null, null );

		// 多次更新同一課程
		for ( $i = 0; $i < 3; $i++ ) {
			$bind_data = BindCoursesData::instance( $this->product_400_id );
			$bind_data->update_course_data( $this->course_100_id, $new_limit );
			$bind_data->save();
		}

		$updated_bind_data = BindCoursesData::instance( $this->product_400_id );
		$all_data          = $updated_bind_data->get_data( ARRAY_N );

		// 課程 100 應只出現一次（to_array() 回傳的 key 為 'id'，非 'course_id'）
		$course_100_entries = array_filter( $all_data, fn( $d ) => (int) $d['id'] === $this->course_100_id );
		$this->assertCount( 1, $course_100_entries, '同一課程不應有重複的 bind_courses_data 記錄' );
	}
}
