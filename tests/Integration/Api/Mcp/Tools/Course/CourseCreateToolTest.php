<?php
/**
 * Course Create MCP Tool 整合測試
 *
 * @group mcp
 * @group course
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Course;

use J7\PowerCourse\Api\Mcp\Tools\Course\CourseCreateTool;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class CourseCreateToolTest
 */
class CourseCreateToolTest extends IntegrationTestCase {

	/**
	 * Happy path：管理員成功建立課程
	 *
	 * @group happy
	 */
	public function test_admin_can_create_course(): void {
		$this->create_admin_user();

		$tool   = new CourseCreateTool();
		$result = $tool->run(
			[
				'name'          => '新課程 via MCP',
				'status'        => 'draft',
				'regular_price' => '1000',
			]
		);

		$this->assertIsArray( $result, '預期回傳含 id 的陣列' );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertGreaterThan( 0, (int) $result['id'] );

		// 驗證資料確實落入 DB
		$product = \wc_get_product( (int) $result['id'] );
		$this->assertInstanceOf( \WC_Product::class, $product );
		$this->assertSame( '新課程 via MCP', $product->get_name() );
	}

	/**
	 * 權限不足：subscriber 應被拒絕
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new CourseCreateTool();
		$result = $tool->run( [ 'name' => 'Forbidden Course' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * Schema validation：缺少 required name 應回傳 422
	 *
	 * @group error
	 */
	public function test_missing_name_returns_validation_error(): void {
		$this->create_admin_user();

		$tool   = new CourseCreateTool();
		$result = $tool->run( [ 'status' => 'draft' ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 422, $result->get_error_data()['status'] ?? 0 );
	}
}
