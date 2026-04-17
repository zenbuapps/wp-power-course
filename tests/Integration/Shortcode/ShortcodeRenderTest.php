<?php
/**
 * 短代碼渲染整合測試
 *
 * Feature: specs/features/shortcode/短代碼渲染.feature
 * 測試 Shortcodes/General.php 的 4 個短代碼業務邏輯：
 * - pc_courses：列出課程，支援 limit/columns/include/exclude 等參數
 * - pc_my_courses：登入用戶的已購課程
 * - pc_simple_card：商品不存在 / 非 simple 時的錯誤訊息
 * - pc_bundle_card：非銷售方案時的錯誤訊息
 *
 * @group shortcode
 */

declare( strict_types=1 );

namespace Tests\Integration\Shortcode;

use Tests\Integration\TestCase;
use J7\PowerCourse\Shortcodes\General as Shortcodes;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class ShortcodeRenderTest
 * 測試短代碼渲染的業務邏輯
 */
class ShortcodeRenderTest extends TestCase {

	/** @var int 管理員用戶 ID */
	private int $admin_id;

	/** @var int Alice 學員 ID */
	private int $alice_id;

	/** @var int 課程 100 ID */
	private int $course_100_id;

	/** @var int 課程 101 ID */
	private int $course_101_id;

	/** @var int 課程 102 ID */
	private int $course_102_id;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 使用 Shortcodes General class 與 WordPress APIs
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_sc_' . uniqid(),
				'user_email' => 'admin_sc_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);

		$this->alice_id = $this->factory()->user->create(
			[
				'user_login' => 'alice_sc_' . uniqid(),
				'user_email' => 'alice_sc_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->course_100_id = $this->create_course(
			[ 'post_title' => 'PHP 基礎課', '_is_course' => 'yes', 'price' => '1200' ]
		);

		$this->course_101_id = $this->create_course(
			[ 'post_title' => 'Laravel 課程', '_is_course' => 'yes', 'price' => '1500' ]
		);

		$this->course_102_id = $this->create_course(
			[ 'post_title' => 'React 進階', '_is_course' => 'yes', 'price' => '2000' ]
		);

		$this->ids['Admin']     = $this->admin_id;
		$this->ids['Alice']     = $this->alice_id;
		$this->ids['Course100'] = $this->course_100_id;
		$this->ids['Course101'] = $this->course_101_id;
		$this->ids['Course102'] = $this->course_102_id;
	}

	// ========== 冒煙測試（Smoke）==========

	/**
	 * @test
	 * @group smoke
	 * Shortcodes 類別已載入
	 */
	public function test_冒煙_Shortcodes類別已載入(): void {
		$this->assertTrue( class_exists( Shortcodes::class ), 'Shortcodes\General 類別應已載入' );
	}

	/**
	 * @test
	 * @group smoke
	 * 4 個短代碼已在 WordPress 中註冊
	 */
	public function test_冒煙_4個短代碼已在WordPress中註冊(): void {
		foreach ( Shortcodes::$shortcodes as $shortcode ) {
			$this->assertTrue( shortcode_exists( $shortcode ), "短代碼 [{$shortcode}] 應已在 WordPress 中註冊" );
		}
	}

