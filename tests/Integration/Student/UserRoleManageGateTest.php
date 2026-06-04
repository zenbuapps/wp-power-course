<?php
/**
 * 使用者角色變更 — 後端 manage_options 守門 整合測試（TDD Red Phase）
 *
 * Feature: specs/issue-238-student-edit/student-edit-fixes.feature
 *   Rule: 使用者角色僅 Administrator 可修改（F2 / Q1=B）
 * API Spec: specs/issue-238-student-edit/api.yml （POST /users/{id}）
 *
 * 對應 Issue #238 F2 後端硬邊界：
 *   POST users/{id} 的角色（role）變更僅當呼叫者具 manage_options 時才套用；
 *   僅具 edit_users 的協作管理員即使送出 role 也會被靜默忽略，其餘允許欄位照常更新。
 *
 * @group student
 * @group user
 * @group security
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\User as UserApi;

/**
 * Class UserRoleManageGateTest
 */
class UserRoleManageGateTest extends TestCase {

	/** @var int 目標學員（初始 subscriber） */
	private int $student_id;

	/** @var UserApi API instance */
	private UserApi $api;

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		$this->api = UserApi::instance();
	}

	/**
	 * 每個測試前建立目標學員（初始角色 subscriber）
	 */
	public function set_up(): void {
		parent::set_up();

		$this->student_id = $this->factory()->user->create(
			[
				'user_login'   => 'role_target_' . uniqid(),
				'user_email'   => 'role_target_' . uniqid() . '@test.com',
				'display_name' => '王小明',
				'role'         => 'subscriber',
			]
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 具 manage_options 的管理員送出 role 變更 → 角色確實更新
	 */
	public function test_admin_can_change_role(): void {
		$admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->student_id );
		$request->set_url_params( [ 'id' => (string) $this->student_id ] );
		$request->set_body_params(
			[
				'role'         => 'customer',
				'display_name' => '王大明',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );
		$this->assertSame( 200, $response->get_status(), '管理員更新應回 200' );

		$updated = get_userdata( $this->student_id );
		$this->assertContains( 'customer', $updated->roles, '管理員應能將角色改為 customer' );
		$this->assertNotContains( 'subscriber', $updated->roles, '原 subscriber 角色應被取代' );
		$this->assertSame( '王大明', $updated->display_name, 'display_name 應一併更新' );
	}

	/**
	 * @test
	 * @group security
	 * Rule: 僅具 edit_users（無 manage_options）的協作管理員送出 role → 角色不變，其餘欄位仍更新
	 */
	public function test_non_admin_role_change_is_ignored_but_other_fields_update(): void {
		// 建立僅具 edit_users 的協作管理員（非 manage_options）
		$editor_id = $this->factory()->user->create( [ 'role' => 'editor' ] );
		$editor     = get_userdata( $editor_id );
		$editor->add_cap( 'edit_users' );
		wp_set_current_user( $editor_id );

		$this->assertTrue( current_user_can( 'edit_users' ), '前置：應具 edit_users' );
		$this->assertFalse( current_user_can( 'manage_options' ), '前置：不應具 manage_options' );

		$request = new \WP_REST_Request( 'POST', '/power-course/v2/users/' . $this->student_id );
		$request->set_url_params( [ 'id' => (string) $this->student_id ] );
		$request->set_body_params(
			[
				'role'         => 'administrator', // 嘗試提權
				'display_name' => '被協作管理員改名',
			]
		);

		$response = $this->api->post_users_with_id_callback( $request );
		$this->assertSame( 200, $response->get_status(), '更新其餘欄位應回 200' );

		$updated = get_userdata( $this->student_id );
		$this->assertContains( 'subscriber', $updated->roles, '角色應維持 subscriber（role 變更被忽略）' );
		$this->assertNotContains( 'administrator', $updated->roles, '非管理員不得提權目標為 administrator' );
		$this->assertSame( '被協作管理員改名', $updated->display_name, 'display_name 等允許欄位仍應正常更新' );
	}
}
