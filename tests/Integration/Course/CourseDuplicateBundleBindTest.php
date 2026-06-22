<?php
/**
 * 複製課程時銷售方案綁定課程同步 整合測試
 * Issue #249 BUG1：複製方案時只改 link_course_ids / pbp，沒碰購買實際授權所依據的
 * bind_courses_data / bind_course_ids，導致複製出的方案購買後授權到舊課程。
 *
 * Feature: specs/features/course/複製課程.feature
 *
 * @group bundle
 * @group course
 * @group duplicate
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\Utils\Duplicate;

/**
 * Class CourseDuplicateBundleBindTest
 */
class CourseDuplicateBundleBindTest extends TestCase {

	/** @var int 顧客用戶 ID */
	private int $customer_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 依賴 Bootstrap 已註冊的 Resources\Order hooks
	}

	/**
	 * 每個測試前建立顧客
	 */
	public function set_up(): void {
		parent::set_up();

		$this->customer_id = $this->factory()->user->create(
			[
				'role'       => 'customer',
				'user_login' => 'buyer_' . uniqid(),
				'user_email' => 'buyer_' . uniqid() . '@test.com',
			]
		);
	}

	/**
	 * 建立課程商品
	 *
	 * @param string $title 課程標題
	 * @param string $price 價格
	 * @return int 課程商品 ID
	 */
	private function make_course( string $title, string $price = '0' ): int {
		$course_id = $this->factory()->post->create(
			[
				'post_title'  => $title,
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $course_id, '_is_course', 'yes' );
		update_post_meta( $course_id, '_price', $price );
		update_post_meta( $course_id, '_regular_price', $price );
		update_post_meta( $course_id, 'limit_type', 'unlimited' );

		return $course_id;
	}

	/**
	 * 建立連結指定課程的銷售方案，並寫入 bind_courses_data / bind_course_ids
	 *
	 * @param int                                                              $course_id   連結課程 ID
	 * @param array<int, array{id:int,name:string,limit_type:string,limit_value:int|null,limit_unit:string|null}> $bind_data bind_courses_data
	 * @return int 方案商品 ID
	 */
	private function make_bundle( int $course_id, array $bind_data ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '方案_' . uniqid(),
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		update_post_meta( $bundle_id, '_price', '0' );
		update_post_meta( $bundle_id, '_regular_price', '0' );
		update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );

		// 購買實際授權所依據的兩份 meta
		update_post_meta( $bundle_id, 'bind_courses_data', $bind_data );
		foreach ( $bind_data as $row ) {
			add_post_meta( $bundle_id, 'bind_course_ids', (string) $row['id'] );
		}

		return $bundle_id;
	}

	/**
	 * 產生一筆 bind_courses_data
	 *
	 * @param int    $course_id   課程 ID
	 * @param string $limit_type  限制類型
	 * @param int    $limit_value 限制值
	 * @param string $limit_unit  限制單位
	 * @return array{id:int,name:string,limit_type:string,limit_value:int|null,limit_unit:string|null}
	 */
	private function bind_row( int $course_id, string $limit_type = 'fixed', int $limit_value = 30, string $limit_unit = 'day' ): array {
		return [
			'id'          => $course_id,
			'name'        => get_the_title( $course_id ),
			'limit_type'  => $limit_type,
			'limit_value' => $limit_value,
			'limit_unit'  => $limit_unit,
		];
	}

	// ========== BUG1：複製後 bind_courses_data 指向新課程 ==========

	/**
	 * @test
	 * @group happy
	 * 複製後新方案 bind_courses_data[0]['id'] == 新課程、name == 新課程標題、limit_* 保留
	 */
	public function test_複製後bind_courses_data指向新課程且limit保留(): void {
		$old_course_id = $this->make_course( '舊課程 A' );
		$new_course_id = $this->make_course( '新課程 B' );
		$bundle_id     = $this->make_bundle(
			$old_course_id,
			[ $this->bind_row( $old_course_id, 'fixed', 90, 'day' ) ]
		);

		// 複製方案，指定新 parent（新課程）
		$new_bundle_id = Duplicate::duplicate_product( $bundle_id, true, $new_course_id );

		$bind_courses_data = get_post_meta( $new_bundle_id, 'bind_courses_data', true );
		$this->assertIsArray( $bind_courses_data );
		$this->assertCount( 1, $bind_courses_data );

		$row = $bind_courses_data[0];
		$this->assertSame( $new_course_id, (int) $row['id'], 'bind_courses_data 應指向新課程' );
		$this->assertSame( get_the_title( $new_course_id ), $row['name'], 'name 應更新為新課程標題' );
		$this->assertSame( 'fixed', $row['limit_type'], 'limit_type 應保留' );
		$this->assertSame( 90, (int) $row['limit_value'], 'limit_value 應保留' );
		$this->assertSame( 'day', $row['limit_unit'], 'limit_unit 應保留' );
	}

	/**
	 * @test
	 * @group happy
	 * 複製後新方案 bind_course_ids == [新課程]
	 */
	public function test_複製後bind_course_ids指向新課程(): void {
		$old_course_id = $this->make_course( '舊課程 A' );
		$new_course_id = $this->make_course( '新課程 B' );
		$bundle_id     = $this->make_bundle(
			$old_course_id,
			[ $this->bind_row( $old_course_id ) ]
		);

		$new_bundle_id = Duplicate::duplicate_product( $bundle_id, true, $new_course_id );

		$bind_course_ids = array_map( 'intval', get_post_meta( $new_bundle_id, 'bind_course_ids' ) ?: [] );
		$this->assertContains( $new_course_id, $bind_course_ids, 'bind_course_ids 應含新課程' );
		$this->assertNotContains( $old_course_id, $bind_course_ids, 'bind_course_ids 不應再含舊課程' );
	}

	/**
	 * @test
	 * @group edge
	 * 多綁課程：只替換對應舊課程那筆，其餘站長刻意多綁的課程原樣保留
	 */
	public function test_多綁課程只替換對應筆其餘保留(): void {
		$old_course_id   = $this->make_course( '舊課程 A' );
		$new_course_id   = $this->make_course( '新課程 B' );
		$extra_course_id = $this->make_course( '額外多綁課程 C' );

		$bundle_id = $this->make_bundle(
			$old_course_id,
			[
				$this->bind_row( $old_course_id, 'fixed', 30, 'day' ),
				$this->bind_row( $extra_course_id, 'unlimited', 0, 'day' ),
			]
		);

		$new_bundle_id = Duplicate::duplicate_product( $bundle_id, true, $new_course_id );

		$bind_courses_data = get_post_meta( $new_bundle_id, 'bind_courses_data', true );
		$this->assertIsArray( $bind_courses_data );
		$ids = array_map( static fn( $r ): int => (int) $r['id'], $bind_courses_data );

		$this->assertContains( $new_course_id, $ids, '舊課程那筆應被替換為新課程' );
		$this->assertContains( $extra_course_id, $ids, '額外多綁課程應原樣保留' );
		$this->assertNotContains( $old_course_id, $ids, '舊課程不應殘留' );
		$this->assertCount( 2, $ids, '筆數不變' );
	}

	/**
	 * @test
	 * @group edge
	 * 守衛：bind_courses_data 含異常筆（非陣列 / 缺 id）時靜默跳過，不中斷複製
	 */
	public function test_異常筆靜默跳過不中斷複製(): void {
		$old_course_id = $this->make_course( '舊課程 A' );
		$new_course_id = $this->make_course( '新課程 B' );

		$bundle_id = $this->make_bundle(
			$old_course_id,
			[ $this->bind_row( $old_course_id ) ]
		);
		// 手動塞一筆異常資料（缺 id）與一筆非陣列
		update_post_meta(
			$bundle_id,
			'bind_courses_data',
			[
				$this->bind_row( $old_course_id ),
				[ 'name' => '缺 id 的異常筆' ],
				'not-an-array',
			]
		);

		$new_bundle_id = Duplicate::duplicate_product( $bundle_id, true, $new_course_id );

		$bind_courses_data = get_post_meta( $new_bundle_id, 'bind_courses_data', true );
		$this->assertIsArray( $bind_courses_data );
		$ids = array_map( static fn( $r ): int => (int) $r['id'], $bind_courses_data );
		$this->assertContains( $new_course_id, $ids, '正常筆應替換為新課程' );
		$this->assertCount( 1, $bind_courses_data, '異常筆應被靜默跳過' );
	}

	// ========== A→B→C 鏈：每代正確 ==========

	/**
	 * @test
	 * @group edge
	 * A→B→C 連續複製鏈，每代 bind_courses_data 都指向自己這代的課程
	 */
	public function test_連續複製鏈每代正確(): void {
		$course_a = $this->make_course( '課程 A' );
		$course_b = $this->make_course( '課程 B' );
		$course_c = $this->make_course( '課程 C' );

		$bundle_a = $this->make_bundle( $course_a, [ $this->bind_row( $course_a ) ] );

		// A → B
		$bundle_b = Duplicate::duplicate_product( $bundle_a, true, $course_b );
		$data_b   = get_post_meta( $bundle_b, 'bind_courses_data', true );
		$this->assertSame( $course_b, (int) $data_b[0]['id'], 'B 代應指向課程 B' );

		// B → C
		$bundle_c = Duplicate::duplicate_product( $bundle_b, true, $course_c );
		$data_c   = get_post_meta( $bundle_c, 'bind_courses_data', true );
		$this->assertSame( $course_c, (int) $data_c[0]['id'], 'C 代應指向課程 C' );

		// 原方案 A 不受影響
		$data_a = get_post_meta( $bundle_a, 'bind_courses_data', true );
		$this->assertSame( $course_a, (int) $data_a[0]['id'], '原方案 A 應維持指向課程 A' );
	}

	// ========== 下單付款授權到新課程 ==========

	/**
	 * @test
	 * @group order
	 * @group happy
	 * 複製後的方案下單付款，授權到新課程而非舊課程（BUG1 端到端）
	 */
	public function test_複製方案購買後授權到新課程(): void {
		$old_course_id = $this->make_course( '舊課程 A' );
		$new_course_id = $this->make_course( '新課程 B' );

		$bundle_id = $this->make_bundle(
			$old_course_id,
			[ $this->bind_row( $old_course_id, 'unlimited', 0, 'day' ) ]
		);

		$new_bundle_id = Duplicate::duplicate_product( $bundle_id, true, $new_course_id );

		// 顧客尚未擁有任何課程
		$this->assert_user_has_no_course_access( $this->customer_id, $old_course_id );
		$this->assert_user_has_no_course_access( $this->customer_id, $new_course_id );

		// 下單買複製後的方案
		$order = wc_create_order();
		$order->set_customer_id( $this->customer_id );
		$order->add_product( wc_get_product( $new_bundle_id ), 1 );
		$order->save();

		// 觸發 woocommerce_new_order：把方案內 bind_courses_data 快照成 _bind_courses_data
		do_action( 'woocommerce_new_order', $order->get_id(), $order );

		// 完成訂單觸發授權 hook
		$order = wc_get_order( $order->get_id() );
		$order->update_status( 'completed' );

		// Then：授權到新課程，不到舊課程
		$this->assert_user_has_course_access( $this->customer_id, $new_course_id );
		$this->assert_user_has_no_course_access( $this->customer_id, $old_course_id );
	}
}
