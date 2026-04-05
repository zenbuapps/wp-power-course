<?php
/**
 * 學員格式化名稱 整合測試
 * Issue: #54 - 學員名稱顯示邏輯 Fallback Chain
 *
 * @group student
 * @group formatted-name
 */

declare( strict_types=1 );

namespace Tests\Integration\Student;

use Tests\Integration\TestCase;
use J7\PowerCourse\Utils\User;

/**
 * Class FormattedNameTest
 * 測試 User::get_formatted_name() 的 Fallback Chain 邏輯
 *
 * Fallback Chain:
 * ① billing_last_name + billing_first_name
 * ② last_name + first_name
 * ③ display_name
 */
class FormattedNameTest extends TestCase {

	/**
	 * 初始化依賴
	 */
	protected function configure_dependencies(): void {
		// 此測試直接使用靜態方法，不需要額外 Service 實例
	}

	// ========== Fallback Chain 測試 ==========

	/**
	 * @test
	 * @group happy
	 * 有 billing 姓名時優先使用 billing
	 */
	public function test_returns_billing_name_when_both_exist(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_billing_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		update_user_meta( $user_id, 'billing_last_name', '劉' );
		update_user_meta( $user_id, 'billing_first_name', '大明' );
		update_user_meta( $user_id, 'last_name', 'Liu' );
		update_user_meta( $user_id, 'first_name', 'DaMing' );

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '劉大明', $result, 'billing 姓名應優先於 WP meta 姓名' );
	}

	/**
	 * @test
	 * @group happy
	 * billing 為空時 fallback 到 WP meta 姓名
	 */
	public function test_returns_wp_meta_name_when_billing_empty(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_meta_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		// 不設 billing meta
		update_user_meta( $user_id, 'last_name', '林' );
		update_user_meta( $user_id, 'first_name', '小玉' );

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '林小玉', $result, 'billing 為空時應使用 WP meta 姓名' );
	}

	/**
	 * @test
	 * @group happy
	 * 兩組姓名都空時 fallback 到 display_name
	 */
	public function test_returns_display_name_when_all_empty(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_display_' . uniqid(),
			'display_name' => 'DisplayUser',
		] );

		// 不設任何姓名 meta
		$result = User::get_formatted_name( $user_id );
		$this->assertSame( 'DisplayUser', $result, '兩組姓名都空時應使用 display_name' );
	}

	// ========== Partial 名稱測試 ==========

	/**
	 * @test
	 * @group edge
	 * 只有 billing_last_name 有值時僅顯示 last_name
	 */
	public function test_returns_partial_billing_last_name_only(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_partial_bl_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		update_user_meta( $user_id, 'billing_last_name', '陳' );
		// billing_first_name 不設

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '陳', $result, '只有 billing_last_name 時應僅顯示 last_name' );
	}

	/**
	 * @test
	 * @group edge
	 * 只有 billing_first_name 有值時僅顯示 first_name
	 */
	public function test_returns_partial_billing_first_name_only(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_partial_bf_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		update_user_meta( $user_id, 'billing_first_name', '大華' );
		// billing_last_name 不設

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '大華', $result, '只有 billing_first_name 時應僅顯示 first_name' );
	}

	/**
	 * @test
	 * @group edge
	 * 只有 WP meta first_name 有值時僅顯示 first_name
	 */
	public function test_returns_partial_wp_meta_first_name_only(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_partial_wf_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		update_user_meta( $user_id, 'first_name', '小美' );
		// last_name 不設，billing 也不設

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '小美', $result, '只有 WP meta first_name 時應僅顯示 first_name' );
	}

	// ========== 優先順序測試 ==========

	/**
	 * @test
	 * @group happy
	 * billing 優先順序高於 WP meta（兩者都有值）
	 */
	public function test_billing_takes_priority_over_wp_meta(): void {
		$user_id = $this->factory()->user->create( [
			'user_login'   => 'test_priority_' . uniqid(),
			'display_name' => 'TestUser',
		] );

		update_user_meta( $user_id, 'billing_last_name', '王' );
		update_user_meta( $user_id, 'billing_first_name', '大同' );
		update_user_meta( $user_id, 'last_name', 'Wang' );
		update_user_meta( $user_id, 'first_name', 'DaTong' );

		$result = User::get_formatted_name( $user_id );
		$this->assertSame( '王大同', $result, 'billing 姓名應優先於 WP meta' );
	}

	// ========== 異常情況 ==========

	/**
	 * @test
	 * @group edge
	 * user_id 不存在時回傳空字串
	 */
	public function test_returns_empty_string_for_nonexistent_user(): void {
		$result = User::get_formatted_name( 99999 );
		$this->assertSame( '', $result, '不存在的 user_id 應回傳空字串' );
	}

	// ========== get_first_name / get_last_name 測試 ==========

	/**
	 * @test
	 * @group happy
	 * get_last_name 遵循 Fallback Chain（billing → WP meta）
	 */
	public function test_get_last_name_follows_fallback(): void {
		$user_id = $this->factory()->user->create( [
			'user_login' => 'test_ln_' . uniqid(),
		] );

		// 設定 billing
		update_user_meta( $user_id, 'billing_last_name', '趙' );
		update_user_meta( $user_id, 'last_name', 'Zhao' );

		$result = User::get_last_name( $user_id );
		$this->assertSame( '趙', $result, 'get_last_name 應優先取 billing_last_name' );
	}

	/**
	 * @test
	 * @group happy
	 * get_last_name billing 為空時取 WP meta
	 */
	public function test_get_last_name_fallback_to_wp_meta(): void {
		$user_id = $this->factory()->user->create( [
			'user_login' => 'test_ln_fb_' . uniqid(),
		] );

		update_user_meta( $user_id, 'last_name', 'Zhao' );

		$result = User::get_last_name( $user_id );
		$this->assertSame( 'Zhao', $result, 'billing_last_name 為空時應取 WP meta last_name' );
	}

	/**
	 * @test
	 * @group happy
	 * get_first_name 遵循 Fallback Chain（billing → WP meta）
	 */
	public function test_get_first_name_follows_fallback(): void {
		$user_id = $this->factory()->user->create( [
			'user_login' => 'test_fn_' . uniqid(),
		] );

		update_user_meta( $user_id, 'billing_first_name', '大華' );
		update_user_meta( $user_id, 'first_name', 'DaHua' );

		$result = User::get_first_name( $user_id );
		$this->assertSame( '大華', $result, 'get_first_name 應優先取 billing_first_name' );
	}

	/**
	 * @test
	 * @group happy
	 * get_first_name billing 為空時取 WP meta
	 */
	public function test_get_first_name_fallback_to_wp_meta(): void {
		$user_id = $this->factory()->user->create( [
			'user_login' => 'test_fn_fb_' . uniqid(),
		] );

		update_user_meta( $user_id, 'first_name', 'DaHua' );

		$result = User::get_first_name( $user_id );
		$this->assertSame( 'DaHua', $result, 'billing_first_name 為空時應取 WP meta first_name' );
	}

	/**
	 * @test
	 * @group edge
	 * get_last_name / get_first_name 不存在用戶返回空字串
	 */
	public function test_get_name_parts_return_empty_for_nonexistent_user(): void {
		$this->assertSame( '', User::get_last_name( 99999 ), 'get_last_name 不存在用戶應回傳空字串' );
		$this->assertSame( '', User::get_first_name( 99999 ), 'get_first_name 不存在用戶應回傳空字串' );
	}
}
