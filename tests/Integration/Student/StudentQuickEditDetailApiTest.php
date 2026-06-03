<?php
/**
 * 學員快速編輯 — Detail 擴充 / Comments / Options API 整合測試
 *
 * 對應實作計劃：specs/student-quick-edit/IMPLEMENTATION_PLAN.md（Phase 1 + Phase 2）。
 *
 * 涵蓋：
 * - GET  users/{id}        — 鏡像 TUserBaseRecord 的新頂層欄位（統計 / billing / shipping / cart / recent_orders / contact_remarks / other_meta_data）
 * - POST users/{id}        — role 自我降權守門、user_birthday 格式驗證
 * - GET  users/options     — 角色清單 {data:{roles:[{value,label}]}}
 * - GET  comments          — 列出 contact_remark
 * - POST comments          — 新增 contact_remark（含空 note / 不存在 user 驗證）
 * - DELETE comments/{id}   — 刪除 contact_remark（含非 contact_remark 拒刪 403）
 *
 * @group student
 * @group user
 * @group api
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\User as UserApi;

/**
 * Class StudentQuickEditDetailApiTest
 */
class StudentQuickEditDetailApiTest extends TestCase {

	/** @var int 學員 Wang 的用戶 ID */
	private int $wang_id;

	/** @var int 管理員 ID（具 edit_users 能力） */
	private int $admin_id;

