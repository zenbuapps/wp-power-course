<?php
/**
 * 銷售方案移除課程（含當前課程） 整合測試
 * Issue #249 BUG2：handle_special_fields 收到 pbp_product_ids 時應立旗標
 * bundle_edited_product_ids = 'yes'，使 get_product_ids_with_compat() 尊重真實列表、
 * 不再自動補回課程；且購買時不授權被移除的課程。
 *
 * Feature: specs/features/bundle/移除排除當前課程功能.feature
 *
 * @group bundle
 * @group bundle-remove-course
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Api\Product as ProductApi;
use J7\PowerCourse\Resources\Course\BindCoursesData;

/**
 * Class BundleRemoveCourseTest
 */
class BundleRemoveCourseTest extends TestCase {

	/** @var int 課程商品 ID（link_course_id） */
	private int $course_id;

	/** @var int 銷售方案商品 ID */
	private int $bundle_id;

	/** @var int 普通商品 ID */
	private int $product_id;

	/** @var int 顧客用戶 ID */
	private int $customer_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 依賴 Bootstrap 已註冊的 Resources\Order hooks
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->course_id = $this->factory()->post->create(
			[
				'post_title'  => '當前課程',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->course_id, '_is_course', 'yes' );
		update_post_meta( $this->course_id, '_price', '0' );
		update_post_meta( $this->course_id, '_regular_price', '0' );
		update_post_meta( $this->course_id, 'limit_type', 'unlimited' );

		$this->product_id = $this->factory()->post->create(
			[
				'post_title'  => '周邊商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_id, '_price', '500' );
		update_post_meta( $this->product_id, '_regular_price', '500' );

		$this->bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '銷售方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->bundle_id, 'bundle_type', 'bundle' );
		update_post_meta( $this->bundle_id, '_price', '0' );
		update_post_meta( $this->bundle_id, '_regular_price', '0' );
		update_post_meta( $this->bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $this->course_id );

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_login' => 'buyer_' . uniqid(),
				'user_email' => 'buyer_' . uniqid() . '@test.com',
			]
		);
	}

	/**
	 * 取得 bundle 的 helper
	 */
	private function bundle_helper(): Helper {
		$helper = Helper::instance( wc_get_product( $this->bundle_id ) );
		$this->assertNotNull( $helper );
		return $helper;
	}

	// ========== 常數 ==========

	/**
	 * @test
	 * @group smoke
	 * EDITED_PRODUCT_IDS_META_KEY 常數應存在且值正確
	 */
	public function test_EDITED_PRODUCT_IDS_META_KEY_常數存在(): void {
		$this->assertSame( 'bundle_edited_product_ids', Helper::EDITED_PRODUCT_IDS_META_KEY );
	}

	// ========== handle_special_fields：送 pbp_product_ids → 立旗標 ==========

	/**
	 * @test
	 * @group happy
	 * 送含商品（不含課程）的 pbp_product_ids → 立旗標、寫入列表、compat 不補課程
	 */
	public function test_送不含課程的列表立旗標且不補課程(): void {
		$product   = wc_get_product( $this->bundle_id );
		$meta_data = [
			Helper::INCLUDE_PRODUCT_IDS_META_KEY => [ (string) $this->product_id ],
		];

		$result = ProductApi::handle_special_fields( $meta_data, $product );
		$this->assertIsArray( $result, 'handle_special_fields 不應回傳 WP_Error' );

		// 旗標應為 'yes'
		$this->assertSame( 'yes', get_post_meta( $this->bundle_id, Helper::EDITED_PRODUCT_IDS_META_KEY, true ) );

		// 真實列表只剩周邊商品
		$ids = $this->bundle_helper()->get_product_ids();
		$this->assertSame( [ (string) $this->product_id ], $ids );

		// compat 不補課程
		$compat_ids = $this->bundle_helper()->get_product_ids_with_compat();
		$this->assertNotContains( (string) $this->course_id, $compat_ids, 'compat 不應補回課程' );
		$this->assertContains( (string) $this->product_id, $compat_ids );
	}

