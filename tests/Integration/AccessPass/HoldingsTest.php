<?php
/**
 * 通行證持有關係展示層 整合測試
 *
 * Issue #252 補完：學員前台原本完全看不到自己持有哪些通行證。
 * 本測試覆蓋 Service\Holdings（剩餘天數 / 快到期 / 來源 / 排序）
 * 與前台 REST（GET /access-passes/me、GET /access-passes/{id}/courses）。
 *
 * 關鍵不變式：is_valid 必須來自 Gate::is_expire_valid()，
 * 與教室的觀看判定同一份邏輯——否則會出現「前台說還有效、教室卻擋下來」。
 *
 * @group access-pass
 * @group holdings
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\Api;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Holdings;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class HoldingsTest
 */
class HoldingsTest extends TestCase {

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 管理員 */
	private int $admin_id;

	/** @var int 測試課程 */
	private int $course_id;

	/**
	 * 初始化依賴（Holdings 為靜態 Service，不需注入）
	 */
	protected function configure_dependencies(): void {
	}

	/** 建立使用者與課程 */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id  = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->alice_id  = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bob_id    = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->course_id = $this->create_course( [ 'post_title' => 'Holdings 測試課' ] );
	}

	/** 清理持有表與 Gate 快取 */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Gate::flush_cache();
		\wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * 建立通行證 CPT
	 *
	 * @param array<string, mixed> $args limit_type / limit_value / scope_type / name
	 * @return int pass_id
	 */
	private function create_access_pass( array $args = [] ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => $args['name'] ?? '持有測試通行證',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', $args['scope_type'] ?? 'all' );
		\update_post_meta( $pass_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', $args['status'] ?? 'active' );

		if ( isset( $args['limit_value'] ) ) {
			\update_post_meta( $pass_id, 'limit_value', $args['limit_value'] );
		}

		return $pass_id;
	}

	/**
	 * 從 get_for_user 結果中挑出指定 pass 的那一筆
	 *
	 * @param int $user_id 學員 ID
	 * @param int $pass_id 通行證 ID
	 * @return array<string, mixed>|null
	 */
	private function holding_of( int $user_id, int $pass_id ): ?array {
		foreach ( Holdings::get_for_user( $user_id ) as $item ) {
			if ( (int) $item['pass_id'] === $pass_id ) {
				return $item;
			}
		}
		return null;
	}

	// ========== Smoke ==========

	/**
	 * @test
	 * @group smoke
	 * Rule: Holdings 的公開方法存在
	 */
	public function test_holdings_方法存在(): void {
		$this->assertTrue( \method_exists( Holdings::class, 'get_for_user' ) );
		$this->assertTrue( \method_exists( Holdings::class, 'get_holders_of_pass' ) );
		$this->assertTrue( \method_exists( Holdings::class, 'get_expiring_threshold_days' ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 未持有任何通行證時回空陣列（不是 null、不丟錯）
	 */
	public function test_未持有時回空陣列(): void {
		$this->assertSame( [], Holdings::get_for_user( $this->alice_id ) );
		$this->assertSame( [], Holdings::get_for_user( 0 ) );
	}

	// ========== 剩餘天數 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: unlimited 沒有到期日，days_remaining 為 null（而非 0）
	 */
	public function test_unlimited_剩餘天數為null且有效(): void {
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'unlimited' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );

		$holding = $this->holding_of( $this->alice_id, $pass_id );

		$this->assertNotNull( $holding );
		$this->assertNull( $holding['days_remaining'], 'unlimited 不該有剩餘天數' );
		$this->assertNull( $holding['expire_timestamp'] );
		$this->assertTrue( $holding['is_valid'] );
		$this->assertFalse( $holding['is_expiring_soon'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 未到期時算得出剩餘天數，且與 Gate 判定一致為有效
	 */
	public function test_fixed_未到期_剩餘天數正確(): void {
		$expire  = \time() + ( 10 * DAY_IN_SECONDS );
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'fixed' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		$holding = $this->holding_of( $this->alice_id, $pass_id );

		$this->assertSame( 10, $holding['days_remaining'] );
		$this->assertSame( $expire, $holding['expire_timestamp'] );
		$this->assertTrue( $holding['is_valid'] );
		$this->assertTrue(
			CourseUtils::is_avl( $this->course_id, $this->alice_id ),
			'Holdings 說有效時，教室也必須放行'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 今天稍晚就到期 → 剩餘天數進位為 1（不是 0，避免顯示成「今天就沒了」的誤導）
	 */
	public function test_fixed_數小時後到期_剩餘天數進位為1(): void {
		$expire  = \time() + ( 3 * HOUR_IN_SECONDS );
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'fixed' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		$holding = $this->holding_of( $this->alice_id, $pass_id );

		$this->assertSame( 1, $holding['days_remaining'] );
		$this->assertTrue( $holding['is_valid'] );
		$this->assertTrue( $holding['is_expiring_soon'], '3 小時後到期必定落在快到期門檻內' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 已過期 → is_valid=false、days_remaining 收斂為 0，且教室確實擋下
	 */
	public function test_fixed_已過期_無效且教室擋下(): void {
		$expire  = \time() - DAY_IN_SECONDS;
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'fixed' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) $expire );

		$holding = $this->holding_of( $this->alice_id, $pass_id );

		$this->assertFalse( $holding['is_valid'] );
		$this->assertSame( 0, $holding['days_remaining'] );
		$this->assertFalse( $holding['is_expiring_soon'], '已過期不算「即將到期」' );
		$this->assertFalse( CourseUtils::is_avl( $this->course_id, $this->alice_id ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 已失效的通行證仍會列出——學員需要知道「我買過但已到期」
	 */
	public function test_已失效的通行證仍列出(): void {
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'fixed' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, (string) ( \time() - 100 ) );

		$this->assertNotNull(
			$this->holding_of( $this->alice_id, $pass_id ),
			'失效的通行證不應被靜默濾掉'
		);
	}

	// ========== 來源判定 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: granted_by 有值 → 手動發放
	 */
	public function test_來源_有granted_by為手動發放(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0', $this->admin_id );

		$this->assertSame( Holdings::SOURCE_MANUAL, $this->holding_of( $this->alice_id, $pass_id )['source'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 有 source_order_id 且無 granted_by → 一次性訂單
	 */
	public function test_來源_有訂單為order(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, 12345, '0' );

		$holding = $this->holding_of( $this->alice_id, $pass_id );
		$this->assertSame( Holdings::SOURCE_ORDER, $holding['source'] );
		$this->assertSame( 12345, $holding['source_order_id'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 兩者皆空 → 訂閱開通（Grant::on_subscription_payment_complete 兩欄都傳 null）
	 */
	public function test_來源_兩者皆空為subscription(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );

		$this->assertSame( Holdings::SOURCE_SUBSCRIPTION, $this->holding_of( $this->alice_id, $pass_id )['source'] );
	}

	// ========== 排序與降級 ==========

	/**
	 * @test
	 * @group edge
	 * Rule: 有效的排前面；都有效時到期日近者優先；無到期日（永久）排最後
	 */
	public function test_排序_有效優先且到期近者優先(): void {
		$expired = $this->create_access_pass( [ 'limit_type' => 'fixed', 'name' => '已過期' ] );
		$far     = $this->create_access_pass( [ 'limit_type' => 'fixed', 'name' => '還很久' ] );
		$near    = $this->create_access_pass( [ 'limit_type' => 'fixed', 'name' => '快到期' ] );
		$forever = $this->create_access_pass( [ 'limit_type' => 'unlimited', 'name' => '永久' ] );

		Repository::insert_or_update( $this->alice_id, $expired, null, (string) ( \time() - 100 ) );
		Repository::insert_or_update( $this->alice_id, $far, null, (string) ( \time() + ( 90 * DAY_IN_SECONDS ) ) );
		Repository::insert_or_update( $this->alice_id, $near, null, (string) ( \time() + ( 3 * DAY_IN_SECONDS ) ) );
		Repository::insert_or_update( $this->alice_id, $forever, null, '0' );

		$order = \array_column( Holdings::get_for_user( $this->alice_id ), 'pass_id' );

		$this->assertSame( [ $near, $far, $forever, $expired ], $order );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 持有列殘留但通行證 CPT 已被刪 → 靜默跳過（與 Gate 的降級一致）
	 */
	public function test_通行證已刪除時跳過該筆(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );
		\wp_delete_post( $pass_id, true );

		$this->assertSame( [], Holdings::get_for_user( $this->alice_id ) );
	}

	// ========== 後台持有名單 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 持有名單帶出學員顯示資訊與分頁資訊
	 */
	public function test_持有名單_含學員資訊與分頁(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0', $this->admin_id );
		Repository::insert_or_update( $this->bob_id, $pass_id, 999, '0' );

		$result = Holdings::get_holders_of_pass( $pass_id, 1, 20 );

		$this->assertSame( 2, $result['total'] );
		$this->assertSame( 1, $result['total_pages'] );
		$this->assertCount( 2, $result['items'] );

		$user_ids = \array_column( $result['items'], 'user_id' );
		$this->assertContains( $this->alice_id, $user_ids );
		$this->assertContains( $this->bob_id, $user_ids );

		foreach ( $result['items'] as $item ) {
			$this->assertArrayHasKey( 'display_name', $item );
			$this->assertArrayHasKey( 'user_email', $item );
			$this->assertNotSame( '', $item['user_email'], '應帶出學員 email' );
		}
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 持有名單分頁生效，且手動發放者的名字帶得出來
	 */
	public function test_持有名單_分頁與發放者名稱(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0', $this->admin_id );
		Repository::insert_or_update( $this->bob_id, $pass_id, null, '0', $this->admin_id );

		$page1 = Holdings::get_holders_of_pass( $pass_id, 1, 1 );

		$this->assertCount( 1, $page1['items'], 'per_page=1 應只回 1 筆' );
		$this->assertSame( 2, $page1['total'] );
		$this->assertSame( 2, $page1['total_pages'] );

		$admin = \get_userdata( $this->admin_id );
		$this->assertSame( $admin->display_name, $page1['items'][0]['granted_by_name'] );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 通行證不存在時回空結果而非丟錯
	 */
	public function test_持有名單_通行證不存在回空(): void {
		$result = Holdings::get_holders_of_pass( 999999, 1, 20 );

		$this->assertSame( 0, $result['total'] );
		$this->assertSame( [], $result['items'] );
	}

	// ========== 前台 REST ==========

	/**
	 * @test
	 * @group happy
	 * Rule: GET /access-passes/me 只回目前登入者自己的通行證
	 */
	public function test_rest_me_只回自己的通行證(): void {
		$alice_pass = $this->create_access_pass( [ 'name' => 'Alice 的' ] );
		$bob_pass   = $this->create_access_pass( [ 'name' => 'Bob 的' ] );
		Repository::insert_or_update( $this->alice_id, $alice_pass, null, '0' );
		Repository::insert_or_update( $this->bob_id, $bob_pass, null, '0' );

		\wp_set_current_user( $this->alice_id );

		$request  = new \WP_REST_Request( 'GET', '/power-course/access-passes/me' );
		$response = Api::instance()->get_access_passes_me_callback( $request );
		$data     = $response->get_data();

		$this->assertCount( 1, $data );
		$this->assertSame( $alice_pass, $data[0]['pass_id'], '不得回傳其他使用者的持有關係' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 未登入時 /me 的權限守門回 401
	 */
	public function test_rest_me_未登入回401(): void {
		\wp_set_current_user( 0 );

		$result = Api::check_logged_in_permission();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 持有者可以查詢該通行證涵蓋的課程
	 */
	public function test_rest_courses_持有者可查詢(): void {
		$pass_id = $this->create_access_pass( [ 'scope_type' => 'all' ] );
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );

		\wp_set_current_user( $this->alice_id );

		$request = new \WP_REST_Request( 'GET', "/power-course/access-passes/{$pass_id}/courses" );
		$request->set_url_params( [ 'id' => (string) $pass_id ] );

		$this->assertTrue( Api::check_pass_courses_permission( $request ), '持有者應可查詢' );

		$data = Api::instance()->get_access_passes_with_id_courses_callback( $request )->get_data();

		$this->assertNotEmpty( $data );
		$this->assertContains( $this->course_id, \array_column( $data, 'id' ) );
		$this->assertArrayHasKey( 'permalink', $data[0] );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 非持有者（且非管理員）不得查詢通行證涵蓋範圍
	 *
	 * 涵蓋範圍等於站主的商品組合策略，不該對未持有者公開。
	 */
	public function test_rest_courses_非持有者回403(): void {
		$pass_id = $this->create_access_pass();
		Repository::insert_or_update( $this->alice_id, $pass_id, null, '0' );

		\wp_set_current_user( $this->bob_id );

		$request = new \WP_REST_Request( 'GET', "/power-course/access-passes/{$pass_id}/courses" );
		$request->set_url_params( [ 'id' => (string) $pass_id ] );

		$result = Api::check_pass_courses_permission( $request );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 管理員即使未持有也可查詢（後台需要看得到範圍）
	 */
	public function test_rest_courses_管理員可查詢(): void {
		$pass_id = $this->create_access_pass();

		\wp_set_current_user( $this->admin_id );

		$request = new \WP_REST_Request( 'GET', "/power-course/access-passes/{$pass_id}/courses" );
		$request->set_url_params( [ 'id' => (string) $pass_id ] );

		$this->assertTrue( Api::check_pass_courses_permission( $request ) );
	}
}
