<?php
/**
 * BundleDeleteProductsTool 整合測試
 *
 * @group mcp
 * @group bundle
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\Tools\Bundle\BundleDeleteProductsTool;
use J7\PowerCourse\BundleProduct\Helper;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class BundleDeleteProductsToolTest
 */
class BundleDeleteProductsToolTest extends IntegrationTestCase {

	/**
	 * 建立一個含商品與數量的 bundle
	 *
	 * @param int        $course_id 綁定課程
	 * @param array<int> $product_ids 包含的 product_ids
	 * @return int bundle id
	 */
	private function create_bundle_with_products( int $course_id, array $product_ids ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '測試方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_id, 'bundle_type', 'bundle' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );

		foreach ( $product_ids as $pid ) {
			\add_post_meta( $bundle_id, Helper::INCLUDE_PRODUCT_IDS_META_KEY, (string) $pid );
		}

		$encoded = [];
		foreach ( $product_ids as $pid ) {
			$encoded[ (string) $pid ] = 1;
		}
		\update_post_meta( $bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, \wp_json_encode( $encoded ) );

		return $bundle_id;
	}

	/**
	 * happy：未指定 product_ids 時清空所有
	 *
	 * @group happy
	 */
	public function test_admin_can_clear_all_bundled_ids(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$other_id  = $this->factory()->post->create(
			[
				'post_title'  => '其他商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		$bundle_id = $this->create_bundle_with_products( $course_id, [ $course_id, $other_id ] );

		// 同時設定已廢棄的 exclude_main_course，驗證 clear 時也會移除
		\update_post_meta( $bundle_id, 'exclude_main_course', 'yes' );

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run( [ 'id' => $bundle_id ] );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['cleared'] );
		$this->assertContains( $course_id, $result['removed_ids'] );
		$this->assertContains( $other_id, $result['removed_ids'] );
		$this->assertSame( [], $result['product_ids'] );

		// 驗證 DB：product_ids 與 quantities 都被清空
		$helper_after = Helper::instance( $bundle_id );
		$this->assertNotNull( $helper_after );
		$this->assertSame( [], $helper_after->get_product_ids() );
		$this->assertSame( '', (string) \get_post_meta( $bundle_id, 'exclude_main_course', true ) );
	}

	/**
	 * happy：指定 product_ids 時只移除指定的
	 *
	 * @group happy
	 */
	public function test_admin_can_remove_specific_ids(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$other_id  = $this->factory()->post->create(
			[
				'post_title'  => '其他商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		$bundle_id = $this->create_bundle_with_products( $course_id, [ $course_id, $other_id ] );

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run(
			[
				'id'          => $bundle_id,
				'product_ids' => [ $other_id ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['cleared'] );
		$this->assertSame( [ $other_id ], $result['removed_ids'] );

		// 驗證 DB：course_id 保留，other_id 被移除
		$helper_after = Helper::instance( $bundle_id );
		$this->assertNotNull( $helper_after );
		$remaining = array_map( 'intval', $helper_after->get_product_ids() );
		$this->assertContains( $course_id, $remaining );
		$this->assertNotContains( $other_id, $remaining );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run( [ 'id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 非 bundle 商品：返回 mcp_bundle_invalid
	 *
	 * @group smoke
	 */
	public function test_non_bundle_product_returns_error(): void {
		$this->create_admin_user();
		$normal_id = $this->factory()->post->create(
			[
				'post_title'  => '一般商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);

		$tool   = new BundleDeleteProductsTool();
		$result = $tool->run( [ 'id' => $normal_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_bundle_invalid', $result->get_error_code() );
	}
}
