<?php
/**
 * ChapterSortTool 整合測試
 *
 * @group mcp
 * @group chapter
 */

declare( strict_types=1 );

namespace Tests\Integration\Api\Mcp\Tools\Chapter;

use J7\PowerCourse\Api\Mcp\Tools\Chapter\ChapterSortTool;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use Tests\Integration\Mcp\IntegrationTestCase;

/**
 * Class ChapterSortToolTest
 */
class ChapterSortToolTest extends IntegrationTestCase {

	/**
	 * happy：管理員能執行章節排序
	 *
	 * @group happy
	 */
	public function test_admin_can_sort_chapters(): void {
		$this->create_admin_user();

		$a = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '章 A',
				'menu_order'  => 1,
				'post_status' => 'publish',
			]
		);
		$b = $this->factory()->post->create(
			[
				'post_type'   => ChapterCPT::POST_TYPE,
				'post_title'  => '章 B',
				'menu_order'  => 2,
				'post_status' => 'publish',
			]
		);

		$tool   = new ChapterSortTool();
		$result = $tool->run(
			[
				'sortable_data' => [
					[
						'id'         => $b,
						'parent_id'  => 0,
						'menu_order' => 0,
						'depth'      => 0,
					],
					[
						'id'         => $a,
						'parent_id'  => 0,
						'menu_order' => 1,
						'depth'      => 0,
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * 權限不足：subscriber 被拒
	 *
	 * @group security
	 */
	public function test_subscriber_is_denied(): void {
		$this->create_subscriber_user();

		$tool   = new ChapterSortTool();
		$result = $tool->run(
			[
				'sortable_data' => [
					[
						'id'         => 1,
						'parent_id'  => 0,
						'menu_order' => 0,
					],
				],
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_permission_denied', $result->get_error_code() );
	}

	/**
	 * schema 錯誤：空的 sortable_data 回傳 422
	 *
	 * @group smoke
	 */
	public function test_empty_sortable_data_returns_error(): void {
		$this->create_admin_user();

		$tool   = new ChapterSortTool();
		$result = $tool->run( [ 'sortable_data' => [] ] );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcp_invalid_input', $result->get_error_code() );
	}
}