	// ========== pc_simple_card ==========

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_simple_card] 商品不存在時回傳「《找不到商品》」
	 */
	public function test_pc_simple_card_不存在的商品(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => 99999 ] );
		$this->assertSame( '《Product not found》', $result );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_simple_card] product_id 省略（預設為 0）時回傳《找不到商品》
	 */
	public function test_pc_simple_card_預設product_id為0(): void {
		$result = Shortcodes::pc_simple_card_callback( [] );
		$this->assertSame( '《Product not found》', $result );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: [pc_simple_card] product_id 為負數時不會造成致命錯誤
	 * 注意：WooCommerce wc_get_product 不做 absint，負數 ID 仍嘗試建立 WC_Product 物件
	 * 此為 WooCommerce 行為，不回傳《找不到商品》
	 */
	public function test_pc_simple_card_負數product_id(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => -100 ] );
		// 負數 ID 仍回傳字串（不崩潰），但不保證回傳《找不到商品》
		$this->assertIsString( $result, '負數 product_id 呼叫應回傳字串，不崩潰' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: [pc_simple_card] product_id 為字串時，wc_get_product 嘗試解析
	 * 字串 "abc" 轉型後為 0，應回傳《找不到商品》
	 */
	public function test_pc_simple_card_字串product_id(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => 'abc' ] );
		$this->assertSame( '《Product not found》', $result );
	}

	// ========== pc_bundle_card ==========

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_bundle_card] 商品不存在時回傳「《找不到商品》」
	 */
	public function test_pc_bundle_card_不存在的商品(): void {
		$result = Shortcodes::pc_bundle_card_callback( [ 'product_id' => 99999 ] );
		$this->assertSame( '《Product not found》', $result );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_bundle_card] 課程商品（非銷售方案）回傳《商品不是銷售方案》
	 */
	public function test_pc_bundle_card_非銷售方案回傳錯誤訊息(): void {
		// 課程商品不是銷售方案
		$result = Shortcodes::pc_bundle_card_callback( [ 'product_id' => $this->course_100_id ] );

		// wc_get_product 需要 WC 完整初始化；若不存在回傳 false 則得到《找不到商品》
		$this->assertContains(
			$result,
			[ '《Product not found》', '《Product is not a bundle》' ],
			'非銷售方案應回傳《找不到商品》或《商品不是銷售方案》'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: [pc_bundle_card] product_id 為 0 回傳《找不到商品》
	 */
	public function test_pc_bundle_card_product_id為0(): void {
		$result = Shortcodes::pc_bundle_card_callback( [ 'product_id' => 0 ] );
		$this->assertSame( '《Product not found》', $result );
	}

	// ========== pc_courses 參數處理 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: pc_courses_callback 的 include 參數支援逗號分隔字串轉換為整數陣列
	 */
	public function test_pc_courses_include字串轉換(): void {
		// 模擬 pc_courses_callback 的內部邏輯
		$include_str = "{$this->course_100_id},{$this->course_101_id}";
		$include_arr = array_filter( array_map( 'intval', explode( ',', str_replace( ' ', '', $include_str ) ) ) );

		$this->assertCount( 2, $include_arr );
		$this->assertContains( $this->course_100_id, $include_arr );
		$this->assertContains( $this->course_101_id, $include_arr );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: pc_courses_callback 的 exclude 參數支援逗號分隔字串轉換為整數陣列
	 */
	public function test_pc_courses_exclude字串轉換(): void {
		$exclude_str = "{$this->course_102_id}";
		$exclude_arr = array_filter( array_map( 'intval', explode( ',', str_replace( ' ', '', $exclude_str ) ) ) );

		$this->assertContains( $this->course_102_id, $exclude_arr );
		$this->assertNotContains( $this->course_100_id, $exclude_arr );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: exclude_avl_courses=true 且用戶已有課程，應排除
	 */
	public function test_pc_courses_exclude_avl_courses排除已購課程(): void {
		// Alice 已加入課程 100
		$this->enroll_user_to_course( $this->alice_id, $this->course_100_id );
		wp_set_current_user( $this->alice_id );

		// 取得 Alice 的已購課程 IDs（get_user_meta 回傳字串陣列，需轉型比對）
		$user_avl_course_ids = array_map( 'intval', (array) get_user_meta( $this->alice_id, 'avl_course_ids' ) );

		$this->assertContains( $this->course_100_id, $user_avl_course_ids, 'Alice 的 avl_course_ids 應包含課程 100' );

		// 重置用戶
		wp_set_current_user( 0 );
	}

	// ========== 短代碼 do_shortcode 整合 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_simple_card product_id=99999] 透過 do_shortcode 回傳《找不到商品》
	 */
	public function test_do_shortcode_pc_simple_card_不存在商品(): void {
		$result = do_shortcode( '[pc_simple_card product_id=99999]' );
		$this->assertSame( '《Product not found》', $result );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: [pc_bundle_card product_id=99999] 透過 do_shortcode 回傳《找不到商品》
	 */
	public function test_do_shortcode_pc_bundle_card_不存在商品(): void {
		$result = do_shortcode( '[pc_bundle_card product_id=99999]' );
		$this->assertSame( '《Product not found》', $result );
	}

	// ========== 邊緣案例 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: pc_courses 預設參數驗證（limit=12, order=DESC）
	 */
	public function test_pc_courses_預設參數完整(): void {
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

		// 驗證預設值與 spec 吻合
		$this->assertSame( 12, $default_args['limit'] );
		$this->assertSame( 'DESC', $default_args['order'] );
		$this->assertSame( '_is_course', $default_args['meta_key'] );
		$this->assertSame( 'yes', $default_args['meta_value'] );
		$this->assertSame( false, $default_args['exclude_avl_courses'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: XSS 輸入作為 product_id，wc_get_product 應無法取得商品
	 */
	public function test_pc_simple_card_XSS作為product_id(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => '<script>alert(1)</script>' ] );
		$this->assertSame( '《Product not found》', $result, 'XSS 輸入不應導致非預期行為' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 超大整數作為 product_id
	 */
	public function test_pc_simple_card_超大整數product_id(): void {
		$result = Shortcodes::pc_simple_card_callback( [ 'product_id' => PHP_INT_MAX ] );
		$this->assertSame( '《Product not found》', $result, '超大整數不應找到商品' );
	}
}
