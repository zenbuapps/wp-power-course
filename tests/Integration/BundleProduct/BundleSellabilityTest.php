<?php
/**
 * 銷售方案「可販售狀態」一致性 整合測試（Issue #260、#261、#262）
 *
 * 涵蓋三個互相關聯的 bug：
 * - #260：已發佈方案設定未來「上線時間」不會轉草稿，前台立即露出（排程形同虛設）
 * - #261：方案下線（draft）後，購物車內既有項目仍可完成結帳（傳統 shortcode 結帳）
 * - #262：行動裝置固定 CTA 不隨銷售狀態變化
 *
 * 三者的共同根因是「方案是否可販售」沒有單一真相來源，各處各自用不同判準。
 * 修復後一律以 Helper::is_visible_on_frontend() / is_sellable() 為準。
 *
 * @group bundle
 * @group issue-260
 * @group issue-261
 * @group issue-262
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Product as ProductApi;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\FrontEnd\Purchasable;

/**
 * Class BundleSellabilityTest
 */
class BundleSellabilityTest extends TestCase {

	protected function configure_dependencies(): void {
		// 確保 Purchasable 的購物車 / 結帳把關 hook 已掛載
		Purchasable::instance();
	}

	/**
	 * 建立一個銷售方案（bundle 商品），含合法定價以排除「0 元放行」干擾
	 *
	 * @param string $status    文章狀態
	 * @param int    $online    自動上線時間（0 = 無排程）
	 * @param int    $course_id 歸屬課程 id
	 * @return int bundle id
	 */
	private function create_bundle( string $status = 'publish', int $online = 0, int $course_id = 100 ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '限時方案',
				'post_status' => $status,
				'post_type'   => 'product',
			]
		);
		\wp_set_object_terms( $bundle_id, 'simple', 'product_type' );
		\update_post_meta( $bundle_id, 'bundle_type', 'single_course' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, (string) $course_id );
		\update_post_meta( $bundle_id, '_price', '399' );
		\update_post_meta( $bundle_id, '_regular_price', '399' );
		\update_post_meta( $bundle_id, '_stock_status', 'instock' );
		if ( $online ) {
			\update_post_meta( $bundle_id, Helper::SCHEDULE_ONLINE_META_KEY, $online );
		}
		\clean_post_cache( $bundle_id );
		return $bundle_id;
	}

	// ========== Issue #260：上線排程 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_已發佈且無排程的方案前台應露出(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$product   = \wc_get_product( $bundle_id );

		$this->assertNotFalse( $product );
		$this->assertTrue( Helper::is_visible_on_frontend( $product ), '已發佈且無排程的方案應在前台露出' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_草稿方案前台不應露出(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$product   = \wc_get_product( $bundle_id );

		$this->assertNotFalse( $product );
		$this->assertFalse( Helper::is_visible_on_frontend( $product ), '草稿方案不應在前台露出' );
	}

	/**
	 * Issue #260 的核心：已 publish 但上線時間在未來的髒資料，前台不得露出
	 *
	 * @test
	 * @group edge
	 */
	public function test_上線時間尚未到點的已發佈方案前台不應露出(): void {
		$bundle_id = $this->create_bundle( 'publish', time() + 86400 );
		$product   = \wc_get_product( $bundle_id );

		$this->assertNotFalse( $product );
		$this->assertFalse(
			Helper::is_visible_on_frontend( $product ),
			'上線時間尚未到點的方案不應在前台露出（Issue #260）'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_上線時間已過的已發佈方案前台應露出(): void {
		$bundle_id = $this->create_bundle( 'publish', time() - 600 );
		$product   = \wc_get_product( $bundle_id );

		$this->assertNotFalse( $product );
		$this->assertTrue( Helper::is_visible_on_frontend( $product ), '上線時間已過的方案應在前台露出' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存未來上線時間時已發佈方案應自動轉草稿(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$future    = time() + 86400;
		$meta_data = [ Helper::SCHEDULE_ONLINE_META_KEY => $future ];

		ProductApi::handle_schedule_fields( $meta_data, $product );

		$this->assertSame(
			'draft',
			\get_post_status( $bundle_id ),
			'設定未來上線時間時，已發佈方案應自動轉草稿等待排程（Issue #260）'
		);
		$this->assertSame(
			$future,
			(int) \get_post_meta( $bundle_id, Helper::SCHEDULE_ONLINE_META_KEY, true ),
			'上線時間 meta 應正確寫入'
		);
	}

	/**
	 * 轉草稿是「等待排程」而非「執行下線」，不得污染下線的 done_at 紀錄
	 *
	 * @test
	 * @group edge
	 */
	public function test_轉草稿等待上線不應寫入自動下線紀錄(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$meta_data = [ Helper::SCHEDULE_ONLINE_META_KEY => time() + 86400 ];
		ProductApi::handle_schedule_fields( $meta_data, $product );

		$this->assertSame(
			'',
			(string) \get_post_meta( $bundle_id, Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY, true ),
			'等待上線而轉草稿，不應被記為「已自動下線」'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_儲存未來上線時間時草稿方案維持草稿(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$meta_data = [ Helper::SCHEDULE_ONLINE_META_KEY => time() + 86400 ];
		ProductApi::handle_schedule_fields( $meta_data, $product );

		$this->assertSame( 'draft', \get_post_status( $bundle_id ), '草稿方案應維持草稿' );
	}

	/**
	 * 既有行為（Q3=B）不得被 Issue #260 的修復破壞
	 *
	 * @test
	 * @group edge
	 */
	public function test_儲存過去上線時間的草稿方案應立即發佈(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$meta_data = [ Helper::SCHEDULE_ONLINE_META_KEY => time() - 600 ];
		ProductApi::handle_schedule_fields( $meta_data, $product );

		$this->assertSame( 'publish', \get_post_status( $bundle_id ), '設定過去上線時間應立即發佈（既有行為）' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_清除上線排程不應變更方案狀態(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$meta_data = [ Helper::SCHEDULE_ONLINE_META_KEY => 0 ];
		ProductApi::handle_schedule_fields( $meta_data, $product );

		$this->assertSame( 'publish', \get_post_status( $bundle_id ), '清除排程（0）不應改動方案狀態' );
	}

	// ========== Issue #262：前台可售方案清單 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_前台方案清單只含已發佈且已到上線時間者(): void {
		$course_id = $this->create_course();

		$published = $this->create_bundle( 'publish', 0, $course_id );
		$this->create_bundle( 'draft', 0, $course_id );
		$this->create_bundle( 'publish', time() + 86400, $course_id );

		$visible     = Helper::get_visible_bundle_products( $course_id );
		$visible_ids = array_map( fn( \WC_Product $product ) => $product->get_id(), $visible );

		$this->assertSame( [ $published ], $visible_ids, '前台方案清單應只含已發佈且已到上線時間的方案' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_缺貨方案不算可販售但仍會在前台露出(): void {
		$course_id = $this->create_course();
		$bundle_id = $this->create_bundle( 'publish', 0, $course_id );
		\update_post_meta( $bundle_id, '_stock_status', 'outofstock' );
		\clean_post_cache( $bundle_id );
		\wc_delete_product_transients( $bundle_id );

		$product = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$this->assertTrue( Helper::is_visible_on_frontend( $product ), '缺貨方案仍應顯示（讓買家看得到已售完）' );
		$this->assertFalse( Helper::is_sellable( $product ), '缺貨方案不應算可販售（Issue #262）' );
	}

	// ========== Issue #262：行動裝置固定 CTA 渲染 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_有可售方案時手機CTA錨到方案區塊(): void {
		$course_id = $this->create_course_with_mobile_cta();
		$this->create_bundle( 'publish', 0, $course_id );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'href="#course-pricing"', $html, '有可售方案時 CTA 應錨到方案區塊' );
		$this->assertStringNotContainsString( 'disabled', $html, '有可售方案時 CTA 不應為停用狀態' );
	}

	/**
	 * Issue #262 的核心：方案全數下線時，CTA 不得再是可點的「立即報名」
	 *
	 * @test
	 * @group happy
	 */
	public function test_方案全數下線時手機CTA應為停用狀態(): void {
		$course_id = $this->create_course_with_mobile_cta();
		$this->create_bundle( 'draft', 0, $course_id );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'disabled', $html, '無可售方案時 CTA 應為停用狀態（Issue #262）' );
		$this->assertStringContainsString( 'cursor-not-allowed', $html, '停用的 CTA 應有 not-allowed 游標樣式' );
		$this->assertStringNotContainsString( 'add-to-cart', $html, '無可售方案時不應把課程本體加入購物車' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_方案缺貨時手機CTA應為停用狀態(): void {
		$course_id = $this->create_course_with_mobile_cta();
		$bundle_id = $this->create_bundle( 'publish', 0, $course_id );
		\update_post_meta( $bundle_id, '_stock_status', 'outofstock' );
		\clean_post_cache( $bundle_id );
		\wc_delete_product_transients( $bundle_id );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'disabled', $html, '方案缺貨時 CTA 應為停用狀態（Issue #262）' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_尚未到上線時間的方案手機CTA應為停用狀態(): void {
		$course_id = $this->create_course_with_mobile_cta();
		$this->create_bundle( 'publish', time() + 86400, $course_id );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'disabled', $html, '方案尚未上線時 CTA 應為停用狀態（Issue #260 + #262）' );
	}

	/**
	 * 課程完全沒有方案時，維持既有的「直接加入購物車」行為
	 *
	 * @test
	 * @group happy
	 */
	public function test_課程無方案且本體可購買時手機CTA直接加入購物車(): void {
		$course_id = $this->create_course_with_mobile_cta( '990' );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'add-to-cart', $html, '課程無方案時應直接把課程本體加入購物車' );
		$this->assertStringNotContainsString( 'disabled', $html, '課程本體可購買時 CTA 不應停用' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_課程無方案且本體缺貨時手機CTA應為停用狀態(): void {
		$course_id = $this->create_course_with_mobile_cta( '990' );
		\update_post_meta( $course_id, '_stock_status', 'outofstock' );
		\clean_post_cache( $course_id );
		\wc_delete_product_transients( $course_id );

		$html = $this->render_mobile_cta( $course_id );

		$this->assertStringContainsString( 'disabled', $html, '課程本體缺貨時 CTA 應為停用狀態（Issue #262）' );
		$this->assertStringNotContainsString( 'add-to-cart', $html, '缺貨時不應渲染加入購物車連結' );
	}

	// ========== Issue #261：購物車 / 結帳把關 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_加入購物車驗證會擋下尚未到上線時間的方案(): void {
		$bundle_id = $this->create_bundle( 'publish', time() + 86400 );

		$passed = \apply_filters( 'woocommerce_add_to_cart_validation', true, $bundle_id, 1 );

		$this->assertFalse( (bool) $passed, '尚未到上線時間的方案不應可加入購物車（Issue #260）' );
	}

	/**
	 * Issue #261 的核心：WC 原生 check_cart_item_validity() 只移除 trash 商品，
	 * draft 方案會留在購物車並完成結帳，必須由本外掛主動移除。
	 *
	 * @test
	 * @group happy
	 */
	public function test_下線方案應被移出購物車(): void {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$bundle_id = $this->create_bundle( 'publish' );
		$cart->add_to_cart( $bundle_id, 1 );
		$this->assertCount( 1, $cart->get_cart(), '前置條件：方案應已在購物車內' );

		$this->take_offline( $bundle_id );

		// 以 do_action 觸發而非直接呼叫 handler：同時驗證 hook 確實掛在對的 action 上
		\do_action( 'woocommerce_check_cart_items' );

		$this->assertCount( 0, $cart->get_cart(), '已下線的方案應被移出購物車（Issue #261）' );
	}

	/**
	 * hook 沒掛上的話，上面所有「直接呼叫 handler」的測試都會綠、production 卻完全失效。
	 * 這支測試守住註冊本身。
	 *
	 * @test
	 * @group happy
	 */
	public function test_購物車與結帳把關hook皆已註冊(): void {
		$purchasable = Purchasable::instance();

		$this->assertNotFalse(
			\has_action( 'woocommerce_check_cart_items', [ $purchasable, 'remove_unavailable_bundle_from_cart' ] ),
			'購物車 / 結帳頁的移除把關應掛在 woocommerce_check_cart_items'
		);
		$this->assertNotFalse(
			\has_action( 'woocommerce_after_checkout_validation', [ $purchasable, 'validate_bundle_on_checkout' ] ),
			'送單前的把關應掛在 woocommerce_after_checkout_validation'
		);
		$this->assertNotFalse(
			\has_action( 'woocommerce_store_api_validate_cart_item', [ $purchasable, 'validate_bundle_on_store_api' ] ),
			'區塊結帳的把關應掛在 woocommerce_store_api_validate_cart_item'
		);
		$this->assertNotFalse(
			\has_filter( 'woocommerce_add_to_cart_validation', [ $purchasable, 'block_offline_bundle_add_to_cart' ] ),
			'加入購物車的把關應掛在 woocommerce_add_to_cart_validation'
		);
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_區塊結帳驗證會擋下已下線的方案(): void {
		if ( ! class_exists( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class ) ) {
			$this->markTestSkipped( '此 WooCommerce 版本沒有 Store API RouteException' );
		}

		$bundle_id = $this->create_bundle( 'draft' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );

		Purchasable::instance()->validate_bundle_on_store_api( $product, [] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_區塊結帳驗證不會誤擋仍在販售的方案(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$product   = \wc_get_product( $bundle_id );
		$this->assertNotFalse( $product );

		Purchasable::instance()->validate_bundle_on_store_api( $product, [] );

		$this->assertTrue( true, '仍在販售的方案不應拋出例外' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_仍在販售的方案不應被移出購物車(): void {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$bundle_id = $this->create_bundle( 'publish' );
		$cart->add_to_cart( $bundle_id, 1 );

		\do_action( 'woocommerce_check_cart_items' );

		$this->assertCount( 1, $cart->get_cart(), '仍在販售的方案不應被移除' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_非銷售方案的草稿商品不應被移出購物車(): void {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$product_id = $this->factory()->post->create(
			[
				'post_title'  => '一般商品',
				'post_status' => 'publish',
				'post_type'   => 'product',
			]
		);
		\wp_set_object_terms( $product_id, 'simple', 'product_type' );
		\update_post_meta( $product_id, '_price', '100' );
		\update_post_meta( $product_id, '_regular_price', '100' );
		\update_post_meta( $product_id, '_stock_status', 'instock' );
		\clean_post_cache( $product_id );

		$cart->add_to_cart( $product_id, 1 );
		$this->take_offline( $product_id );

		\do_action( 'woocommerce_check_cart_items' );

		$this->assertCount( 1, $cart->get_cart(), '非銷售方案的商品應維持 WooCommerce 原生行為，不被本功能移除' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_結帳驗證會擋下已下線的方案(): void {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$bundle_id = $this->create_bundle( 'publish' );
		$cart->add_to_cart( $bundle_id, 1 );
		$this->take_offline( $bundle_id );

		$errors = new \WP_Error();
		Purchasable::instance()->validate_bundle_on_checkout( [], $errors );

		$this->assertContains(
			'pc_bundle_unavailable',
			$errors->get_error_codes(),
			'已下線的方案應在送單前被擋下（Issue #261）'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_結帳驗證不會誤擋仍在販售的方案(): void {
		$cart = $this->init_cart();
		if ( ! $cart ) {
			return;
		}

		$bundle_id = $this->create_bundle( 'publish' );
		$cart->add_to_cart( $bundle_id, 1 );

		$errors = new \WP_Error();
		Purchasable::instance()->validate_bundle_on_checkout( [], $errors );

		$this->assertSame( [], $errors->get_error_codes(), '仍在販售的方案不應被擋下' );
	}

	// ========== 測試輔助 ==========

	/**
	 * 建立一門開啟「行動裝置 CTA 固定於底部」的課程
	 *
	 * @param string $price 課程本體售價（'0' = 免費）
	 * @return int 課程 id
	 */
	private function create_course_with_mobile_cta( string $price = '0' ): int {
		$course_id = $this->create_course( [ 'price' => $price ] );
		\update_post_meta( $course_id, 'enable_mobile_fixed_cta', 'yes' );
		\update_post_meta( $course_id, '_stock_status', 'instock' );
		\clean_post_cache( $course_id );
		return $course_id;
	}

	/**
	 * 渲染課程頁 body 模板，並取出行動裝置固定 CTA 那一段 HTML
	 *
	 * CTA 區塊以 `md:hidden tw-fixed bottom-0` 標記，是該模板中唯一的固定底部容器。
	 *
	 * @param int $course_id 課程 id
	 * @return string CTA 區塊 HTML（找不到時回傳空字串）
	 */
	private function render_mobile_cta( int $course_id ): string {
		$product = \wc_get_product( $course_id );
		$this->assertNotFalse( $product, '前置條件：課程商品應可取得' );

		// 模板路徑對應 inc/templates/pages/course-product/body.php；
		// 'course-product' 是 Plugin::$template_page_names 內的 page name（plugin.php:62）
		$html = (string) \J7\PowerCourse\Plugin::load_template(
			'course-product/body',
			[ 'product' => $product ],
			false
		);

		$start = strpos( $html, 'md:hidden tw-fixed bottom-0' );
		$this->assertNotFalse( $start, '應渲染出行動裝置固定 CTA 區塊（enable_mobile_fixed_cta = yes）' );

		return substr( $html, $start );
	}

	/**
	 * 初始化 WooCommerce 購物車；環境不支援時 skip
	 *
	 * @return \WC_Cart|null
	 */
	private function init_cart(): ?\WC_Cart {
		if ( ! \function_exists( 'WC' ) ) {
			$this->markTestSkipped( 'WooCommerce 未載入' );
		}

		if ( null === \WC()->session ) {
			\WC()->initialize_session();
		}
		if ( null === \WC()->cart ) {
			\WC()->initialize_cart();
		}

		$cart = \WC()->cart;
		if ( ! ( $cart instanceof \WC_Cart ) ) {
			$this->markTestSkipped( '測試環境無法初始化 WooCommerce 購物車' );
		}

		$cart->empty_cart();
		return $cart;
	}

	/**
	 * 將商品下線，並讓購物車項目取得新鮮的商品物件
	 *
	 * 購物車項目的 `data` 是加入當下建立的 WC_Product 快照。真實情境中
	 * 「使用者帶著舊購物車回訪」是一個新的 request，`WC_Cart_Session::get_cart_from_session()`
	 * 會對每個項目重新 `wc_get_product()`（class-wc-cart-session.php:144）。
	 * 測試環境沒有跨 request 的 session，故在此以同一份重建邏輯模擬。
	 *
	 * @param int $product_id 商品 id
	 * @return void
	 */
	private function take_offline( int $product_id ): void {
		\wp_update_post(
			[
				'ID'          => $product_id,
				'post_status' => 'draft',
			]
		);
		\clean_post_cache( $product_id );
		\wc_delete_product_transients( $product_id );

		$this->assertSame( 'draft', \get_post_status( $product_id ), '前置條件：商品應已轉為草稿' );

		$cart     = \WC()->cart;
		$contents = $cart->get_cart_contents();
		foreach ( $contents as $key => $item ) {
			$fresh = \wc_get_product( (int) $item['product_id'] );
			if ( $fresh instanceof \WC_Product ) {
				$contents[ $key ]['data'] = $fresh;
			}
		}
		$cart->set_cart_contents( $contents );
	}
}
