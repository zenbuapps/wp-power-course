<?php
/**
 * 查詢課程權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/查詢課程權限包.feature
 * Issue #252：課程通行證（Access Pass）CRUD API
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Query 類別尚未實作
 *   - Query::list 方法不存在
 *
 * @group access-pass
 * @group query
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Service\Query;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class QueryAccessPassTest
 * 驗證課程權限包的查詢業務邏輯（對映 查詢課程權限包.feature 的所有 Rule/Example）
 */
class QueryAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 一次性商品 500 */
	private int $product_500;

	/** @var int 一次性商品 501 */
	private int $product_501;

	/** @var int active 全站永久權限包（pass_300）*/
	private int $pass_300;

	/** @var int active category 永久權限包（pass_301）*/
	private int $pass_301;

	/** @var int disabled 舊版全站永久權限包（pass_302）*/
	private int $pass_302;

	/** @var int 課程分類 term_id（HTML）*/
	private int $term_html;

	/**
	 * 初始化依賴（Query 使用靜態方法，不需注入）
	 */
	protected function configure_dependencies(): void {
		// Query::class 尚未實作，Red 階段不初始化
	}

	/**
	 * 每個測試前建立 Background fixture
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立管理員
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_' . uniqid(),
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;
		\wp_set_current_user( $this->admin_id );

		// Background：建立分類 term（HTML）
		$html_result     = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_html = \is_wp_error( $html_result ) ? 0 : (int) $html_result['term_id'];

		// Background：建立商品
		$this->product_500 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '全站通行證',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_500, '_price', '1999' );
		$this->ids['product_500'] = $this->product_500;

		$this->product_501 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => 'HTML 系列包',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_501, '_price', '999' );
		$this->ids['product_501'] = $this->product_501;

		// Background：建立 active 全站永久權限包（pass_300）
		$this->pass_300 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '全站課程權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_300, 'scope_type', 'all' );
		\update_post_meta( $this->pass_300, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_300, 'access_pass_status', 'active' );
		$this->ids['pass_300'] = $this->pass_300;

		// Background：建立 active category 權限包（pass_301，term_ids=[term_html]）
		$this->pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => 'HTML 權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_301, 'scope_type', 'category' );
		\update_post_meta( $this->pass_301, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_301, 'access_pass_status', 'active' );
		\add_post_meta( $this->pass_301, 'scope_term_ids', $this->term_html, false );
		$this->ids['pass_301'] = $this->pass_301;

		// Background：建立 disabled 舊版權限包（pass_302）
		$this->pass_302 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '舊版權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_302, 'scope_type', 'all' );
		\update_post_meta( $this->pass_302, 'limit_type', 'unlimited' );
		\update_post_meta( $this->pass_302, 'access_pass_status', 'disabled' );
		$this->ids['pass_302'] = $this->pass_302;

		// Background：設定掛載關係（商品 500 掛 300；商品 501 掛 301）
		\update_post_meta( $this->product_500, 'access_pass_id', $this->pass_300 );
		\update_post_meta( $this->product_501, 'access_pass_id', $this->pass_301 );
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT
	 */
	public function tear_down(): void {
		$passes = \get_posts(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $passes as $pass_id ) {
			\wp_force_delete_post( $pass_id );
		}
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Query 類別存在（Red：預期失敗）
	 */
	public function test_query_類別存在(): void {
		$this->assertTrue(
			\class_exists( Query::class ),
			'J7\PowerCourse\Resources\AccessPass\Service\Query 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Query::list 方法存在（Red：預期失敗）
	 */
	public function test_query_list_方法存在(): void {
		$this->assertTrue(
			\method_exists( Query::class, 'list' ),
			'Query::list 方法不存在'
		);
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（回應）- 回傳所有權限包清單，含狀態與範圍類型
	 *
	 * Example: 查詢權限包列表，應含 pass_300(active/all)、pass_301(active/category)、pass_302(disabled/all)
	 */
	public function test_查詢列表含所有權限包含status與scope_type(): void {
		$result = Query::list();

		$this->assertIsArray( $result, '查詢結果應為陣列' );

		// 提取 ID 清單
		$ids = \array_map( fn( $item ) => (int) ( $item['id'] ?? $item->id ?? 0 ), $result );

		$this->assertContains( $this->pass_300, $ids, '應含 pass_300' );
		$this->assertContains( $this->pass_301, $ids, '應含 pass_301' );
		$this->assertContains( $this->pass_302, $ids, '應含 pass_302（disabled 也要列出）' );

		// 找到 pass_300 並驗證欄位
		$pass_300_item = null;
		foreach ( $result as $item ) {
			$item_id = (int) ( $item['id'] ?? $item->id ?? 0 );
			if ( $item_id === $this->pass_300 ) {
				$pass_300_item = $item;
				break;
			}
		}

		$this->assertNotNull( $pass_300_item, '應找到 pass_300' );

		// 驗證 scope_type 與 status 欄位（支援 array 或 object 存取）
		$scope_type = \is_array( $pass_300_item ) ? ( $pass_300_item['scope_type'] ?? null ) : ( $pass_300_item->scope_type ?? null );
		$status     = \is_array( $pass_300_item ) ? ( $pass_300_item['status'] ?? null ) : ( $pass_300_item->status ?? null );

		$this->assertSame( 'all', $scope_type, 'pass_300 的 scope_type 應為 all' );
		$this->assertSame( 'active', $status, 'pass_300 的 status 應為 active' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（回應）- 回傳每個權限包已掛載的商品數
	 *
	 * Example: 查詢結果含已掛載商品數
	 *   pass_300 的 attached_product_count = 1（商品 500）
	 *   pass_301 的 attached_product_count = 1（商品 501）
	 */
	public function test_查詢結果含attached_product_count(): void {
		$result = Query::list();

		$this->assertIsArray( $result, '查詢結果應為陣列' );

		// 找 pass_300 的 attached_product_count
		$pass_300_item = null;
		foreach ( $result as $item ) {
			$item_id = (int) ( $item['id'] ?? $item->id ?? 0 );
			if ( $item_id === $this->pass_300 ) {
				$pass_300_item = $item;
				break;
			}
		}
		$this->assertNotNull( $pass_300_item, '應找到 pass_300' );

		$count_300 = \is_array( $pass_300_item )
			? ( $pass_300_item['attached_product_count'] ?? null )
			: ( $pass_300_item->attached_product_count ?? null );
		$this->assertSame( 1, (int) $count_300, 'pass_300 的 attached_product_count 應為 1' );

		// 找 pass_301 的 attached_product_count
		$pass_301_item = null;
		foreach ( $result as $item ) {
			$item_id = (int) ( $item['id'] ?? $item->id ?? 0 );
			if ( $item_id === $this->pass_301 ) {
				$pass_301_item = $item;
				break;
			}
		}
		$this->assertNotNull( $pass_301_item, '應找到 pass_301' );

		$count_301 = \is_array( $pass_301_item )
			? ( $pass_301_item['attached_product_count'] ?? null )
			: ( $pass_301_item->attached_product_count ?? null );
		$this->assertSame( 1, (int) $count_301, 'pass_301 的 attached_product_count 應為 1' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（回應）- 可依狀態篩選權限包（status=active 排除 disabled）
	 *
	 * Example: 僅查詢啟用中的權限包
	 *   When status='active' 篩選
	 *   Then 含 pass_300、pass_301；不含 pass_302
	 */
	public function test_依status篩選只回傳active權限包(): void {
		$result = Query::list( [ 'status' => 'active' ] );

		$this->assertIsArray( $result, '查詢結果應為陣列' );

		$ids = \array_map( fn( $item ) => (int) ( $item['id'] ?? $item->id ?? 0 ), $result );

		$this->assertContains( $this->pass_300, $ids, 'active 篩選應含 pass_300' );
		$this->assertContains( $this->pass_301, $ids, 'active 篩選應含 pass_301' );
		$this->assertNotContains( $this->pass_302, $ids, 'active 篩選應排除 disabled 的 pass_302' );
	}
}
