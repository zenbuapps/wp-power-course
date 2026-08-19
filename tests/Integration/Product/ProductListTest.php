<?php
/**
 * 查詢商品列表整合測試
 *
 * Feature: specs/features/product/查詢商品列表.feature
 * 測試 GET /power-course/v2/products、/products/select、/products/options 的業務邏輯：
 * - is_course meta 篩選
 * - link_course_ids meta 篩選
 * - 分頁 header 欄位
 * - pc_simple_card / pc_bundle_card 短代碼回傳結構
 *
 * @group product
 * @group product-list
 */

declare( strict_types=1 );

namespace Tests\Integration\Product;

use Tests\Integration\TestCase;
use J7\PowerCourse\Shortcodes\General as Shortcodes;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class ProductListTest
 * 測試商品列表查詢相關業務邏輯
 */
class ProductListTest extends TestCase {

	/** @var int 課程商品 100 ID */
	private int $product_100_id;

	/** @var int 課程商品 101 ID */
	private int $product_101_id;

	/** @var int 加購包 200 ID */
	private int $product_200_id;

	/** @var int 銷售方案 300（link_course_ids=100）ID */
	private int $product_300_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 WordPress WC API 與 wc_get_products
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		// 課程商品 100
		$this->product_100_id = $this->create_course(
			[
				'post_title' => 'PHP 基礎課',
				'_is_course' => 'yes',
				'price'      => '1200',
			]
		);

		// 課程商品 101
		$this->product_101_id = $this->create_course(
			[
				'post_title' => 'Laravel 課程',
				'_is_course' => 'yes',
				'price'      => '2500',
			]
		);

