<?php
/**
 * BundleGetTool 整合測試
 *
 * @group mcp
 * @group bundle
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Bundle;

use J7\PowerCourse\Api\Mcp\Tools\Bundle\BundleGetTool;
use J7\PowerCourse\BundleProduct\Helper;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class BundleGetToolTest
 */
class BundleGetToolTest extends IntegrationTestCase {

	/**
	 * 建立一個 bundle 商品
	 *
	 * @param int        $course_id          綁定課程
	 * @param array<int> $product_ids        包含的商品 IDs
	 * @param array<int, int>|null $quantities product_id => qty
	 * @return int bundle id
	 */
	private function create_bundle_with_products( int $course_id, array $product_ids = [], ?array $quantities = null ): int {
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

		if ( null !== $quantities ) {
			$encoded = [];
			foreach ( $quantities as $pid => $qty ) {
				$encoded[ (string) $pid ] = (int) $qty;
			}
			\update_post_meta( $bundle_id, Helper::PRODUCT_QUANTITIES_META_KEY, \wp_json_encode( $encoded ) );
		}

		return $bundle_id;
	}

	/**
	 * happy：管理員能取得 bundle 詳情
	 *
	 * @group happy
	 */
	public function test_admin_can_get_bundle(): void {
		$this->create_admin_user();
		$course_id = $this->create_course();
		$other_id  = $this->factory()->post->create(
			[
				'post_title'  => '其他商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		$bundle_id = $this->create_bundle_with_products(
			$course_id,
			[ $course_id, $other_id ],
			[ $course_id => 1, $other_id => 2 ]
		);

		$tool   = new BundleGetTool();
		$result = $tool->run( [ 'id' => $bundle_id ] );

		$this->assertIsArray( $result );
		$this->assertSame( $bundle_id, (int) $result['id'] );
		$this->assertSame( $course_id, (int) $result['link_course_id'] );
		$this->assertContains( $course_id, $result['product_ids'] );
		$this->assertContains( $other_id, $result['product_ids'] );
		$this->assertSame( 2, (int) $result['product_quantities'][ (string) $other_id ] );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new BundleGetTool();
		$result = $tool->run( [ 'id' => 1 ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * 缺 id：返回 422 錯誤
	 *
	 * @group smoke
	 */
	public function test_missing_id_returns_error(): void {
		$this->create_admin_user();

		$tool   = new BundleGetTool();
		$result = $tool->run( [] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
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

		$tool   = new BundleGetTool();
		$result = $tool->run( [ 'id' => $normal_id ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_bundle_invalid', $result->get_error_code() );
	}
}
