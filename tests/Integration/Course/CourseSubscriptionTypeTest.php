<?php
/**
 * 課程訂閱商品類型整合測試
 *
 * 對應 POST /courses/{id} 的 `type` 欄位（simple ↔ subscription）。
 *
 * 背景：Issue #235 為了讓「站內 ↔ 外部課程」切換走確認流程，在 separator() 內
 * 對未帶 confirm_type_change 的請求一律 unset( type )，連帶讓課程定價頁的
 * 「課程商品類型」下拉（simple / subscription）失效——選了訂閱也會被寫回 simple，
 * 且 `_subscription_*` meta 被一併刪除。
 *
 * 覆蓋範圍：
 * - 站內子類型（simple / subscription）不需 confirm_type_change 即可切換
 * - 未提供 type 的部分更新必須保留現有 product_type（PATCH 語意，MCP 更新走此路徑）
 * - 新建課程未指定 type 時仍為 simple
 * - 訂閱外掛未安裝時應回明確錯誤，而非 silent 忽略
 * - Admin 商品類型選項在 subscription 類型下仍顯示「課程」勾選框
 *
 * 注意：斷言一律讀 product_type taxonomy term，不用 $product->get_type()。
 * 測試環境沒有 WooCommerce Subscriptions，WC_Product_Subscription class 不存在時
 * wc_get_product() 會 fallback 成 WC_Product_Simple，get_type() 會謊報 'simple'。
 *
 * @group course
 * @group subscription
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;
use J7\PowerCourse\Admin\Product as AdminProduct;

/**
 * Class CourseSubscriptionTypeTest
 */
class CourseSubscriptionTypeTest extends TestCase {

	/** @var CourseApi */
	private CourseApi $api;

	protected function configure_dependencies(): void {
		$this->api = CourseApi::instance();
	}

	public function tear_down(): void {
		\remove_filter( 'power_course_subscription_available', '__return_true' );
		parent::tear_down();
	}

	/**
	 * 模擬 WooCommerce Subscriptions 已安裝
	 *
	 * 不直接定義 WC_Subscription class——那會污染整個 PHPUnit process，
	 * 讓 SubscriptionIntegrationTest 等依賴「class 不存在」的測試全部轉為 skip。
	 */
	private function pretend_subscriptions_installed(): void {
		\add_filter( 'power_course_subscription_available', '__return_true' );
	}

	/**
	 * 讀取 product_type taxonomy term slug
	 *
	 * @param int $course_id 課程 ID
	 * @return string
	 */
	private function get_product_type_slug( int $course_id ): string {
		$terms = \wp_get_object_terms( $course_id, 'product_type', [ 'fields' => 'slugs' ] );
		if ( \is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		return (string) $terms[0];
	}

	/**
	 * 透過 REST API 更新課程
	 *
	 * @param int                  $course_id 課程 ID
	 * @param array<string, mixed> $body 請求 body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_course_via_api( int $course_id, array $body ) {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/courses/' . $course_id );
		$request->set_url_params( [ 'id' => (string) $course_id ] );
		$request->set_body_params( $body );
		return $this->api->post_courses_with_id_callback( $request );
	}

	/**
	 * 建立站內簡單商品課程
	 *
	 * @return int
	 */
	private function create_simple_course(): int {
		$course_id = $this->create_course(
			[
				'post_title' => '訂閱類型測試課程',
				'_is_course' => 'yes',
			]
		);
		\wp_set_object_terms( $course_id, 'simple', 'product_type' );
		\wc_delete_product_transients( $course_id );
		return $course_id;
	}

	/**
	 * 建立訂閱商品課程（taxonomy + 訂閱 meta 都齊備）
	 *
	 * @return int
	 */
	private function create_subscription_course(): int {
		$course_id = $this->create_course(
			[
				'post_title' => '既有訂閱課程',
				'_is_course' => 'yes',
			]
		);
		\wp_set_object_terms( $course_id, 'subscription', 'product_type' );
		\update_post_meta( $course_id, '_subscription_price', '299' );
		\update_post_meta( $course_id, '_subscription_period', 'month' );
		\update_post_meta( $course_id, '_subscription_period_interval', '1' );
		\wc_delete_product_transients( $course_id );
		return $course_id;
	}

	// ========== 站內子類型切換 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_未帶confirm_type_change時type_subscription應生效(): void {
		$this->pretend_subscriptions_installed();
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                          => 'subscription',
				'_subscription_price'           => '299',
				'_subscription_period'          => 'month',
				'_subscription_period_interval' => '1',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '切換為訂閱商品不應失敗' );

