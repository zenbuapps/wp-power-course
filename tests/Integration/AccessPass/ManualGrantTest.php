<?php
/**
 * 後台手動發放 / 撤銷課程通行證 整合測試
 *
 * Issue #252 補完：站主原本只能靠「幫學員建一張免費訂單」來發放通行證，
 * 本測試覆蓋 Service 層（Grant::grant_manually / revoke / assert_grantable）
 * 與 REST 層（POST /access-passes/{id}/grant、/revoke）。
 *
 * 關鍵不變式：
 *   1. 到期日一律依通行證的 limit_type 自動計算，與購買開通完全一致。
 *   2. follow_subscription 拒絕手動發放——沒有訂單可綁訂閱，發了會立刻失效。
 *   3. 撤銷只刪 pc_user_access_pass 的對應列，絕不碰 avl_course_ids。
 *
 * @group access-pass
 * @group manual-grant
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\Api;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class ManualGrantTest
 */
class ManualGrantTest extends TestCase {

	/** @var int 管理員 ID（發放者） */
	private int $admin_id;

	/** @var int 學員 Alice */
	private int $alice_id;

	/** @var int 學員 Bob */
	private int $bob_id;

	/** @var int 測試課程 */
	private int $course_id;

	/**
	 * 初始化依賴（本測試全走靜態 Service，不需注入）
	 */
	protected function configure_dependencies(): void {
	}

	/** 每個測試前建立共用 fixture */
	public function set_up(): void {
		parent::set_up();

		$this->admin_id = $this->factory()->user->create( [ 'role' => 'administrator' ] );
		$this->alice_id = $this->factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->bob_id   = $this->factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->course_id = $this->create_course( [ 'post_title' => '手動發放測試課' ] );

		\wp_set_current_user( $this->admin_id );
	}

	/** 每個測試後清空持有表與通行證 CPT */
	public function tear_down(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Gate::flush_cache();

		parent::tear_down();
	}

	// ========== Fixture Helper ==========

