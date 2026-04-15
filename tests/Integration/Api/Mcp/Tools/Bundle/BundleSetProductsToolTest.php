<?php
/**
 * BundleSetProductsTool 整合測試（含原子操作還原測試）
 *
 * @group mcp
 * @group bundle
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\Tools\Bundle\BundleSetProductsTool;
use J7\PowerCourse\BundleProduct\Helper;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class BundleSetProductsToolTest
 */
class BundleSetProductsToolTest extends IntegrationTestCase {

	/**
	 * 建立一個 bundle 商品
	 *
	 * @param int $course_id 綁定課程
	 * @return int bundle id
	 */
	private function create_bundle( int $course_id ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '測試方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );
		return $bundle_id;
	}

	/**
	 * happy：管理員能設定 product_ids 與 quantities
	 *
	 * @group happy
	 */
	public function test_admin_can_set_products_and_quantities(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$other_id  = $this->factory()->post->create(
			[
				'post_title'  => '其他商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		$bundle_id = $this->create_bundle( $course_id );

		$tool   = new BundleSetProductsTool();
		$result = $tool->run(
			[
				'id'          => $bundle_id,
				'product_ids' => [ $course_id, $other_id ],
				'quantities'  => [
					(string) $course_id => 2,
					(string) $other_id  => 5,
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $bundle_id, (int) $result['id'] );
		$this->assertContains( $course_id, $result['product_ids'] );
		$this->assertContains( $other_id, $result['product_ids'] );
		$this->assertSame( 2, (int) $result['product_quantities'][ (string) $course_id ] );
		$this->assertSame( 5, (int) $result['product_quantities'][ (string) $other_id ] );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new BundleSetProductsTool();
		$result = $tool->run(
			[
				'id'          => 1,
				'product_ids' => [ 2 ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 向下相容：設定時自動清除 exclude_main_course meta
	 *
	 * @group compat
	 */
	public function test_set_products_clears_deprecated_meta(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$bundle_id = $this->create_bundle( $course_id );

		// 寫入已廢棄的 exclude_main_course meta
		\update_post_meta( $bundle_id, 'exclude_main_course', 'yes' );
		$this->assertSame( 'yes', \get_post_meta( $bundle_id, 'exclude_main_course', true ) );

		$tool = new BundleSetProductsTool();
		$tool->run(
			[
				'id'          => $bundle_id,
				'product_ids' => [ $course_id ],
			]
		);

		// 寫入後 exclude_main_course 應被清除
		$this->assertSame( '', \get_post_meta( $bundle_id, 'exclude_main_course', true ) );
	}

	/**
	 * quantities 值超出範圍：應回傳 422 且不修改 DB
	 *
	 * @group validation
	 */
	public function test_quantities_out_of_range_returns_error(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$bundle_id = $this->create_bundle( $course_id );

		$tool   = new BundleSetProductsTool();
		$result = $tool->run(
			[
				'id'          => $bundle_id,
				'product_ids' => [ $course_id ],
				'quantities'  => [ (string) $course_id => 1000 ],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );

		// product_ids 應保持未變
		$helper = Helper::instance( $bundle_id );
		$this->assertNotNull( $helper );
		$this->assertSame( [], $helper->get_product_ids() );
	}

	/**
	 * 原子操作：set_product_quantities 失敗時，product_ids 應還原
	 *
	 * 透過覆寫 'update_post_metadata' filter 讓 PRODUCT_QUANTITIES 寫入時丟 Exception
	 *
	 * @group atomic
	 */
	public function test_atomic_restore_when_quantities_fail(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$other_id  = $this->factory()->post->create(
			[
				'post_title'  => '其他商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		$bundle_id = $this->create_bundle( $course_id );

		// 設定初始狀態：bundle 已有一個 product_id
		\add_post_meta( $bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $course_id );

		// 攔截 quantities meta 寫入，使其拋出 Exception
		$throw = static function ( $check, $object_id, $meta_key ) {
			if ( Helper::PRODUCT_QUANTITIES_META_KEY === $meta_key ) {
				throw new \RuntimeException( '模擬 quantities 寫入失敗' );
			}
			return $check;
		};
		\add_filter( 'update_post_metadata', $throw, 10, 3 );

		try {
			$tool   = new BundleSetProductsTool();
			$result = $tool->run(
				[
					'id'          => $bundle_id,
					'product_ids' => [ $course_id, $other_id ],
					'quantities'  => [ (string) $course_id => 2, (string) $other_id => 3 ],
				]
			);

			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'mcp_bundle_set_failed', $result->get_error_code() );
		} finally {
			\remove_filter( 'update_post_metadata', $throw, 10 );
		}

		// 驗證：product_ids 應還原為操作前狀態（只有 $course_id）
		$helper_after = Helper::instance( $bundle_id );
		$this->assertNotNull( $helper_after );
		$restored_ids = array_map( 'intval', $helper_after->get_product_ids() );
		$this->assertSame( [ $course_id ], $restored_ids, 'product_ids 應還原為操作前狀態' );
	}
}