		\wc_delete_product_transients( $course_id );
		$this->assertSame(
			'subscription',
			$this->get_product_type_slug( $course_id ),
			'product_type taxonomy 應為 subscription（站內子類型切換不需 confirm_type_change）'
		);
		$this->assertSame(
			'299',
			(string) \get_post_meta( $course_id, '_subscription_price', true ),
			'訂閱價格 meta 應落地'
		);
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_type_simple應把訂閱課程切回簡單商品並清除訂閱meta(): void {
		$course_id = $this->create_subscription_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'          => 'simple',
				'regular_price' => '1000',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '切回簡單商品不應失敗' );

		\wc_delete_product_transients( $course_id );
		$this->assertSame( 'simple', $this->get_product_type_slug( $course_id ), 'product_type 應切回 simple' );
		$this->assertSame(
			'',
			(string) \get_post_meta( $course_id, '_subscription_price', true ),
			'切回簡單商品時訂閱 meta 應被清除'
		);
	}

	// ========== 部分更新（PATCH）語意 ==========

	/**
	 * @test
	 * @group edge
	 *
	 * 未送 type 的更新（MCP CourseUpdateTool 的 schema 沒有 type 欄位，一律走此路徑）
	 * 不得把訂閱課程降級為簡單商品，也不得清掉訂閱 meta。
	 */
	public function test_未送type欄位的更新不得把訂閱課程打回simple(): void {
		$course_id = $this->create_subscription_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'name' => '只改名字',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result, '一般更新不應失敗' );

		\wc_delete_product_transients( $course_id );
		$this->assertSame(
			'subscription',
			$this->get_product_type_slug( $course_id ),
			'未提供 type 時應保留現有 product_type'
		);
		$this->assertSame(
			'299',
			(string) \get_post_meta( $course_id, '_subscription_price', true ),
			'未提供 type 時不應清除訂閱 meta'
		);
		$this->assertSame( '只改名字', \get_post( $course_id )->post_title, 'name 仍應更新' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_新建課程未指定type時為simple(): void {
		\wp_set_current_user( $this->factory()->user->create( [ 'role' => 'administrator' ] ) );
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/courses' );
		$request->set_body_params(
			[
				'name'          => '新建課程',
				'regular_price' => '500',
			]
		);

		$response = $this->api->post_courses_callback( $request );
		$this->assertNotInstanceOf( \WP_Error::class, $response, '新建課程不應失敗' );

		$new_id = (int) $response->get_data()['data']['id'];
		$this->assertGreaterThan( 0, $new_id, '應回傳新課程 ID' );
		$this->assertSame( 'simple', $this->get_product_type_slug( $new_id ), '新建課程預設為 simple' );
	}

	// ========== 訂閱外掛未安裝 ==========

	/**
	 * @test
	 * @group error
	 *
	 * 訂閱外掛未安裝時必須回明確錯誤，而不是 silent 忽略後寫回 simple。
	 */
	public function test_未安裝訂閱外掛時切換為訂閱應回錯誤(): void {
		if ( \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 存在，跳過未安裝情境' );
		}

		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                => 'subscription',
				'_subscription_price' => '299',
			]
		);

		$this->assertInstanceOf( \WP_Error::class, $result, '未安裝訂閱外掛時應回 WP_Error' );
		$this->assertSame( 'subscription_class_not_found', $result->get_error_code() );
	}

	// ========== 真實 WooCommerce Subscriptions 環境 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 前面的 happy path 是用 filter 模擬「訂閱功能可用」，沒有真的 WC_Product_Subscription class。
	 * 這條在真的裝了 WooCommerce Subscriptions 時才跑，驗證切換後 WooCommerce 確實
	 * 把商品實例化為訂閱商品，且後續的一般更新不會把它打回 simple。
	 */
	public function test_真實訂閱外掛環境下切換後應為訂閱商品實例(): void {
		if ( ! \class_exists( 'WC_Product_Subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未安裝，跳過真實環境驗證' );
		}

		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'                          => 'subscription',
				'regular_price'                 => '299',
				'_subscription_price'           => '299',
				'_subscription_period'          => 'month',
				'_subscription_period_interval' => '1',
			]
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result, '切換為訂閱商品不應失敗' );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product_Subscription::class, $product, 'wc_get_product() 應回傳訂閱商品實例' );
		$this->assertSame( 'subscription', $product->get_type(), 'WooCommerce 應認得此商品為訂閱商品' );
		$this->assertSame( '299', (string) \get_post_meta( $course_id, '_subscription_price', true ) );

		// 切換完成後再做一次一般更新（前端其他 tab 的儲存、MCP 更新都是這個形狀）
		$this->update_course_via_api( $course_id, [ 'name' => '訂閱課程改名' ] );
		\wc_delete_product_transients( $course_id );

		$this->assertSame(
			'subscription',
			$this->get_product_type_slug( $course_id ),
			'後續一般更新不得把訂閱課程打回 simple'
		);
		$this->assertSame(
			'299',
			(string) \get_post_meta( $course_id, '_subscription_price', true ),
			'後續一般更新不得清掉訂閱價格'
		);
	}

	/**
	 * @test
	 * @group happy
	 *
	 * 真實環境下的反向切換：訂閱商品切回簡單商品。
	 */
	public function test_真實訂閱外掛環境下可切回簡單商品(): void {
		if ( ! \class_exists( 'WC_Product_Subscription' ) ) {
			$this->markTestSkipped( 'WooCommerce Subscriptions 未安裝，跳過真實環境驗證' );
		}

		$course_id = $this->create_subscription_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type'          => 'simple',
				'regular_price' => '1000',
			]
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		\wc_delete_product_transients( $course_id );
		$product = \wc_get_product( $course_id );
		$this->assertInstanceOf( \WC_Product_Simple::class, $product, '應回到簡單商品實例' );
		$this->assertSame( 'simple', $product->get_type() );
		$this->assertSame(
			'',
			(string) \get_post_meta( $course_id, '_subscription_price', true ),
			'切回簡單商品時訂閱 meta 應被清除'
		);
	}

	// ========== 向下相容護欄 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Issue #235 契約：external 屬於 WC_Product class 切換，仍必須帶 confirm_type_change。
	 */
	public function test_未帶confirm_type_change時type_external仍被忽略(): void {
		$course_id = $this->create_simple_course();

		$result = $this->update_course_via_api(
			$course_id,
			[
				'type' => 'external',
				'name' => '改名後的站內課程',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		\wc_delete_product_transients( $course_id );
		$this->assertSame( 'simple', $this->get_product_type_slug( $course_id ), 'external 切換仍需 confirm_type_change' );
	}

	// ========== Admin 商品類型選項 ==========

	/**
	 * @test
	 * @group happy
	 *
	 * 經典商品編輯器切換到「簡單訂閱」時，「課程」勾選框不可消失
	 * （欄位說明本身就寫著課程商品可搭配簡單訂閱）。
	 */
	public function test_課程勾選框在訂閱商品類型也要顯示(): void {
		$options = AdminProduct::add_product_type_options( [] );

		$this->assertArrayHasKey( AdminProduct::PRODUCT_OPTION_NAME, $options );
		$this->assertStringContainsString(
			'show_if_subscription',
			$options[ AdminProduct::PRODUCT_OPTION_NAME ]['wrapper_class'],
			'訂閱商品類型下也必須顯示「課程」勾選框'
		);
	}
}
