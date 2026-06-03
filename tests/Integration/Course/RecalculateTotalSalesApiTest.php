<?php
/**
 * 重新計算課程已售出數量 REST 端點 整合測試
 * Feature: specs/features/report/重新計算課程已售出數量.feature
 *
 * Rule: 前置（權限）- 僅管理員可手動觸發重新計算
 *
 * @group course
 * @group api
 * @group total-sales
 */

declare( strict_types=1 );

namespace Tests\Integration\Course;

use Tests\Integration\TestCase;
use J7\PowerCourse\Api\Course as CourseApi;

/**
 * Class RecalculateTotalSalesApiTest
 */
class RecalculateTotalSalesApiTest extends TestCase {

	protected function configure_dependencies(): void {
		// 確保 Course API 已註冊 REST 路由
		CourseApi::instance();
		do_action( 'rest_api_init' );
	}

	/**
	 * 觸發 REST 請求
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch() {
		$request = new \WP_REST_Request( 'POST', '/power-course/courses/recalculate-total-sales' );
		$server  = \rest_get_server();
		return $server->dispatch( $request );
	}

	/**
	 * @test
	 * @group security
	 * Example: 非管理員觸發重新計算失敗（權限不足）
	 */
	public function test_非管理員觸發失敗(): void {
		$customer_id = $this->factory()->user->create(
			[
				'user_login' => 'customer_recalc_' . uniqid(),
				'user_email' => 'customer_recalc_' . uniqid() . '@test.com',
				'role'       => 'customer',
			]
		);
		\wp_set_current_user( $customer_id );

		$response = $this->dispatch();

		$this->assertSame( 403, $response->get_status(), '非管理員應回 403' );
	}

	/**
	 * @test
	 * @group happy
	 * Example: 管理員觸發重新計算成功，回應已排程
	 */
	public function test_管理員觸發成功回排程(): void {
		$admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_recalc_' . uniqid(),
				'user_email' => 'admin_recalc_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		\wp_set_current_user( $admin_id );

		$response = $this->dispatch();

		$this->assertSame( 200, $response->get_status(), '管理員應回 200' );

		$data = $response->get_data();
		$this->assertSame( 'recalculate_total_sales_scheduled', $data['code'] );
		$this->assertTrue( (bool) $data['data']['scheduled'] );
	}
}
