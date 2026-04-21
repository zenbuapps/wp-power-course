<?php
/**
 * 講師 API 端點整合測試
 *
 * Features:
 *   - specs/plans/refactor-teacher-management.plan.md 階段 1
 *
 * 測試 J7\PowerCourse\Api\User 的：
 * - post_users_add_teachers_callback — partial success 結構
 * - post_users_remove_teachers_callback — partial success 結構（修正 early-break bug）
 * - check_manage_woocommerce_permission — 權限守門
 *
 * @group teacher
 * @group user
 * @group api
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\User as UserApi;

/**
 * Class TeacherApiTest
 * 測試 User API 的講師相關端點新回傳結構與權限守門
 */
class TeacherApiTest extends TestCase {

	/** @var int Carol 用戶 ID（customer） */
	private int $carol_id;

	/** @var int David 用戶 ID（customer） */
	private int $david_id;

	/** @var int Eve 用戶 ID（已是講師） */
	private int $eve_id;

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

		$this->carol_id = $this->factory()->user->create(
			[
				'user_login' => 'carol_' . uniqid(),
				'user_email' => 'carol_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->david_id = $this->factory()->user->create(
			[
				'user_login' => 'david_' . uniqid(),
				'user_email' => 'david_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		$this->eve_id = $this->factory()->user->create(
			[
				'user_login' => 'eve_' . uniqid(),
				'user_email' => 'eve_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);

		// Eve 已是講師
		update_user_meta( $this->eve_id, 'is_teacher', 'yes' );
	}

	/**
	 * Helper：建立 WP_REST_Request 並塞入 body params
	 *
	 * @param array<string, mixed> $body_params Body parameters.
	 * @return \WP_REST_Request
	 */
	private function build_request( array $body_params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/remove-teachers' );
		foreach ( $body_params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		// 確保 body_params 也有被設（某些 WP_REST_Request 版本需要）
		$request->set_body_params( $body_params );
		return $request;
	}

	// ========== remove-teachers：partial success 回傳結構 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 全部成功時 data.user_ids 收集所有成功 ID，failed_user_ids 為空，status 200。
	 */
	public function test_remove_teachers_all_success(): void {
		// Given: 兩個都是講師
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		update_user_meta( $this->david_id, 'is_teacher', 'yes' );

		$request = $this->build_request(
			[
				'user_ids' => [ (string) $this->carol_id, (string) $this->david_id ],
			]
		);

		$response = $this->api->post_users_remove_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), '全部成功應 status 200' );
		$this->assertSame( 'remove_teachers_success', $data['code'] );
		$this->assertEqualsCanonicalizing(
			[ (string) $this->carol_id, (string) $this->david_id ],
			$data['data']['user_ids'],
			'成功清單應包含兩個 ID'
		);
		$this->assertSame( [], $data['data']['failed_user_ids'], 'failed_user_ids 應為空' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: partial success 時 status 仍 200，但 failed_user_ids 非空，
	 *       且迴圈**繼續處理**（不可 early-break）。
	 */
	public function test_remove_teachers_partial_success_continues_processing(): void {
		// Given: Carol 是講師；David 不是；Eve 也是講師（在 set_up 設好）
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		// David 沒有 is_teacher meta

		$request = $this->build_request(
			[
				// 順序故意把 David（會失敗）放在中間，驗證 Eve（最後一個）仍被處理
				'user_ids' => [ (string) $this->carol_id, (string) $this->david_id, (string) $this->eve_id ],
			]
		);

		$response = $this->api->post_users_remove_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'partial success 也應 status 200' );
		$this->assertSame( 'remove_teachers_success', $data['code'] );

		$this->assertEqualsCanonicalizing(
			[ (string) $this->carol_id, (string) $this->eve_id ],
			$data['data']['user_ids'],
			'成功清單應包含 Carol 與 Eve'
		);
		$this->assertSame( [ (string) $this->david_id ], $data['data']['failed_user_ids'], '失敗清單應只有 David' );

		// 驗證 Eve（在 David 失敗之後）確實被處理了 — 這是反 early-break 的關鍵斷言
		$eve_meta = get_user_meta( $this->eve_id, 'is_teacher', true );
		$this->assertSame( '', $eve_meta, 'Eve 的 is_teacher 應已被刪除（即使前面有 David 失敗也不中斷）' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 全部失敗時回 status 400，user_ids 為空，failed_user_ids 收集所有 ID。
	 */
	public function test_remove_teachers_all_failed(): void {
		// Given: Carol 與 David 都不是講師
		$request = $this->build_request(
			[
				'user_ids' => [ (string) $this->carol_id, (string) $this->david_id ],
			]
		);

		$response = $this->api->post_users_remove_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status(), '全部失敗應 status 400' );
		$this->assertSame( 'remove_teachers_failed', $data['code'] );
		$this->assertSame( [], $data['data']['user_ids'], '成功清單應為空' );
		$this->assertEqualsCanonicalizing(
			[ (string) $this->carol_id, (string) $this->david_id ],
			$data['data']['failed_user_ids'],
			'失敗清單應包含兩個 ID'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 空 user_ids 時回 400 + 空清單，不應 fatal。
	 */
	public function test_remove_teachers_empty_user_ids(): void {
		$request = $this->build_request( [ 'user_ids' => [] ] );

		$response = $this->api->post_users_remove_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'remove_teachers_invalid_params', $data['code'] );
		$this->assertSame( [], $data['data']['user_ids'] );
		$this->assertSame( [], $data['data']['failed_user_ids'] );
	}

	// ========== add-teachers：partial success 回傳結構 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 全部成功時 data.user_ids 收集所有成功 ID，failed_user_ids 為空，status 200。
	 */
	public function test_add_teachers_all_success(): void {
		$request = $this->build_request(
			[
				'user_ids' => [ (string) $this->carol_id, (string) $this->david_id ],
			]
		);

		$response = $this->api->post_users_add_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'add_teachers_success', $data['code'] );
		$this->assertEqualsCanonicalizing(
			[ (string) $this->carol_id, (string) $this->david_id ],
			$data['data']['user_ids']
		);
		$this->assertSame( [], $data['data']['failed_user_ids'] );

		// 驗證實際 meta 已寫入
		$this->assertSame( 'yes', get_user_meta( $this->carol_id, 'is_teacher', true ) );
		$this->assertSame( 'yes', get_user_meta( $this->david_id, 'is_teacher', true ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 不存在的 user_id 應進 failed_user_ids；迴圈繼續處理。
	 */
	public function test_add_teachers_partial_success_with_invalid_user(): void {
		$invalid_user_id = 999999999;

		$request = $this->build_request(
			[
				// 把無效 ID 夾在中間
				'user_ids' => [ (string) $this->carol_id, (string) $invalid_user_id, (string) $this->david_id ],
			]
		);

		$response = $this->api->post_users_add_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'partial success 應 status 200' );
		$this->assertEqualsCanonicalizing(
			[ (string) $this->carol_id, (string) $this->david_id ],
			$data['data']['user_ids']
		);
		$this->assertSame( [ (string) $invalid_user_id ], $data['data']['failed_user_ids'] );

		// David 應被處理（反 early-break 驗證）
		$this->assertSame( 'yes', get_user_meta( $this->david_id, 'is_teacher', true ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 空 user_ids 時回 400。
	 */
	public function test_add_teachers_empty_user_ids(): void {
		$request = $this->build_request( [ 'user_ids' => [] ] );

		$response = $this->api->post_users_add_teachers_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'add_teachers_invalid_params', $data['code'] );
	}

	// ========== 權限檢查：check_manage_woocommerce_permission ==========

	/**
	 * @test
	 * @group security
	 * Rule: 未登入使用者應被拒絕，回傳 WP_Error with status 401。
	 */
	public function test_permission_denied_for_logged_out_user(): void {
		wp_set_current_user( 0 );

		$result = UserApi::check_manage_woocommerce_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 401, $data['status'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 訂閱者（無 manage_woocommerce 能力）應被拒絕，回 403。
	 */
	public function test_permission_denied_for_subscriber(): void {
		$subscriber_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber_id );

		$result = UserApi::check_manage_woocommerce_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}

	/**
	 * @test
	 * @group security
	 * Rule: administrator（有 manage_woocommerce）應通過，回 true。
	 */
	public function test_permission_granted_for_administrator(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$result = UserApi::check_manage_woocommerce_permission();

		$this->assertTrue( $result );
	}

	// ========== other_meta_data 陣列批次更新（Meta Tab save） ==========

	/**
	 * @test
	 * @group happy
	 * Rule: post_users_with_id_callback 接到 other_meta_data 陣列時，
	 *       透過 umeta_id 精準更新對應的 user_meta row，不影響其他 meta。
	 */
	public function test_update_other_meta_data_via_umeta_id(): void {
		// Given: 講師 Carol 已有兩筆自訂 meta
		update_user_meta( $this->carol_id, 'is_teacher', 'yes' );
		update_user_meta( $this->carol_id, 'custom_key_a', 'value_a_old' );
		update_user_meta( $this->carol_id, 'custom_key_b', 'value_b_old' );

		// 取得 umeta_id（模擬前端從 GET /users/{id} 拿到的 other_meta_data 結構）
		global $wpdb;
		/** @var array<int, array{umeta_id: string, meta_key: string}> $meta_rows */
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_key FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key IN ('custom_key_a', 'custom_key_b')",
				$this->carol_id
			),
			ARRAY_A
		);

		$this->assertCount( 2, $meta_rows, '應有兩筆可更新的 meta row' );

		$other_meta_data = [];
		foreach ( $meta_rows as $row ) {
			$other_meta_data[] = [
				'umeta_id'   => $row['umeta_id'],
				'meta_key'   => $row['meta_key'],
				'meta_value' => $row['meta_key'] === 'custom_key_a' ? 'value_a_new' : 'value_b_new',
			];
		}

		// When: 打 API 更新
		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->carol_id );
		$request->set_url_params( [ 'id' => (string) $this->carol_id ] );
		$request->set_body_params( [ 'other_meta_data' => $other_meta_data ] );

		$response = $this->api->post_users_with_id_callback( $request );

		// Then: 更新成功、原本的值已替換、is_teacher 保持不變
		$this->assertSame( 200, $response->get_status(), '更新應成功' );
		$this->assertSame( 'value_a_new', get_user_meta( $this->carol_id, 'custom_key_a', true ) );
		$this->assertSame( 'value_b_new', get_user_meta( $this->carol_id, 'custom_key_b', true ) );
		$this->assertSame( 'yes', get_user_meta( $this->carol_id, 'is_teacher', true ), 'is_teacher 不應受影響' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: other_meta_data 中 umeta_id=0 或 meta_key 空 → 跳過該筆，不 fatal
	 */
	public function test_update_other_meta_data_skips_invalid_records(): void {
		update_user_meta( $this->carol_id, 'custom_key_c', 'unchanged' );

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->carol_id );
		$request->set_url_params( [ 'id' => (string) $this->carol_id ] );
		$request->set_body_params(
			[
				'other_meta_data' => [
					[
						'umeta_id'   => 0,
						'meta_key'   => 'custom_key_c',
						'meta_value' => 'attempt_bypass',
					], // umeta_id=0 應跳過
					[
						'umeta_id'   => 999,
						'meta_key'   => '',
						'meta_value' => 'empty_key',
					], // 空 meta_key 應跳過
					'not_an_array', // 非陣列應跳過
				],
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status(), '即使 other_meta_data 含無效項，API 應仍成功' );
		// custom_key_c 應保持原值（因為 umeta_id=0 被跳過）
		$this->assertSame( 'unchanged', get_user_meta( $this->carol_id, 'custom_key_c', true ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: other_meta_data 為空陣列或缺失 → 不影響 wp_update_user 流程
	 */
	public function test_update_other_meta_data_empty_does_not_break(): void {
		update_user_meta( $this->carol_id, 'existing', 'kept' );

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->carol_id );
		$request->set_url_params( [ 'id' => (string) $this->carol_id ] );
		$request->set_body_params(
			[
				'display_name' => 'Carol Renamed',
				// 沒有 other_meta_data
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$user = get_user_by( 'id', $this->carol_id );
		$this->assertSame( 'Carol Renamed', $user->display_name );
		$this->assertSame( 'kept', get_user_meta( $this->carol_id, 'existing', true ) );
	}
}