	/**
	 * @test
	 * @group edge
	 * 送顯式空值 '[]' 字串 → 清空列表、立旗標、compat 不補課程（比照 Issue #203 空陣列處理）
	 */
	public function test_送顯式空值字串清空列表且不補課程(): void {
		// 先放一筆，確認後面確實被清空
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		$product   = wc_get_product( $this->bundle_id );
		$meta_data = [
			Helper::INCLUDE_PRODUCT_IDS_META_KEY => '[]',
		];

		$result = ProductApi::handle_special_fields( $meta_data, $product );
		$this->assertIsArray( $result );

		$this->assertSame( 'yes', get_post_meta( $this->bundle_id, Helper::EDITED_PRODUCT_IDS_META_KEY, true ) );

		$this->assertSame( [], $this->bundle_helper()->get_product_ids(), '列表應被清空' );
		$this->assertSame( [], $this->bundle_helper()->get_product_ids_with_compat(), 'compat 不應補回課程' );
	}

	/**
	 * @test
	 * @group edge
	 * 再存不復活：立旗標後即使列表不含課程，compat 也不會把課程補回
	 */
	public function test_再存不復活(): void {
		$product = wc_get_product( $this->bundle_id );

		// 第一次：移除課程，只留周邊商品
		ProductApi::handle_special_fields(
			[ Helper::INCLUDE_PRODUCT_IDS_META_KEY => [ (string) $this->product_id ] ],
			$product
		);

		// 第二次：再存一次相同列表（模擬站長重新儲存）
		ProductApi::handle_special_fields(
			[ Helper::INCLUDE_PRODUCT_IDS_META_KEY => [ (string) $this->product_id ] ],
			wc_get_product( $this->bundle_id )
		);

		$compat_ids = $this->bundle_helper()->get_product_ids_with_compat();
		$this->assertNotContains( (string) $this->course_id, $compat_ids, '再存後課程仍不應復活' );
	}

	/**
	 * @test
	 * @group happy
	 * payload 完全沒有 pbp_product_ids key → 保持原狀（既有合約，不可誤清、不立旗標）
	 */
	public function test_payload無pbp_key保持原狀(): void {
		// 既有列表（含課程與周邊）
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->course_id );
		add_post_meta( $this->bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $this->product_id );

		$product = wc_get_product( $this->bundle_id );
		// payload 不含 pbp_product_ids，只含其他無關欄位
		$meta_data = [ 'some_other_field' => 'value' ];

		$result = ProductApi::handle_special_fields( $meta_data, $product );
		$this->assertIsArray( $result );

		// 列表不被動到
		$ids = $this->bundle_helper()->get_product_ids();
		$this->assertContains( (string) $this->course_id, $ids, '未送 key 時列表應保持原狀' );
		$this->assertContains( (string) $this->product_id, $ids );