	/** @var UserApi API instance */
	private UserApi $api;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		$this->api = UserApi::instance();
	}

	/**
	 * 每個測試前建立測試資料
	 */
	public function set_up(): void {
		parent::set_up();

		$this->wang_id = $this->factory()->user->create(
			[
				'user_login'   => 'wang_' . uniqid(),
				'user_email'   => 'wang_' . uniqid() . '@test.com',
				'display_name' => '王小明',
				'first_name'   => '小明',
				'last_name'    => '王',
				'role'         => 'customer',
			]
		);

		// billing / shipping meta
		update_user_meta( $this->wang_id, 'billing_first_name', '小明' );
		update_user_meta( $this->wang_id, 'billing_last_name', '王' );
		update_user_meta( $this->wang_id, 'billing_email', 'billing_wang@test.com' );
		update_user_meta( $this->wang_id, 'billing_phone', '0912345678' );
		update_user_meta( $this->wang_id, 'billing_company', '測試公司' );
		update_user_meta( $this->wang_id, 'billing_address_1', '測試路 100 號' );
		update_user_meta( $this->wang_id, 'billing_city', '台北市' );
		update_user_meta( $this->wang_id, 'billing_postcode', '100' );
		update_user_meta( $this->wang_id, 'billing_country', 'TW' );
		update_user_meta( $this->wang_id, 'billing_state', 'TPE' );
		update_user_meta( $this->wang_id, 'shipping_first_name', '小明' );
		update_user_meta( $this->wang_id, 'shipping_address_1', '收件路 200 號' );
		update_user_meta( $this->wang_id, 'shipping_city', '新北市' );
		update_user_meta( $this->wang_id, 'shipping_country', 'TW' );

		// 以管理員身分登入（edit_users 能力）
		$this->admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * 建立 GET users/{id} 的 request
	 *
	 * @param int $user_id User ID.
	 * @return \WP_REST_Request
	 */
	private function make_get_request( int $user_id ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/power-course/users/' . $user_id );
		$request->set_url_params( [ 'id' => (string) $user_id ] );
		return $request;
	}

	// ========== GET users/{id}：新頂層欄位 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: GET users/{id} 回傳鏡像 TUserBaseRecord 的全部新頂層欄位（契約鐵則：欄位名一字不差）
	 */
	public function test_get_user_returns_all_new_top_level_fields(): void {
		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );

		$expected_keys = [
			'total_spend',
			'orders_count',
			'avg_order_value',
			'date_last_active',
			'date_last_order',
			'user_registered',
			'user_registered_human',
			'user_avatar_url',
			'user_birthday',
			'description',
			'role',
			'edit_url',
			'billing',
			'shipping',
			'cart',
			'recent_orders',
			'other_meta_data',
			'contact_remarks',
		];
		foreach ( $expected_keys as $key ) {
			$this->assertArrayHasKey( $key, $data, "回傳資料應含頂層欄位 {$key}" );
		}

		// 既有欄位仍保留（向下相容 issue/229）
		$this->assertArrayHasKey( 'meta_data', $data, '既有 meta_data 欄位應保留' );
		$this->assertArrayHasKey( 'display_name', $data );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 統計欄位型別正確；無訂單時 total_spend=0 / orders_count=0 / avg_order_value=0
	 */
	public function test_get_user_stats_default_to_zero_when_no_orders(): void {
		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$this->assertIsFloat( $data['total_spend'], 'total_spend 應為 float' );
		$this->assertIsInt( $data['orders_count'], 'orders_count 應為 int' );
		$this->assertSame( 0, $data['orders_count'], '無訂單時 orders_count 應為 0' );
		$this->assertEquals( 0, $data['avg_order_value'], '無訂單時 avg_order_value 應為 0' );
		$this->assertSame( 'customer', $data['role'], 'role 應為 customer' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: billing 物件含完整欄位（含 email/phone/company）；shipping 物件不含 email
	 */
	public function test_get_user_returns_billing_and_shipping_objects(): void {
		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$billing = $data['billing'];
		$this->assertIsArray( $billing, 'billing 應為物件（陣列）' );
		$this->assertSame( '小明', $billing['first_name'] );
		$this->assertSame( '0912345678', $billing['phone'] );
		$this->assertSame( '測試公司', $billing['company'] );
		$this->assertSame( 'billing_wang@test.com', $billing['email'], 'billing 應含 email' );
		$this->assertArrayHasKey( 'address_1', $billing );

		$shipping = $data['shipping'];
		$this->assertIsArray( $shipping, 'shipping 應為物件（陣列）' );
		$this->assertSame( '收件路 200 號', $shipping['address_1'] );
		$this->assertArrayNotHasKey( 'email', $shipping, 'shipping 不應含 email' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: persistent cart meta 不存在 → cart 為空陣列 []
	 */
	public function test_get_user_cart_is_empty_array_when_no_persistent_cart_meta(): void {
		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$this->assertIsArray( $data['cart'], 'cart 應為陣列' );
		$this->assertCount( 0, $data['cart'], '無 persistent cart meta 時 cart 應為空陣列' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: persistent cart meta 存在時，cart item 形狀為 {product_id,product_name,quantity,price,line_total,product_image}
	 */
	public function test_get_user_cart_returns_shaped_items_from_persistent_cart(): void {
		// 建立一個商品
		$product = new \WC_Product_Simple();
		$product->set_name( '購物車商品' );
		$product->set_regular_price( '199' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		// 寫入 persistent cart meta（動態 blog_id key）
		$meta_key = '_woocommerce_persistent_cart_' . get_current_blog_id();
		update_user_meta(
			$this->wang_id,
			$meta_key,
			[
				'cart' => [
					'abc123' => [
						'product_id' => $product_id,
						'quantity'   => 2,
						'line_total' => 398.0,
					],
				],
			]
		);

		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$this->assertCount( 1, $data['cart'], 'cart 應含 1 筆' );
		$item = $data['cart'][0];
		foreach ( [ 'product_id', 'product_name', 'quantity', 'price', 'line_total', 'product_image' ] as $key ) {
			$this->assertArrayHasKey( $key, $item, "cart item 應含 {$key}" );
		}
		$this->assertSame( $product_id, $item['product_id'] );
		$this->assertSame( '購物車商品', $item['product_name'] );
		$this->assertSame( 2, $item['quantity'] );
		$this->assertSame( 398.0, $item['line_total'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: other_meta_data 過濾敏感 key（session_tokens / *_capabilities / persistent cart）
	 */
	public function test_get_user_other_meta_data_filters_sensitive_keys(): void {
		// 加入一個一般 meta 與一個 persistent cart meta
		update_user_meta( $this->wang_id, 'custom_field', 'visible_value' );
		update_user_meta( $this->wang_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), [ 'cart' => [] ] );

		$response = $this->api->get_users_with_id_callback( $this->make_get_request( $this->wang_id ) );
		$data     = $response->get_data();

		$meta_keys = array_column( $data['other_meta_data'], 'meta_key' );

		$this->assertContains( 'custom_field', $meta_keys, '一般 meta 應出現在 other_meta_data' );
		$this->assertNotContains( 'session_tokens', $meta_keys, 'session_tokens 應被過濾' );
		$this->assertNotContains( $this->wang_id . '_capabilities', $meta_keys );
		foreach ( $meta_keys as $key ) {
			$this->assertStringStartsNotWith( '_woocommerce_persistent_cart_', (string) $key, 'persistent cart meta 應被過濾' );
			$this->assertStringEndsNotWith( '_capabilities', (string) $key, 'capabilities meta 應被過濾' );
		}
	}

	// ========== POST users/{id}：role 守門 / user_birthday 驗證 ==========

	/**
	 * @test
	 * @group security
	 * Rule: 防自我降權 — 更新「自己」的 role 時應被忽略（避免管理員鎖死）
	 */
	public function test_post_user_ignores_self_role_change(): void {
		// 以管理員身分更新「自己」的 role 為 subscriber
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->admin_id );
		$request->set_url_params( [ 'id' => (string) $this->admin_id ] );
		$request->set_body_params( [ 'role' => 'subscriber' ] );

		$this->api->post_users_with_id_callback( $request );

		$admin = get_userdata( $this->admin_id );
		$this->assertNotFalse( $admin );
		$this->assertContains( 'administrator', $admin->roles, '更新自己 role 應被忽略，administrator 角色不變' );
		$this->assertNotContains( 'subscriber', $admin->roles, '不應被降權為 subscriber' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 更新「他人」的合法 role → 成功套用
	 */
	public function test_post_user_applies_valid_role_for_other_user(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params( [ 'role' => 'subscriber' ] );

		$this->api->post_users_with_id_callback( $request );

		$wang = get_userdata( $this->wang_id );
		$this->assertNotFalse( $wang );
		$this->assertContains( 'subscriber', $wang->roles, '他人合法 role 應套用' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 不存在的 role → 靜默忽略（角色不變）
	 */
	public function test_post_user_ignores_invalid_role(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params( [ 'role' => 'nonexistent_role_xyz' ] );

		$this->api->post_users_with_id_callback( $request );

		$wang = get_userdata( $this->wang_id );
		$this->assertNotFalse( $wang );
		$this->assertContains( 'customer', $wang->roles, '非法 role 應被忽略，原角色不變' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: user_birthday 格式為 YYYY-MM-DD → 寫入 meta
	 */
	public function test_post_user_saves_valid_birthday(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params( [ 'user_birthday' => '1990-05-20' ] );

		$this->api->post_users_with_id_callback( $request );

		$this->assertSame( '1990-05-20', get_user_meta( $this->wang_id, 'user_birthday', true ), '合法生日應寫入' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: user_birthday 格式非法 → 不寫入該 meta
	 */
	public function test_post_user_rejects_invalid_birthday_format(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params( [ 'user_birthday' => '20/05/1990' ] );

		$this->api->post_users_with_id_callback( $request );

		$this->assertSame( '', get_user_meta( $this->wang_id, 'user_birthday', true ), '非法生日格式不應寫入' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: description 可透過 POST 寫入（user table 欄位）
	 */
	public function test_post_user_saves_description(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params( [ 'description' => '這是一段簡介' ] );

		$this->api->post_users_with_id_callback( $request );

		$this->assertSame( '這是一段簡介', get_user_meta( $this->wang_id, 'description', true ), 'description 應已寫入' );
	}

	// ========== GET users/options ==========

	/**
	 * @test
	 * @group happy
	 * Rule: GET users/options 回傳 {data:{roles:[{value,label}]}}
	 */
	public function test_get_users_options_returns_roles(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-course/users/options' );
		$response = $this->api->get_users_options_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'data', $data );
		$this->assertArrayHasKey( 'roles', $data['data'] );
		$this->assertIsArray( $data['data']['roles'] );
		$this->assertNotEmpty( $data['data']['roles'] );

		$first = $data['data']['roles'][0];
		$this->assertArrayHasKey( 'value', $first, '每個 role 應含 value' );
		$this->assertArrayHasKey( 'label', $first, '每個 role 應含 label' );

		$values = array_column( $data['data']['roles'], 'value' );
		$this->assertContains( 'administrator', $values, 'roles 應含 administrator' );
	}

	// ========== Comments CRUD ==========

	/**
	 * 建立一筆 contact_remark
	 *
	 * @param int    $commented_user_id 被留言對象.
	 * @param string $note              內容.
	 * @param bool   $is_customer_note  是否為客戶可見備註.
	 * @return \WP_REST_Response
	 */
	private function create_remark( int $commented_user_id, string $note = '內部備註', bool $is_customer_note = false ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-course/comments' );
		$request->set_body_params(
			[
				'comment_type'      => 'contact_remark',
				'commented_user_id' => $commented_user_id,
				'note'              => $note,
				'is_customer_note'  => $is_customer_note,
			]
		);
		return $this->api->post_comments_callback( $request );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: comments create → list → delete 一輪正常
	 */
	public function test_comments_create_list_delete_cycle(): void {
		// --- create ---
		$create_response = $this->create_remark( $this->wang_id, '這位學員很積極' );
		$create_data     = $create_response->get_data();

		$this->assertSame( 200, $create_response->get_status() );
		$this->assertArrayHasKey( 'data', $create_data, 'create 應回 Refine 格式 {data:{id}}' );
		$this->assertArrayHasKey( 'id', $create_data['data'] );
		$comment_id = (int) $create_data['data']['id'];
		$this->assertGreaterThan( 0, $comment_id );

		// --- list ---
		$list_request = new \WP_REST_Request( 'GET', '/power-course/comments' );
		$list_request->set_query_params( [ 'commented_user_id' => $this->wang_id ] );
		$list_response = $this->api->get_comments_callback( $list_request );
		$list_data     = $list_response->get_data();

		$this->assertSame( 200, $list_response->get_status() );
		$this->assertCount( 1, $list_data, 'list 應回 1 筆' );

		$remark = $list_data[0];
		foreach ( [ 'id', 'content', 'date_created', 'customer_note', 'added_by', 'user_id', 'commented_user_id' ] as $key ) {
			$this->assertArrayHasKey( $key, $remark, "contact_remark 應含 {$key}" );
		}
		$this->assertSame( '這位學員很積極', $remark['content'] );
		$this->assertSame( $this->wang_id, $remark['commented_user_id'] );
		$this->assertSame( $this->admin_id, $remark['user_id'], 'user_id 應為當前登入者' );
		$this->assertFalse( $remark['customer_note'] );

		// --- delete ---
		$delete_request = new \WP_REST_Request( 'DELETE', '/power-course/comments/' . $comment_id );
		$delete_request->set_url_params( [ 'id' => (string) $comment_id ] );
		$delete_response = $this->api->delete_comments_with_id_callback( $delete_request );
		$delete_data     = $delete_response->get_data();

		$this->assertSame( 200, $delete_response->get_status() );
		$this->assertSame( $comment_id, $delete_data['data']['id'], 'delete 應回 Refine 格式 {data:{id}}' );

		// 確認已刪除
		$this->assertNull( get_comment( $comment_id ), 'comment 應已被刪除' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: note 空白 → 400 empty_note
	 */
	public function test_post_comment_rejects_empty_note(): void {
		$response = $this->create_remark( $this->wang_id, '   ' );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'empty_note', $data['code'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: commented_user_id 對應 user 不存在 → 400 invalid_user
	 */
	public function test_post_comment_rejects_invalid_user(): void {
		$response = $this->create_remark( 999999999, '對不存在的 user 留言' );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_user', $data['code'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: is_customer_note 為 true 時，list 回傳 customer_note=true
	 */
	public function test_post_comment_persists_customer_note_flag(): void {
		$this->create_remark( $this->wang_id, '客戶可見備註', true );

		$list_request = new \WP_REST_Request( 'GET', '/power-course/comments' );
		$list_request->set_query_params( [ 'commented_user_id' => $this->wang_id ] );
		$list_data = $this->api->get_comments_callback( $list_request )->get_data();

		$this->assertCount( 1, $list_data );
		$this->assertTrue( $list_data[0]['customer_note'], 'customer_note 應為 true' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: DELETE 非 contact_remark 的留言 → 403 forbidden_comment_type（防誤刪一般留言）
	 */
	public function test_delete_rejects_non_contact_remark_comment(): void {
		// 建立一筆一般留言（comment_type 預設 '' / 'comment'）
		$normal_comment_id = wp_insert_comment(
			[
				'comment_post_ID' => 0,
				'comment_content' => '一般留言',
				'comment_approved' => 1,
			]
		);
		$this->assertNotFalse( $normal_comment_id );

		$delete_request = new \WP_REST_Request( 'DELETE', '/power-course/comments/' . $normal_comment_id );
		$delete_request->set_url_params( [ 'id' => (string) $normal_comment_id ] );
		$response = $this->api->delete_comments_with_id_callback( $delete_request );
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status(), '非 contact_remark 應拒刪 403' );
		$this->assertSame( 'forbidden_comment_type', $data['code'] );

		// 一般留言不應被刪除
		$this->assertNotNull( get_comment( (int) $normal_comment_id ), '一般留言不應被刪除' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: DELETE 不存在的 comment → 404 comment_not_found
	 */
	public function test_delete_returns_404_for_nonexistent_comment(): void {
		$delete_request = new \WP_REST_Request( 'DELETE', '/power-course/comments/999999999' );
		$delete_request->set_url_params( [ 'id' => '999999999' ] );
		$response = $this->api->delete_comments_with_id_callback( $delete_request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'comment_not_found', $data['code'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: GET comments 不帶 commented_user_id → 回空陣列
	 */
	public function test_get_comments_returns_empty_without_user_id(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-course/comments' );
		$response = $this->api->get_comments_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $response->get_data() );
	}

	// ========== orders-summary 向下相容：recent[] 補 order_items[] ==========

	/**
	 * @test
	 * @group happy
	 * Rule: orders-summary 的 recent[] 每筆補上 order_items[]（向下相容，既有欄位不刪）
	 */
	public function test_orders_summary_recent_includes_order_items(): void {
		// 建立一個含商品的訂單
		$product = new \WC_Product_Simple();
		$product->set_name( '訂單商品' );
		$product->set_regular_price( '300' );
		$product->set_status( 'publish' );
		$product_id = $product->save();

		$order = wc_create_order();
		$order->set_customer_id( $this->wang_id );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->set_status( 'completed' );
		$order->calculate_totals();
		$order->save();

		$request = new \WP_REST_Request( 'GET', '/power-course/users/' . $this->wang_id . '/orders-summary' );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_query_params( [ 'limit' => 5 ] );

		$data = $this->api->get_users_with_id_orders_summary_callback( $request )->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertCount( 1, $data['recent'] );

		$recent = $data['recent'][0];
		// 既有欄位仍存在
		$this->assertArrayHasKey( 'id', $recent );
		$this->assertArrayHasKey( 'number', $recent );
		$this->assertArrayHasKey( 'status', $recent );
		$this->assertArrayHasKey( 'total', $recent );
		// 新增 order_items[]
		$this->assertArrayHasKey( 'order_items', $recent, 'recent 每筆應補 order_items' );
		$this->assertCount( 1, $recent['order_items'] );
		foreach ( [ 'product_id', 'product_name', 'quantity', 'price', 'line_total', 'product_image' ] as $key ) {
			$this->assertArrayHasKey( $key, $recent['order_items'][0], "order item 應含 {$key}" );
		}
		$this->assertSame( $product_id, $recent['order_items'][0]['product_id'] );
	}
}
