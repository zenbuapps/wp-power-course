<?php
/**
 * 刪除課程權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/刪除課程權限包.feature
 * Issue #252：課程通行證（Access Pass）— 刪除收回
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Crud::delete 方法尚未實作
 *   - 刪除後收回 pc_user_access_pass 表中對應列的邏輯未存在
 *   - 刪除後觀看判定（Gate）無法感知已刪除
 *
 * @group access-pass
 * @group delete
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class DeleteAccessPassTest
 * 驗證刪除課程權限包（對映 刪除課程權限包.feature 的所有 Rule/Example）
 *
 * 刪除 = 真正收回已購用戶觀看權限（對照停用：停用保留已購用戶觀看權限）
 * 刪除需要二次確認旗標（confirm=true）
 * 刪除不可影響 avl_course_ids（OR 疊加，互不影響）
 */
class DeleteAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 學員 Student ID */
	private int $student_id;

	/** @var int 課程 100（HTML 入門課）*/
	private int $course_100;

	/** @var int 課程 200（PHP 基礎課）*/
	private int $course_200;

	/** @var int HTML 課程分類 term_id */
	private int $term_10;

	/** @var int 課程權限包 301（HTML category 永久包）*/
	private int $pass_301;

	/**
	 * 初始化依賴（Crud::delete 尚未實作）
	 */
	protected function configure_dependencies(): void {
		// Crud::delete 尚未實作，Red 階段不初始化
	}

	/**
	 * 每個測試前建立 Background fixture
	 *
	 * Background（來自 feature）：
	 *   - Admin（administrator）、Student（subscriber）
	 *   - course_100（HTML 入門課）、course_200（PHP 基礎課）
	 *   - pass_301（HTML category 永久包，scope_type=category, term_ids=[10]）
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立管理員
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_del_' . uniqid(),
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;

		// Background：建立學員
		$this->student_id = $this->factory()->user->create(
			[
				'user_login' => 'student_del_' . uniqid(),
				'user_email' => 'student_del_' . uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->ids['Student'] = $this->student_id;

		\wp_set_current_user( $this->admin_id );

		// Background：建立課程分類
		$html_result   = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_10 = \is_wp_error( $html_result ) ? 0 : (int) $html_result['term_id'];

		// Background：建立課程 100（HTML 入門課，掛 term_10）
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		\wp_set_object_terms( $this->course_100, $this->term_10, 'product_cat' );
		$this->ids['course_100'] = $this->course_100;

		// Background：建立課程 200（PHP 基礎課，無分類）
		$this->course_200 = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->ids['course_200'] = $this->course_200;

		// Background：建立權限包 301（HTML category 永久包）
		$this->pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => 'HTML 權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_301, 'scope_type', 'category' );
		\update_post_meta( $this->pass_301, 'limit_mode', 'permanent' );
		\update_post_meta( $this->pass_301, 'access_pass_status', 'active' );
		\add_post_meta( $this->pass_301, 'scope_term_ids', $this->term_10, false );
		$this->ids['pass_301'] = $this->pass_301;
	}

	/**
	 * 每個測試後清理 CPT 與自訂表
	 */
	public function tear_down(): void {
		// 清除 pc_access_pass CPT（含已被 delete 刪除的）
		$passes = \get_posts(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_status' => 'any',
				'numberposts' => -1,
				'fields'      => 'ids',
			]
		);
		foreach ( $passes as $pass_id ) {
			\wp_force_delete_post( $pass_id );
		}

		// 清除 pc_user_access_pass 表
		global $wpdb;
		$table = $wpdb->prefix . 'pc_user_access_pass';
		$wpdb->query( "DELETE FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Crud::delete 方法存在（Red：預期失敗，尚未實作）
	 */
	public function test_crud_delete_方法存在(): void {
		$this->assertTrue(
			\method_exists( Crud::class, 'delete' ),
			'Crud::delete 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 被刪除的權限包必須存在
	 *
	 * Example: 刪除不存在的權限包時操作失敗（passId=9999）
	 *   When 管理員 "Admin" 刪除課程權限包 9999，確認旗標為 true
	 *   Then 操作失敗
	 */
	public function test_刪除不存在的權限包失敗(): void {
		$this->expectException( \RuntimeException::class );

		// When：刪除不存在的 pass_id=9999
		Crud::delete( 9999, true );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- 刪除需帶二次確認旗標
	 *
	 * Example: 未帶確認旗標刪除時操作失敗
	 *   When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 false
	 *   Then 操作失敗
	 */
	public function test_未帶確認旗標刪除失敗(): void {
		$this->expectException( \RuntimeException::class );

		// When：confirm=false
		Crud::delete( $this->pass_301, false );
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 確認後成功刪除權限包
	 *
	 * Example: 帶確認旗標成功刪除權限包
	 *   When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
	 *   Then 操作成功
	 *   And 權限包 301 應不存在
	 */
	public function test_帶確認旗標成功刪除(): void {
		// When：confirm=true，刪除 pass_301
		Crud::delete( $this->pass_301, true );

		// Then：pass_301 應不再存在（post 已刪除）
		$post = \get_post( $this->pass_301 );
		$this->assertTrue(
			$post === null || $post->post_status === 'trash',
			"權限包 {$this->pass_301} 應已被刪除（post 不存在或在垃圾桶）"
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 刪除後已購用戶失去該權限包涵蓋的觀看權
	 *
	 * Example: 刪除權限包後已購學員失去範圍內課程觀看權
	 *   Given 學員 "Student" 已透過權限包 301 取得課程 100 的觀看權限
	 *   When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
	 *   Then 操作成功
	 *   And 學員 "Student" 對課程 100 的觀看權限應為「不可觀看」
	 */
	public function test_刪除後已購學員失去觀看權(): void {
		// Given：Student 持有 pass_301（pc_user_access_pass 表）
		Repository::insert_or_update( $this->student_id, $this->pass_301, null, null );

		// 確認刪除前 Student 可觀看 course_100（透過 pass_301）
		$before = Gate::user_has_valid_pass_for_course( $this->student_id, $this->course_100 );
		$this->assertTrue( $before, '前置確認：刪除前 Student 應可觀看 course_100' );

		// When：刪除 pass_301
		Crud::delete( $this->pass_301, true );

		// Then：Student 對課程 100 的觀看權限應為「不可觀看」
		$after = Gate::user_has_valid_pass_for_course( $this->student_id, $this->course_100 );
		$this->assertFalse( $after, '刪除權限包後已購學員應失去範圍內課程觀看權' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 刪除後 pc_user_access_pass 表中的持有列也應被刪除
	 *
	 * 驗證 Crud::delete 串接 Repository::delete_by_pass
	 */
	public function test_刪除後持有列也被清除(): void {
		// Given：Student 持有 pass_301
		Repository::insert_or_update( $this->student_id, $this->pass_301, null, null );
		$rows_before = Repository::find_by_user( $this->student_id );
		$this->assertNotEmpty( $rows_before, '前置確認：Student 應有持有紀錄' );

		// When：刪除 pass_301
		Crud::delete( $this->pass_301, true );

		// Then：pc_user_access_pass 中 pass_301 的持有列應被清除
		$rows_after = Repository::find_by_user( $this->student_id );
		$pass_ids   = \array_map( fn( $r ) => (int) $r->pass_id, $rows_after );
		$this->assertNotContains(
			$this->pass_301,
			$pass_ids,
			'刪除權限包後 pc_user_access_pass 中的持有列應被清除'
		);
	}

	// ========== 邊緣案例（Edge Tests）==========

	/**
	 * @test
	 * @group edge
	 * Rule: 後置（狀態）- 刪除權限包不影響使用者單獨購買的課程權限（avl_course_ids）
	 *
	 * Example: 刪除分類權限包後，單獨購買的課程仍可觀看
	 *   Given 學員 "Student" 已單獨購買課程 200（寫入 avl_course_ids）
	 *   And 學員 "Student" 已透過權限包 301 取得課程 100 的觀看權限
	 *   When 管理員 "Admin" 刪除課程權限包 301，確認旗標為 true
	 *   Then 操作成功
	 *   And 學員 "Student" 對課程 200 的觀看權限應為「可觀看」
	 *
	 * 關鍵業務規則：刪除 pass_301（category 包）不能誤砍 avl_course_ids 中 course_200 的條目
	 */
	public function test_刪除分類包後單獨購買課程仍可觀看(): void {
		// Given：Student 已單獨購買課程 200（avl_course_ids 含 course_200）
		\add_user_meta( $this->student_id, 'avl_course_ids', $this->course_200, false );

		// And：Student 持有 pass_301（涵蓋 course_100）
		Repository::insert_or_update( $this->student_id, $this->pass_301, null, null );

		// When：刪除 pass_301
		Crud::delete( $this->pass_301, true );

		// Then：Student 對課程 200 仍可觀看（avl_course_ids 未受影響）
		$result = CourseUtils::is_avl( $this->course_200, $this->student_id );
		$this->assertTrue(
			$result,
			'刪除分類包後，單獨購買（avl_course_ids）的課程應仍可觀看，不可誤砍'
		);
	}

	/**
	 * @test
	 * @group edge
	 * 刪除後 pass_id 所有持有列均被清除（不僅限單一用戶）
	 *
	 * 驗證多個學員持有同一 pass 時，刪除後全部持有列都被清除
	 */
	public function test_刪除後所有持有列均被清除(): void {
		// Given：建立另一個學員並讓他也持有 pass_301
		$another_student = $this->factory()->user->create(
			[
				'user_login' => 'student2_del_' . uniqid(),
				'role'       => 'subscriber',
			]
		);
		Repository::insert_or_update( $this->student_id, $this->pass_301, null, null );
		Repository::insert_or_update( $another_student, $this->pass_301, null, null );

		// When：刪除 pass_301
		Crud::delete( $this->pass_301, true );

		// Then：兩位學員的 pass_301 持有列均被清除
		$rows_student1 = Repository::find_by_user( $this->student_id );
		$rows_student2 = Repository::find_by_user( $another_student );

		$pass_ids_1 = \array_map( fn( $r ) => (int) $r->pass_id, $rows_student1 );
		$pass_ids_2 = \array_map( fn( $r ) => (int) $r->pass_id, $rows_student2 );

		$this->assertNotContains( $this->pass_301, $pass_ids_1, '學員1的持有列應被清除' );
		$this->assertNotContains( $this->pass_301, $pass_ids_2, '學員2的持有列應被清除' );
	}

	// ========== REST 層測試（DELETE /power-course/access-passes）==========

	/**
	 * 打 DELETE /power-course/access-passes 端點（JSON body）並回傳 response
	 *
	 * 以真實的 WP_REST_Request（method=DELETE、JSON body）驅動已註冊的 callback
	 * delete_access_passes_callback，覆蓋 REST 接線：JSON body 解析、ids/confirm
	 * 前置驗證、逐筆委派 Crud::delete、回傳契約欄位。
	 *
	 * 採直呼 Api::instance()->callback() 而非 rest_do_request：避免在 PHPUnit 環境下
	 * 於 rest_api_init 之外註冊路由（或連帶觸發 MCP abilities）所引發的 WP _doing_it_wrong
	 * 通知污染 incorrect-usage 斷言。此為本專案既有 REST 測試的主流寫法（如 StudentQuickEditDetailApiTest）。
	 *
	 * @param array<int>|mixed $ids     要刪除的 pass ids（保留 mixed 以測試非陣列輸入）
	 * @param mixed            $confirm 二次確認旗標
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function dispatch_delete( $ids, $confirm ) {
		$request = new \WP_REST_Request( 'DELETE', '/power-course/access-passes' );
		$request->add_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) \wp_json_encode(
				[
					'ids'     => $ids,
					'confirm' => $confirm,
				]
			)
		);

		return \J7\PowerCourse\Resources\AccessPass\Core\Api::instance()->delete_access_passes_callback( $request );
	}

	/**
	 * 從 WP_REST_Response 或 WP_Error 取出 HTTP 狀態碼
	 *
	 * @param \WP_REST_Response|\WP_Error $response callback 回傳值
	 * @return int
	 */
	private function status_of( $response ): int {
		if ( $response instanceof \WP_Error ) {
			$data = $response->get_error_data();
			return \is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		}
		return $response->get_status();
	}

	/**
	 * @test
	 * @group api
	 * @group error
	 * Rule: 前置（參數）- confirm 必須為 true，否則 400
	 *
	 * DELETE /access-passes body confirm=false → 400 ValidationError
	 */
	public function test_rest_confirm_false_回400(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch_delete( [ $this->pass_301 ], false );

		$this->assertInstanceOf( \WP_Error::class, $response, 'confirm=false 應回 WP_Error' );
		$this->assertSame( 400, $this->status_of( $response ), 'confirm=false 應回 400' );

		// 權限包不應被刪除（前置失敗，不執行刪除）
		$post = \get_post( $this->pass_301 );
		$this->assertInstanceOf( \WP_Post::class, $post, 'confirm=false 時權限包不應被刪除' );
	}

	/**
	 * @test
	 * @group api
	 * @group error
	 * Rule: 前置（參數）- ids 不可為空陣列，否則 400
	 *
	 * DELETE /access-passes body ids=[] → 400 ValidationError
	 */
	public function test_rest_ids_空陣列_回400(): void {
		\wp_set_current_user( $this->admin_id );

		$response = $this->dispatch_delete( [], true );

		$this->assertInstanceOf( \WP_Error::class, $response, 'ids 空陣列應回 WP_Error' );
		$this->assertSame( 400, $this->status_of( $response ), 'ids 空陣列應回 400' );
	}

	/**
	 * @test
	 * @group api
	 * @group happy
	 * Rule: 後置（狀態）- confirm=true 成功刪除，回 {success, deleted_ids, affected_user_count}
	 *
	 * DELETE /access-passes body {ids:[301], confirm:true} → 200，pass CPT 已不存在
	 */
	public function test_rest_confirm_true_成功刪除回契約欄位(): void {
		\wp_set_current_user( $this->admin_id );

		// Given：Student 持有 pass_301（驗證 affected_user_count 至少為 1）
		Repository::insert_or_update( $this->student_id, $this->pass_301, null, null );

		$response = $this->dispatch_delete( [ $this->pass_301 ], true );

		// Then：200 且回傳契約欄位（嚴格對齊 api.yml 6328-6345）
		$this->assertInstanceOf( \WP_REST_Response::class, $response, 'confirm=true 應回 WP_REST_Response' );
		$data = $response->get_data();
		$this->assertSame( 200, $response->get_status(), 'confirm=true 應回 200' );
		$this->assertIsArray( $data, 'response data 應為陣列' );
		$this->assertArrayHasKey( 'success', $data, '回傳應含 success' );
		$this->assertArrayHasKey( 'deleted_ids', $data, '回傳應含 deleted_ids' );
		$this->assertArrayHasKey( 'affected_user_count', $data, '回傳應含 affected_user_count' );

		$this->assertTrue( $data['success'], 'success 應為 true' );
		$this->assertContains( $this->pass_301, $data['deleted_ids'], 'deleted_ids 應含 pass_301' );
		$this->assertGreaterThanOrEqual( 1, $data['affected_user_count'], 'affected_user_count 應 >= 1（Student 持有）' );

		// Then：pass CPT 已不存在
		$post = \get_post( $this->pass_301 );
		$this->assertTrue(
			$post === null || $post->post_status === 'trash',
			"權限包 {$this->pass_301} 經 REST 刪除後應不存在"
		);
	}
}
