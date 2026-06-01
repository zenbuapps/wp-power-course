<?php
/**
 * 學員快速編輯 API 整合測試（TDD Red Phase）
 *
 * Feature: specs/student-quick-edit/student-quick-edit.feature
 * API Spec: specs/student-quick-edit/api.yml
 *
 * 測試 J7\PowerCourse\Api\User 的新端點（目前尚未實作，全部測試預期失敗）：
 * - get_users_with_id_callback           — GET  users/{id}              — 取得學員完整資料
 * - post_users_with_id_callback          — POST users/{id}              — 更新學員資料（密碼留空不變）
 * - post_users_with_id_reset_password_callback — POST users/{id}/reset-password — 發送密碼重設信
 * - get_users_with_id_orders_summary_callback  — GET  users/{id}/orders-summary — 取得訂單摘要
 * - check_edit_users_permission          — 新權限守門靜態方法（edit_users）
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
 * Class StudentQuickEditApiTest
 *
 * 測試「學員快速編輯」功能所需的所有後端 API 端點。
 * 這些端點在 inc/classes/Api/User.php 中尚未實作（Red Phase）。
 */
class StudentQuickEditApiTest extends TestCase {

	/** @var int 學員 Wang 的用戶 ID */
	private int $wang_id;

	/** @var string 學員 Wang 初始密碼的原始雜湊（用於驗證密碼未被修改） */
	private string $wang_initial_pass_hash;

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

		// 建立學員王小明（附 billing_* 與 shipping_* meta）
		$this->wang_id = $this->factory()->user->create(
			[
				'user_login'   => 'wang_' . uniqid(),
				'user_email'   => 'wang_' . uniqid() . '@test.com',
				'user_pass'    => 'initial_secure_pass_123',
				'display_name' => '王小明',
				'first_name'   => '小明',
				'last_name'    => '王',
				'role'         => 'customer',
			]
		);

		// 記錄初始密碼雜湊，用於斷言密碼未被改變
		$wang_data                    = get_userdata( $this->wang_id );
		$this->wang_initial_pass_hash = $wang_data ? $wang_data->user_pass : '';

		// 設定 WC billing 與 shipping meta
		update_user_meta( $this->wang_id, 'billing_first_name', '小明' );
		update_user_meta( $this->wang_id, 'billing_last_name', '王' );
		update_user_meta( $this->wang_id, 'billing_email', 'billing_wang@test.com' );
		update_user_meta( $this->wang_id, 'billing_phone', '0912345678' );
		update_user_meta( $this->wang_id, 'billing_address_1', '測試路 100 號' );
		update_user_meta( $this->wang_id, 'billing_city', '台北市' );
		update_user_meta( $this->wang_id, 'billing_postcode', '100' );
		update_user_meta( $this->wang_id, 'billing_country', 'TW' );
		update_user_meta( $this->wang_id, 'billing_state', 'TPE' );
		update_user_meta( $this->wang_id, 'shipping_first_name', '小明' );
		update_user_meta( $this->wang_id, 'shipping_last_name', '王' );
		update_user_meta( $this->wang_id, 'shipping_address_1', '收件路 200 號' );
		update_user_meta( $this->wang_id, 'shipping_city', '新北市' );
		update_user_meta( $this->wang_id, 'shipping_country', 'TW' );