		// 旗標不應被立（仍為空）
		$this->assertSame( '', get_post_meta( $this->bundle_id, Helper::EDITED_PRODUCT_IDS_META_KEY, true ) );
	}

	// ========== 購買不授權被移除課程 ==========

	/**
	 * @test
	 * @group order
	 * @group happy
	 * 移除課程後購買方案，不授權被移除的課程（端到端）
	 */
	public function test_移除課程後購買不授權該課程(): void {
		$product = wc_get_product( $this->bundle_id );

		// 站長移除課程，只留周邊商品
		ProductApi::handle_special_fields(
			[ Helper::INCLUDE_PRODUCT_IDS_META_KEY => [ (string) $this->product_id ] ],
			$product
		);

		$this->assert_user_has_no_course_access( $this->customer_id, $this->course_id );

		// 下單買方案
		$order = wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->add_product( wc_get_product( $this->bundle_id ), 1 );
		$order->save();

		do_action( 'woocommerce_new_order', $order->get_id(), $order );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );

		// Then：被移除的課程不應被授權
		$this->assert_user_has_no_course_access( $this->customer_id, $this->course_id );
	}

	// ========== 授權側：bind_course_ids / bind_courses_data 字串空值 reconcile ==========

	/**
	 * 為 bundle 預先綁定課程（寫 bind_courses_data + bind_course_ids），模擬「方案綁定一門課程」
	 *
	 * @param int $course_id 課程 ID
	 * @return void
	 */
	private function seed_bound_course( int $course_id ): void {
		update_post_meta(
			$this->bundle_id,
			'bind_courses_data',
			[
				[
					'id'          => $course_id,
					'name'        => get_the_title( $course_id ),
					'limit_type'  => 'unlimited',
					'limit_value' => 0,
					'limit_unit'  => 'day',
				],
			]
		);
		add_post_meta( $this->bundle_id, 'bind_course_ids', (string) $course_id );
	}

	/**
	 * @test
	 * @group happy
	 * 移除唯一課程：前端送字串 '[]'（toFormData 繞過空陣列）→ reconcile 須清空授權側
	 *
	 * 這是 integration gap 的核心斷言：bind_course_ids='[]'（字串）必須被 json_decode 解析、
	 * 進入 reconcile，把 bind_courses_data 內的舊綁定移除；否則購買時 Order.php 仍授權該課程。
	 */
	public function test_送字串空值移除唯一課程_清空授權側(): void {
		// Given：方案綁定了當前課程
		$this->seed_bound_course( $this->course_id );
		$this->assertContains(
			(int) $this->course_id,
			array_map( 'intval', BindCoursesData::instance( $this->bundle_id )->get_course_ids() ),
			'前置條件：方案應已綁定課程'
		);

		$product = wc_get_product( $this->bundle_id );

		// When：前端移除唯一課程，bind_course_ids 與 pbp_product_ids 皆送字串 '[]'
		$result = ProductApi::handle_special_fields(
			[
				Helper::INCLUDE_PRODUCT_IDS_META_KEY => '[]',
				'bind_course_ids'                    => '[]',
			],
			$product
		);
		$this->assertIsArray( $result );

		// Then：授權側 bind_courses_data 被清空、不含該課程
		$instance = BindCoursesData::instance( $this->bundle_id );
		$this->assertSame( [], $instance->get_data( ARRAY_N ), 'bind_courses_data 應被清空' );
		$this->assertNotContains(
			(int) $this->course_id,
			array_map( 'intval', $instance->get_course_ids() ),
			'get_course_ids() 不應再含被移除的課程'
		);

		// bind_course_ids meta 也應清空
		$this->assertSame( [], get_post_meta( $this->bundle_id, 'bind_course_ids' ) ?: [] );
	}

	/**
	 * @test
	 * @group order
	 * @group happy
	 * 移除唯一課程（字串 '[]'）後購買方案，不授權被移除的課程（端到端，授權側）
	 */
	public function test_送字串空值移除課程後購買不授權(): void {
		// Given：方案綁定了當前課程
		$this->seed_bound_course( $this->course_id );

		$product = wc_get_product( $this->bundle_id );

		// When：移除唯一課程（字串 '[]'）
		ProductApi::handle_special_fields(
			[
				Helper::INCLUDE_PRODUCT_IDS_META_KEY => '[]',
				'bind_course_ids'                    => '[]',
			],
			$product
		);

		$this->assert_user_has_no_course_access( $this->customer_id, $this->course_id );

		// 下單買方案
		$order = wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->add_product( wc_get_product( $this->bundle_id ), 1 );
		$order->save();

		do_action( 'woocommerce_new_order', $order->get_id(), $order );

		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );

		// Then：授權側已清空，購買不應授權被移除的課程
		$this->assert_user_has_no_course_access( $this->customer_id, $this->course_id );
	}
}