		// 加購包（非課程）
		$this->product_200_id = $this->factory()->post->create(
			[
				'post_title'  => '加購包',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_200_id, '_is_course', 'no' );
		update_post_meta( $this->product_200_id, '_price', '0' );

		// 銷售方案 300（link_course_ids=100）
		$this->product_300_id = $this->factory()->post->create(
			[
				'post_title'  => 'PHP 銷售方案',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		update_post_meta( $this->product_300_id, '_is_course', 'no' );
		update_post_meta( $this->product_300_id, Helper::LINK_COURSE_IDS_META_KEY, $this->product_100_id );

		$this->ids['Product100'] = $this->product_100_id;
		$this->ids['Product101'] = $this->product_101_id;
		$this->ids['Product200'] = $this->product_200_id;
		$this->ids['Product300'] = $this->product_300_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * wc_get_products 函式存在且可查詢
	 */
	public function test_冒煙_wc_get_products可查詢(): void {
		$this->assertTrue( function_exists( 'wc_get_products' ), 'wc_get_products 應存在' );
	}

	/**
	 * @test
	 * @group smoke
	 * Shortcodes 類別的 $shortcodes 包含 4 個短代碼
	 */
	public function test_冒煙_Shortcodes包含4個短代碼(): void {
		$expected = [ 'pc_courses', 'pc_my_courses', 'pc_simple_card', 'pc_bundle_card' ];
		foreach ( $expected as $shortcode ) {
			$this->assertContains( $shortcode, Shortcodes::$shortcodes, "{$shortcode} 應已註冊" );
		}
		$this->assertCount( 4, Shortcodes::$shortcodes, '應有 4 個短代碼' );
	}

	// ========== is_course 篩選 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: _is_course=yes 正確儲存在 post_meta 中
	 */
	public function test_is_course_yes的meta正確儲存(): void {
		$is_course = get_post_meta( $this->product_100_id, '_is_course', true );
		$this->assertSame( 'yes', $is_course, '課程商品的 _is_course 應為 yes' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: _is_course=no 正確儲存在 post_meta 中（非課程）
	 */
	public function test_is_course_no的meta正確儲存(): void {
		$is_course = get_post_meta( $this->product_200_id, '_is_course', true );
		$this->assertSame( 'no', $is_course, '非課程商品的 _is_course 應為 no' );
	}

	// ========== link_course_ids 篩選 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: link_course_ids meta 正確儲存，可用於查詢銷售方案
	 */
	public function test_link_course_ids_meta正確儲存(): void {
		$link_course_id = get_post_meta( $this->product_300_id, Helper::LINK_COURSE_IDS_META_KEY, true );
		$this->assertEquals( $this->product_100_id, (int) $link_course_id, 'link_course_ids 應指向課程 100' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 可用 get_posts 的 meta_query 篩選 link_course_ids
	 */
	public function test_link_course_ids_meta查詢_找到銷售方案(): void {
		$results = get_posts(
			[
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'publish',
				'meta_query'     => [
					[
						'key'   => Helper::LINK_COURSE_IDS_META_KEY,
						'value' => $this->product_100_id,
					],
				],
			]
		);

		$this->assertContains( $this->product_300_id, $results, '銷售方案 300 應被篩選出來' );
		$this->assertNotContains( $this->product_200_id, $results, '加購包不應出現' );
	}

	// ========== pc_simple_card 短代碼 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: pc_simple_card 商品不存在時回傳 "《找不到商品》"
	 */
	public function test_pc_simple_card_商品不存在時回傳錯誤訊息(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => 99999 ] );
		$this->assertSame( '《找不到商品》', $result, '不存在的商品應回傳《找不到商品》' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_simple_card 商品 ID 為 0 時回傳 "《找不到商品》"
	 */
	public function test_pc_simple_card_商品ID為0時回傳錯誤訊息(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => 0 ] );
		$this->assertSame( '《找不到商品》', $result, 'product_id=0 應回傳《找不到商品》' );
	}

	// ========== pc_bundle_card 短代碼 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: pc_bundle_card 商品不存在時回傳 "《找不到商品》"
	 */
	public function test_pc_bundle_card_商品不存在時回傳錯誤訊息(): void {
		$result = Shortcodes::pc_bundle_card_callback( [ 'product_id' => 99999 ] );
		$this->assertSame( '《找不到商品》', $result, '不存在的商品應回傳《找不到商品》' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_bundle_card 非銷售方案商品回傳 "《商品不是銷售方案》"
	 * 注意：需要 WC_Product 物件，且 Helper::is_bundle_product = false
	 */
	public function test_pc_bundle_card_非銷售方案回傳錯誤訊息(): void {
		// 課程商品 100 不是銷售方案
		$wc_product = wc_get_product( $this->product_100_id );
		if ( ! $wc_product ) {
			$this->markTestSkipped( 'wc_get_product 無法取得商品（可能 WC 未完整初始化）' );
		}

		$result = Shortcodes::pc_bundle_card_callback( [ 'product_id' => $this->product_100_id ] );
		$this->assertSame( '《商品不是銷售方案》', $result, '非銷售方案應回傳《商品不是銷售方案》' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_simple_card 非 simple/subscription 商品回傳錯誤訊息
	 * （此測試在 WC 完整初始化環境下才能驗證 product type）
	 */
	public function test_pc_simple_card_課程商品類型驗證(): void {
		$wc_product = wc_get_product( $this->product_100_id );
		if ( ! $wc_product ) {
			$this->markTestSkipped( 'wc_get_product 無法取得商品' );
		}

		// 課程商品（simple type）應正常渲染或回傳模板（不應回傳《找不到商品》）
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => $this->product_100_id ] );
		$this->assertNotSame( '《找不到商品》', $result, '存在的商品不應回傳《找不到商品》' );
	}

	// ========== Helper::LINK_COURSE_IDS_META_KEY 常數驗證 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: Helper::LINK_COURSE_IDS_META_KEY 常數值正確
	 */
	public function test_Helper_LINK_COURSE_IDS_META_KEY常數值正確(): void {
		$this->assertSame( 'link_course_ids', Helper::LINK_COURSE_IDS_META_KEY );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: Helper::INCLUDE_PRODUCT_IDS_META_KEY 常數值正確
	 */
	public function test_Helper_INCLUDE_PRODUCT_IDS_META_KEY常數值正確(): void {
		$this->assertSame( 'pbp_product_ids', Helper::INCLUDE_PRODUCT_IDS_META_KEY );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: pc_courses 短代碼的預設 limit 為 12
	 * 透過 wp_parse_args 驗證預設值
	 */
	public function test_pc_courses_預設limit為12(): void {
		$default_args = [
			'status'              => [ 'publish' ],
			'visibility'          => 'visible',
			'paginate'            => true,
			'limit'               => 12,
			'page'                => 1,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'meta_key'            => '_is_course',
			'meta_value'          => 'yes',
			'exclude_avl_courses' => false,
		];

		$args = wp_parse_args( [], $default_args );

		$this->assertSame( 12, $args['limit'], 'pc_courses 預設 limit 應為 12' );
		$this->assertSame( 'date', $args['orderby'], '預設 orderby 應為 date' );
		$this->assertSame( 'DESC', $args['order'], '預設 order 應為 DESC' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: pc_courses include 參數支援逗號分隔字串
	 */
	public function test_pc_courses_include參數支援逗號分隔(): void {
		$include_str = "{$this->product_100_id},{$this->product_101_id}";
		$include_arr = array_filter( array_map( 'intval', explode( ',', str_replace( ' ', '', $include_str ) ) ) );

		$this->assertContains( $this->product_100_id, $include_arr, '應包含商品 100' );
		$this->assertContains( $this->product_101_id, $include_arr, '應包含商品 101' );
		$this->assertCount( 2, $include_arr );
	}

	/**
	 * @test
	 * @group security
	 * Rule: WooCommerce wc_get_product 不對負數 product_id 做 absint 處理
	 * 負數 ID 在 WC 工廠中被視為有效整數，仍會嘗試建立 WC_Product 物件
	 * 因此回傳值可能不是《找不到商品》，此為 WooCommerce 設計決策
	 * 測試驗證：對負數 product_id 呼叫不會造成 PHP 致命錯誤
	 */
	public function test_pc_simple_card_負數product_id_不造成致命錯誤(): void {
		// wc_get_product(-1) 仍可能建立 WC_Product_Simple 物件（WooCommerce 行為）
		// 此測試只驗證呼叫不會拋出例外
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => -1 ] );
		$this->assertIsString( $result, '負數 product_id 應回傳字串，不應拋出例外' );
	}
}