		// 以管理員身分登入（edit_users 能力）
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );
	}

	// ========== 權限守門：check_edit_users_permission ==========

	/**
	 * @test
	 * @group security
	 * Rule: 未登入使用者呼叫需 edit_users 的端點 → WP_Error 401 rest_not_logged_in
	 */
	public function test_check_edit_users_permission_denies_logged_out_user(): void {
		// 未登入狀態
		wp_set_current_user( 0 );

		$result = UserApi::check_edit_users_permission();

		$this->assertInstanceOf( \WP_Error::class, $result, '未登入應回傳 WP_Error' );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code(), 'error code 應為 rest_not_logged_in' );
		$error_data = $result->get_error_data();
		$this->assertSame( 401, $error_data['status'], 'HTTP status 應為 401' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 訂閱者（無 edit_users 能力）→ WP_Error 403 rest_forbidden
	 */
	public function test_check_edit_users_permission_denies_subscriber(): void {
		$subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = UserApi::check_edit_users_permission();

		$this->assertInstanceOf( \WP_Error::class, $result, '訂閱者應回傳 WP_Error' );
		$this->assertSame( 'rest_forbidden', $result->get_error_code(), 'error code 應為 rest_forbidden' );
		$error_data = $result->get_error_data();
		$this->assertSame( 403, $error_data['status'], 'HTTP status 應為 403' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: administrator（有 edit_users 能力）→ 回傳 true
	 */
	public function test_check_edit_users_permission_grants_administrator(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$result = UserApi::check_edit_users_permission();

		$this->assertTrue( $result, 'administrator 應通過權限檢查（回傳 true）' );
	}

	// ========== GET users/{id}：載入學員完整資料 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: GET users/{id} 回傳包含 id / user_login / display_name / email / meta_data，
	 *       meta_data 中包含 billing_* 與 shipping_* 欄位
	 */
	public function test_get_user_returns_basic_info_and_wc_meta(): void {
		$request = new \WP_REST_Request( 'GET', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );

		$response = $this->api->get_users_with_id_callback( $request );
		$data     = $response->get_data();

		// 確認基本欄位存在
		$this->assertSame( 200, $response->get_status(), 'GET 存在的學員應回 200' );
		$this->assertArrayHasKey( 'id', $data, '回傳資料應含 id' );
		$this->assertArrayHasKey( 'user_login', $data, '回傳資料應含 user_login' );
		$this->assertArrayHasKey( 'display_name', $data, '回傳資料應含 display_name' );
		$this->assertArrayHasKey( 'email', $data, '回傳資料應含 email' );
		$this->assertArrayHasKey( 'meta_data', $data, '回傳資料應含 meta_data' );

		// 確認 meta_data 包含 WC billing/shipping 欄位
		$meta = $data['meta_data'];
		$this->assertIsArray( $meta, 'meta_data 應為陣列' );
		$this->assertArrayHasKey( 'billing_phone', $meta, 'meta_data 應含 billing_phone' );
		$this->assertArrayHasKey( 'billing_address_1', $meta, 'meta_data 應含 billing_address_1' );
		$this->assertArrayHasKey( 'billing_city', $meta, 'meta_data 應含 billing_city' );
		$this->assertArrayHasKey( 'billing_country', $meta, 'meta_data 應含 billing_country' );
		$this->assertArrayHasKey( 'shipping_address_1', $meta, 'meta_data 應含 shipping_address_1' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: GET users/{id} 的回傳資料不得包含敏感欄位 user_pass 與 session_tokens
	 */
	public function test_get_user_excludes_sensitive_fields(): void {
		$request = new \WP_REST_Request( 'GET', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );

		$response = $this->api->get_users_with_id_callback( $request );
		$data     = $response->get_data();

		// 敏感欄位不得出現在任何層級
		$this->assertArrayNotHasKey( 'user_pass', $data, '回傳資料不得包含 user_pass' );
		$this->assertArrayNotHasKey( 'session_tokens', $data, '回傳資料不得包含 session_tokens' );

		if ( isset( $data['meta_data'] ) && is_array( $data['meta_data'] ) ) {
			$this->assertArrayNotHasKey( 'user_pass', $data['meta_data'], 'meta_data 不得包含 user_pass' );
			$this->assertArrayNotHasKey( 'session_tokens', $data['meta_data'], 'meta_data 不得包含 session_tokens' );
		}
	}

	/**
	 * @test
	 * @group error
	 * Rule: GET users/{id} 找不到用戶 → 404 user_not_found
	 */
	public function test_get_user_returns_404_for_nonexistent_user(): void {
		$nonexistent_id = 999999999;
		$request        = new \WP_REST_Request( 'GET', '/power-course/v2/users/' . $nonexistent_id );
		$request->set_url_params( [ 'id' => (string) $nonexistent_id ] );

		$response = $this->api->get_users_with_id_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 404, $response->get_status(), '找不到用戶應回 404' );
		$this->assertSame( 'user_not_found', $data['code'], 'error code 應為 user_not_found' );
	}

	// ========== POST users/{id}：更新基本資料 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: POST users/{id} 更新 display_name / user_email / first_name / last_name → 持久化成功
	 */
	public function test_post_user_updates_basic_info(): void {
		$new_email = 'new_wang_' . uniqid() . '@test.com';

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				'display_name' => '王大明',
				'user_email'   => $new_email,
				'first_name'   => '大明',
				'last_name'    => '王',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '更新基本資料應回 200' );

		// 驗證持久化
		$updated_user = get_user_by( 'id', $this->wang_id );
		$this->assertNotFalse( $updated_user, '用戶應存在' );
		$this->assertSame( '王大明', $updated_user->display_name, 'display_name 應已更新' );
		$this->assertSame( $new_email, $updated_user->user_email, 'user_email 應已更新' );
		$this->assertSame( '大明', get_user_meta( $this->wang_id, 'first_name', true ), 'first_name 應已更新' );
		$this->assertSame( '王', get_user_meta( $this->wang_id, 'last_name', true ), 'last_name 應已更新' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: Q8=A — 只更新 user_email 不應連動改變 billing_email（兩個欄位獨立）
	 */
	public function test_post_user_email_does_not_update_billing_email(): void {
		// 確認初始 billing_email
		$original_billing_email = get_user_meta( $this->wang_id, 'billing_email', true );
		$new_login_email        = 'updated_login_' . uniqid() . '@test.com';

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				'user_email' => $new_login_email,
			]
		);

		$this->api->post_users_with_id_callback( $request );

		// billing_email 應維持原值
		$current_billing_email = get_user_meta( $this->wang_id, 'billing_email', true );
		$this->assertSame(
			$original_billing_email,
			$current_billing_email,
			'只更新登入 Email 時，billing_email 應維持不變（兩欄位獨立）'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 更新 billing_phone / billing_address_1 / billing_city / billing_postcode /
	 *       billing_country / billing_state 及部分 shipping_* → 對應 user meta 已更新
	 */
	public function test_post_user_updates_wc_billing_and_shipping_meta(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				'billing_phone'      => '0987654321',
				'billing_address_1'  => '新測試路 500 號',
				'billing_city'       => '高雄市',
				'billing_postcode'   => '800',
				'billing_country'    => 'TW',
				'billing_state'      => 'KHH',
				'shipping_address_1' => '收件新路 999 號',
				'shipping_city'      => '桃園市',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '更新 WC meta 應回 200' );

		// 驗證 billing meta
		$this->assertSame( '0987654321', get_user_meta( $this->wang_id, 'billing_phone', true ), 'billing_phone 應已更新' );
		$this->assertSame( '新測試路 500 號', get_user_meta( $this->wang_id, 'billing_address_1', true ), 'billing_address_1 應已更新' );
		$this->assertSame( '高雄市', get_user_meta( $this->wang_id, 'billing_city', true ), 'billing_city 應已更新' );
		$this->assertSame( '800', get_user_meta( $this->wang_id, 'billing_postcode', true ), 'billing_postcode 應已更新' );
		$this->assertSame( 'TW', get_user_meta( $this->wang_id, 'billing_country', true ), 'billing_country 應已更新' );
		$this->assertSame( 'KHH', get_user_meta( $this->wang_id, 'billing_state', true ), 'billing_state 應已更新' );

		// 驗證 shipping meta
		$this->assertSame( '收件新路 999 號', get_user_meta( $this->wang_id, 'shipping_address_1', true ), 'shipping_address_1 應已更新' );
		$this->assertSame( '桃園市', get_user_meta( $this->wang_id, 'shipping_city', true ), 'shipping_city 應已更新' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: POST users/{id} 送入非空 user_pass → 密碼確實被更新（wp_check_password 驗證成功）
	 */
	public function test_post_user_updates_password_when_provided(): void {
		$new_pass = 'SuperSecurePass_' . uniqid();

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				'user_pass'    => $new_pass,
				'display_name' => '王小明',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '更新密碼應回 200' );

		// 用 wp_check_password 驗證新密碼正確
		$updated_user = get_user_by( 'id', $this->wang_id );
		$this->assertNotFalse( $updated_user, '用戶應存在' );
		$this->assertTrue(
			wp_check_password( $new_pass, $updated_user->user_pass, $this->wang_id ),
			'新密碼應正確（wp_check_password 應回傳 true）'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: user_pass 為空字串時，密碼不得被修改（endpoint 應 unset 空密碼）
	 */
	public function test_post_user_does_not_change_password_when_empty_string(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				'user_pass'    => '',           // 空字串 → 不應改密碼
				'display_name' => '王小明不改密',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '空密碼時更新其他欄位應回 200' );

		// 密碼雜湊應維持不變
		$current_user = get_user_by( 'id', $this->wang_id );
		$this->assertNotFalse( $current_user, '用戶應存在' );
		$this->assertSame(
			$this->wang_initial_pass_hash,
			$current_user->user_pass,
			'user_pass 為空字串時，密碼雜湊不應改變'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 請求中完全不帶 user_pass 欄位時，密碼同樣不得被修改
	 */
	public function test_post_user_does_not_change_password_when_key_absent(): void {
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_body_params(
			[
				// 刻意不傳 user_pass
				'display_name' => '王小明無密碼欄位',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '不帶 user_pass 時更新其他欄位應回 200' );

		// 密碼雜湊應維持不變
		$current_user = get_user_by( 'id', $this->wang_id );
		$this->assertNotFalse( $current_user, '用戶應存在' );
		$this->assertSame(
			$this->wang_initial_pass_hash,
			$current_user->user_pass,
			'不傳 user_pass 時，密碼雜湊不應改變'
		);
	}

	// ========== POST users/{id}/reset-password：發送密碼重設信 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: POST reset-password 對現有學員 → status 200, code reset_password_email_sent
	 */
	public function test_post_reset_password_returns_success_for_existing_user(): void {
		// 短路郵件發送，避免測試環境真的嘗試寄信或因 SMTP 不可用而失敗
		add_filter( 'wp_mail', '__return_true' );
		// 攔截 retrieve_password 的 wp_mail 呼叫，直接回傳 true
		add_filter( 'send_password_change_email', '__return_false' );
		add_filter( 'send_email_change_email', '__return_false' );

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->wang_id . '/reset-password' );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );

		$response = $this->api->post_users_with_id_reset_password_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), '密碼重設信應回 200' );
		$this->assertSame( 'reset_password_email_sent', $data['code'], 'code 應為 reset_password_email_sent' );

		remove_filter( 'wp_mail', '__return_true' );
		remove_filter( 'send_password_change_email', '__return_false' );
		remove_filter( 'send_email_change_email', '__return_false' );
	}

	// ========== GET users/{id}/orders-summary：訂單摘要 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 學員有 N 筆訂單 → total == N, recent count == min(N, limit), 每筆含 id/number/status/total
	 */
	public function test_get_orders_summary_returns_correct_count_and_fields(): void {
		// 建立 3 筆已完成的 WooCommerce 訂單
		$order_count = 3;
		for ( $i = 0; $i < $order_count; $i++ ) {
			$order = wc_create_order();
			$order->set_customer_id( $this->wang_id );
			$order->set_status( 'completed' );
			$order->calculate_totals();
			$order->save();
		}

		$limit   = 2;
		$request = new \WP_REST_Request( 'GET', '/power-course/v2/users/' . $this->wang_id . '/orders-summary' );
		$request->set_url_params( [ 'id' => (string) $this->wang_id ] );
		$request->set_query_params( [ 'limit' => $limit ] );

		$response = $this->api->get_users_with_id_orders_summary_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), '訂單摘要應回 200' );
		$this->assertArrayHasKey( 'total', $data, '應含 total 欄位' );
		$this->assertArrayHasKey( 'view_all_url', $data, '應含 view_all_url 欄位' );
		$this->assertArrayHasKey( 'recent', $data, '應含 recent 欄位' );

		// total 應等於建立的訂單數
		$this->assertSame( $order_count, $data['total'], "total 應為 {$order_count}" );

		// recent 筆數應受 limit 限制
		$this->assertIsArray( $data['recent'], 'recent 應為陣列' );
		$this->assertCount( $limit, $data['recent'], "recent 應包含 {$limit} 筆（受 limit 限制）" );

		// 每筆 recent item 應含必要欄位
		foreach ( $data['recent'] as $item ) {
			$this->assertArrayHasKey( 'id', $item, '每筆訂單摘要應含 id' );
			$this->assertArrayHasKey( 'number', $item, '每筆訂單摘要應含 number' );
			$this->assertArrayHasKey( 'status', $item, '每筆訂單摘要應含 status' );
			$this->assertArrayHasKey( 'total', $item, '每筆訂單摘要應含 total' );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 學員無任何訂單 → total:0, recent:[]（空陣列）
	 */
	public function test_get_orders_summary_returns_empty_for_no_orders(): void {
		// 建立一個全新的學員，確保無任何訂單
		$fresh_user_id = $this->factory()->user->create(
			[
				'user_login' => 'fresh_no_orders_' . uniqid(),
				'user_email' => 'fresh_no_orders_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$request = new \WP_REST_Request( 'GET', '/power-course/v2/users/' . $fresh_user_id . '/orders-summary' );
		$request->set_url_params( [ 'id' => (string) $fresh_user_id ] );
		$request->set_query_params( [ 'limit' => 5 ] );

		$response = $this->api->get_users_with_id_orders_summary_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), '無訂單學員應回 200' );
		$this->assertSame( 0, $data['total'], 'total 應為 0' );
		$this->assertIsArray( $data['recent'], 'recent 應為陣列' );
		$this->assertCount( 0, $data['recent'], 'recent 應為空陣列' );
	}
}