	/**
	 * 建立通行證 CPT
	 *
	 * @param array<string, mixed> $args scope_type / limit_type / limit_value / limit_unit / status
	 * @return int pass_id
	 */
	private function create_access_pass( array $args = [] ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => $args['name'] ?? '測試通行證',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', $args['scope_type'] ?? 'all' );
		\update_post_meta( $pass_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', $args['status'] ?? 'active' );

		if ( isset( $args['limit_value'] ) ) {
			\update_post_meta( $pass_id, 'limit_value', $args['limit_value'] );
		}
		if ( isset( $args['limit_unit'] ) ) {
			\update_post_meta( $pass_id, 'limit_unit', $args['limit_unit'] );
		}

		return $pass_id;
	}

	/**
	 * 讀取指定 (user, pass) 的持有列
	 *
	 * @param int $user_id 學員 ID
	 * @param int $pass_id 通行證 ID
	 * @return object|null
	 */
	private function get_row( int $user_id, int $pass_id ): ?object {
		foreach ( Repository::find_by_user( $user_id ) as $row ) {
			if ( (int) $row->pass_id === $pass_id ) {
				return $row;
			}
		}
		return null;
	}

	// ========== Smoke ==========

	/**
	 * @test
	 * @group smoke
	 * Rule: 手動發放 / 撤銷 / 前置驗證三個方法都存在
	 */
	public function test_grant_手動發放相關方法存在(): void {
		$this->assertTrue( \method_exists( Grant::class, 'grant_manually' ), 'Grant::grant_manually 不存在' );
		$this->assertTrue( \method_exists( Grant::class, 'revoke' ), 'Grant::revoke 不存在' );
		$this->assertTrue( \method_exists( Grant::class, 'assert_grantable' ), 'Grant::assert_grantable 不存在' );
	}

	// ========== Happy：到期日依 limit_type 自動計算 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: unlimited 通行證手動發放後永久有效（expire_date='0'）
	 */
	public function test_手動發放_unlimited_到期日為0且可觀看(): void {
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'unlimited' ] );

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$row = $this->get_row( $this->alice_id, $pass_id );
		$this->assertNotNull( $row, '應建立持有列' );
		$this->assertSame( '0', (string) $row->expire_date, 'unlimited 的 expire_date 應為 0' );
		$this->assertTrue(
			CourseUtils::is_avl( $this->course_id, $this->alice_id ),
			'發放後應立即可觀看範圍內課程'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: fixed 通行證的到期日 = 發放當下 + limit_value 個 limit_unit
	 */
	public function test_手動發放_fixed_到期日為發放當下起算N天(): void {
		$pass_id = $this->create_access_pass(
			[
				'limit_type'  => 'fixed',
				'limit_value' => 30,
				'limit_unit'  => 'day',
			]
		);

		$before = \time();
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
		$after = \time();

		$row       = $this->get_row( $this->alice_id, $pass_id );
		$timestamp = (int) $row->expire_date;

		$this->assertGreaterThanOrEqual( $before + ( 30 * DAY_IN_SECONDS ) - 5, $timestamp, '到期日不應早於發放當下 +30 天' );
		$this->assertLessThanOrEqual( $after + ( 30 * DAY_IN_SECONDS ) + 5, $timestamp, '到期日不應晚於發放當下 +30 天' );
		$this->assertTrue( CourseUtils::is_avl( $this->course_id, $this->alice_id ), '未到期應可觀看' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: assigned 通行證直接採用 limit_value 這個絕對 timestamp（不經 strtotime）
	 */
	public function test_手動發放_assigned_到期日等於指定的絕對timestamp(): void {
		$target  = \time() + ( 10 * DAY_IN_SECONDS );
		$pass_id = $this->create_access_pass(
			[
				'limit_type'  => 'assigned',
				'limit_value' => $target,
				'limit_unit'  => 'timestamp',
			]
		);

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$row = $this->get_row( $this->alice_id, $pass_id );
		$this->assertSame( $target, (int) $row->expire_date, 'assigned 應原樣採用 limit_value' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 手動發放會記錄發放者，且不寫入 source_order_id（用於區分來源）
	 */
	public function test_手動發放_記錄發放者且無來源訂單(): void {
		$pass_id = $this->create_access_pass();

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$row = $this->get_row( $this->alice_id, $pass_id );
		$this->assertSame( $this->admin_id, (int) $row->granted_by, 'granted_by 應記錄操作的管理員' );
		$this->assertEmpty( $row->source_order_id, '手動發放不應有來源訂單' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 重複發放同一張通行證走 upsert，不會產生第二列
	 */
	public function test_重複手動發放_不產生重複列(): void {
		$pass_id = $this->create_access_pass();

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$rows = \array_filter(
			Repository::find_by_user( $this->alice_id ),
			static fn( object $row ): bool => (int) $row->pass_id === $pass_id
		);

		$this->assertCount( 1, $rows, '同一 (user, pass) 應只有一列' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 手動發放**不會**把課程 id 展開寫進 avl_course_ids（compute-on-read 的前提）
	 */
	public function test_手動發放_不寫入avl_course_ids(): void {
		$pass_id = $this->create_access_pass();

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$avl_course_ids = \get_user_meta( $this->alice_id, 'avl_course_ids', false );
		$this->assertNotContains(
			(string) $this->course_id,
			\array_map( 'strval', $avl_course_ids ),
			'通行證是 compute-on-read，不得 materialize 課程 id'
		);
	}

	// ========== Error：前置驗證 ==========

	/**
	 * @test
	 * @group error
	 * Rule: 跟隨訂閱的通行證無法手動發放
	 *
	 * 若放行，calc_expire_date 會回 '0'，而 Gate 對 follow_subscription 走
	 * is_subscription_valid('0') 必然回 false → 發了一筆永遠無效的假資料。
	 */
	public function test_手動發放_follow_subscription_應拒絕(): void {
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'follow_subscription' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '跟隨訂閱的課程權限包無法手動發放，請改由訂閱商品開通' );

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 已停用的通行證不可再產生新的持有關係（比照「不可掛載到新商品」）
	 */
	public function test_手動發放_已停用通行證應拒絕(): void {
		$pass_id = $this->create_access_pass( [ 'status' => 'disabled' ] );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '已停用的課程權限包不可手動發放' );

		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 通行證不存在時拒絕
	 */
	public function test_手動發放_通行證不存在應拒絕(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '課程權限包不存在' );

		Grant::grant_manually( $this->alice_id, 999999, $this->admin_id );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 使用者不存在時拒絕
	 */
	public function test_手動發放_使用者不存在應拒絕(): void {
		$pass_id = $this->create_access_pass();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( '使用者不存在' );

		Grant::grant_manually( 999999, $pass_id, $this->admin_id );
	}

	// ========== 撤銷 ==========

	/**
	 * @test
	 * @group happy
	 * Rule: 撤銷後持有列消失，且同一 request 內觀看判定立即失效
	 */
	public function test_撤銷_持有列消失且立即失去觀看權(): void {
		$pass_id = $this->create_access_pass();
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
		$this->assertTrue( CourseUtils::is_avl( $this->course_id, $this->alice_id ), '前置：應可觀看' );

		$revoked = Grant::revoke( $this->alice_id, $pass_id );

		$this->assertTrue( $revoked, 'revoke 應回報有刪到列' );
		$this->assertNull( $this->get_row( $this->alice_id, $pass_id ), '持有列應已刪除' );
		$this->assertFalse(
			CourseUtils::is_avl( $this->course_id, $this->alice_id ),
			'撤銷後應立即失去觀看權（Gate memoize 必須被失效）'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 撤銷通行證**不得**誤砍學員單獨購買的課程（OR 疊加互不影響）
	 */
	public function test_撤銷_不影響單獨購買的課程(): void {
		$pass_id = $this->create_access_pass();
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		// 另外用逐課綁定的方式給 Alice 一門課
		$solo_course = $this->create_course( [ 'post_title' => '單獨購買的課' ] );
		\add_user_meta( $this->alice_id, 'avl_course_ids', $solo_course );
		Gate::flush_cache();

		Grant::revoke( $this->alice_id, $pass_id );

		$avl_course_ids = \array_map( 'strval', \get_user_meta( $this->alice_id, 'avl_course_ids', false ) );
		$this->assertContains( (string) $solo_course, $avl_course_ids, 'avl_course_ids 不得被動到' );
		$this->assertTrue(
			CourseUtils::is_avl( $solo_course, $this->alice_id ),
			'單獨購買的課程在撤銷通行證後仍應可觀看'
		);
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 撤銷某位學員不影響持有同一張通行證的其他學員
	 */
	public function test_撤銷_不影響其他持有者(): void {
		$pass_id = $this->create_access_pass();
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );
		Grant::grant_manually( $this->bob_id, $pass_id, $this->admin_id );

		Grant::revoke( $this->alice_id, $pass_id );

		$this->assertNull( $this->get_row( $this->alice_id, $pass_id ), 'Alice 應被撤銷' );
		$this->assertNotNull( $this->get_row( $this->bob_id, $pass_id ), 'Bob 的持有關係應保留' );
		$this->assertTrue( CourseUtils::is_avl( $this->course_id, $this->bob_id ), 'Bob 仍應可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 撤銷「本來就沒持有」的組合是冪等的，回 false 而非丟錯
	 */
	public function test_撤銷_未持有時回false(): void {
		$pass_id = $this->create_access_pass();

		$this->assertFalse( Grant::revoke( $this->alice_id, $pass_id ), '未持有時應回 false' );
	}

	// ========== REST 層 ==========

	/**
	 * 打 POST /access-passes/{id}/grant
	 *
	 * 直呼 callback（本專案 REST 測試的主流寫法，避免 rest_api_init 之外註冊路由的 _doing_it_wrong）。
	 *
	 * @param int              $pass_id  通行證 ID
	 * @param array<int>|mixed $user_ids 學員 ID 陣列
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch_grant( int $pass_id, $user_ids ) {
		$request = new \WP_REST_Request( 'POST', "/power-course/access-passes/{$pass_id}/grant" );
		$request->set_url_params( [ 'id' => (string) $pass_id ] );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'user_ids' => $user_ids ] ) );

		return Api::instance()->post_access_passes_with_id_grant_callback( $request );
	}

	/**
	 * 打 POST /access-passes/{id}/revoke
	 *
	 * @param int              $pass_id  通行證 ID
	 * @param array<int>|mixed $user_ids 學員 ID 陣列
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch_revoke( int $pass_id, $user_ids ) {
		$request = new \WP_REST_Request( 'POST', "/power-course/access-passes/{$pass_id}/revoke" );
		$request->set_url_params( [ 'id' => (string) $pass_id ] );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( [ 'user_ids' => $user_ids ] ) );

		return Api::instance()->post_access_passes_with_id_revoke_callback( $request );
	}

	/**
	 * 取出 HTTP 狀態碼
	 *
	 * @param \WP_REST_Response|\WP_Error $response callback 回傳值
	 * @return int
	 */
	private function status_of( $response ): int {
		if ( \is_wp_error( $response ) ) {
			$data = $response->get_error_data();
			return (int) ( $data['status'] ?? 500 );
		}
		return (int) $response->get_status();
	}

	/**
	 * @test
	 * @group happy
	 * Rule: REST 批次發放成功，回報 granted_ids 與筆數
	 */
	public function test_rest_批次發放成功(): void {
		$pass_id = $this->create_access_pass();

		$response = $this->dispatch_grant( $pass_id, [ $this->alice_id, $this->bob_id ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $this->status_of( $response ) );
		$this->assertSame( 2, $data['granted_count'], '應成功發放 2 筆' );
		$this->assertEmpty( $data['failed'], '不應有失敗項' );
		$this->assertNotNull( $this->get_row( $this->alice_id, $pass_id ) );
		$this->assertNotNull( $this->get_row( $this->bob_id, $pass_id ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: user_ids 為空時回 400
	 */
	public function test_rest_發放_user_ids為空回400(): void {
		$pass_id = $this->create_access_pass();

		$this->assertSame( 400, $this->status_of( $this->dispatch_grant( $pass_id, [] ) ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 通行證不存在回 404
	 */
	public function test_rest_發放_通行證不存在回404(): void {
		$this->assertSame( 404, $this->status_of( $this->dispatch_grant( 999999, [ $this->alice_id ] ) ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 已停用的通行證回 403（訊息含「停用」→ to_wp_error 對映 403）
	 */
	public function test_rest_發放_已停用回403(): void {
		$pass_id = $this->create_access_pass( [ 'status' => 'disabled' ] );

		$this->assertSame( 403, $this->status_of( $this->dispatch_grant( $pass_id, [ $this->alice_id ] ) ) );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 跟隨訂閱的通行證回 400（參數性錯誤：這張通行證不適合手動發放）
	 */
	public function test_rest_發放_follow_subscription回400(): void {
		$pass_id = $this->create_access_pass( [ 'limit_type' => 'follow_subscription' ] );

		$this->assertSame( 400, $this->status_of( $this->dispatch_grant( $pass_id, [ $this->alice_id ] ) ) );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 批次中夾雜不存在的使用者時，其他人照樣發放成功，失敗者記入 failed
	 */
	public function test_rest_發放_部分失敗不中斷其他人(): void {
		$pass_id = $this->create_access_pass();

		$response = $this->dispatch_grant( $pass_id, [ $this->alice_id, 999999 ] );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['granted_count'], '有效使用者仍應發放成功' );
		$this->assertCount( 1, $data['failed'], '不存在的使用者應記入 failed' );
		$this->assertNotNull( $this->get_row( $this->alice_id, $pass_id ) );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: REST 批次撤銷成功；未持有者記入 not_found_ids 而非報錯
	 */
	public function test_rest_批次撤銷(): void {
		$pass_id = $this->create_access_pass();
		Grant::grant_manually( $this->alice_id, $pass_id, $this->admin_id );

		$response = $this->dispatch_revoke( $pass_id, [ $this->alice_id, $this->bob_id ] );
		$data     = $response->get_data();

		$this->assertSame( 200, $this->status_of( $response ) );
		$this->assertSame( 1, $data['revoked_count'], '只有 Alice 持有，應撤銷 1 筆' );
		$this->assertSame( [ $this->bob_id ], $data['not_found_ids'], 'Bob 未持有，應記入 not_found_ids' );
		$this->assertNull( $this->get_row( $this->alice_id, $pass_id ) );
	}
}
