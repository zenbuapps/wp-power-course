<?php
/**
 * 建立課程權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/建立課程權限包.feature
 * Issue #252：課程通行證（Access Pass）CRUD API
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Crud 類別尚未實作
 *   - 建立 / 驗證邏輯尚未存在
 *
 * @group access-pass
 * @group create
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Service\Crud;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class CreateAccessPassTest
 * 驗證課程權限包的建立業務邏輯（對映 建立課程權限包.feature 的所有 Rule/Example）
 */
class CreateAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 課程 ID（100 HTML 入門課）*/
	private int $course_100;

	/** @var int 課程 ID（101 HTML 進階課）*/
	private int $course_101;

	/** @var int 課程分類 term_id（10 HTML）*/
	private int $term_10;

	/**
	 * 初始化依賴（Crud 使用靜態方法，不需注入）
	 */
	protected function configure_dependencies(): void {
		// Crud::class 尚未實作，Red 階段不初始化
	}

	/**
	 * 每個測試前建立 Background fixture
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立管理員
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_' . uniqid(),
				'user_email' => 'admin_' . uniqid() . '@test.com',
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;

		// Background：設定當前用戶為管理員（REST API 權限需要）
		\wp_set_current_user( $this->admin_id );

		// Background：建立課程（WooCommerce product, _is_course=yes）
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		$this->ids['course_100'] = $this->course_100;

		$this->course_101 = $this->create_course( [ 'post_title' => 'HTML 進階課' ] );
		$this->ids['course_101'] = $this->course_101;

		// Background：建立分類 term（HTML, product_cat, id=10 由工廠決定）
		$result = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_10 = \is_wp_error( $result ) ? 0 : (int) $result['term_id'];
		$this->ids['term_10'] = $this->term_10;
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT
	 */
	public function tear_down(): void {
		// 刪除所有 pc_access_pass 文章（WP_UnitTestCase 會回滾 wp_posts，此處保險清理）
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
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Crud 類別存在（Red：預期失敗，類別尚未建立）
	 */
	public function test_crud_類別存在(): void {
		$this->assertTrue(
			\class_exists( Crud::class ),
			'J7\PowerCourse\Resources\AccessPass\Service\Crud 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Crud::create 方法存在（Red：預期失敗）
	 */
	public function test_crud_create_方法存在(): void {
		$this->assertTrue(
			\method_exists( Crud::class, 'create' ),
			'Crud::create 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- name 不可為空
	 *
	 * Example: 未提供權限包名稱時建立失敗
	 *   When 管理員 "Admin" 建立課程權限包，name 為空
	 *   Then 操作失敗，錯誤訊息包含 "name"
	 */
	public function test_name_為空時建立失敗(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/name/i' );

		Crud::create(
			[
				'name'       => '',
				'scope_type' => 'all',
				'limit_type' => 'unlimited',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- scope_type 必須為 all、category、specific 三者之一
	 *
	 * Example: scope_type 為空時建立失敗
	 */
	public function test_scope_type_為空時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => '測試權限',
				'scope_type' => '',
				'limit_type' => 'unlimited',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- scope_type 必須為 all、category、specific 三者之一
	 *
	 * Example: scope_type 為 "invalid" 時建立失敗
	 */
	public function test_scope_type_為invalid時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => '測試權限',
				'scope_type' => 'invalid',
				'limit_type' => 'unlimited',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 必須為 unlimited、fixed、assigned、follow_subscription 四者之一
	 *
	 * Example: limit_type 不合法時建立失敗
	 */
	public function test_limit_type_不合法時建立失敗(): void {
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessageMatches( '/limit_type/i' );

		Crud::create(
			[
				'name'       => '測試權限',
				'scope_type' => 'all',
				'limit_type' => 'invalid',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 必須為四態之一（舊值 permanent 已不再合法）
	 *
	 * Example: 使用已淘汰的舊值 permanent 時建立失敗（契約遷移：permanent → unlimited）
	 */
	public function test_limit_type_使用舊值permanent時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => '測試權限',
				'scope_type' => 'all',
				'limit_type' => 'permanent',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- scope_type 為 category 時，term_ids 至少需指定一個
	 *
	 * Example: category 範圍未指定任何 term 時建立失敗
	 */
	public function test_category範圍未指定term_ids時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => 'HTML 系列權限',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [],
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- scope_type 為 specific 時，course_ids 至少需指定一門課程
	 *
	 * Example: specific 範圍未指定任何課程時建立失敗
	 */
	public function test_specific範圍未指定course_ids時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => '特選包',
				'scope_type' => 'specific',
				'limit_type' => 'unlimited',
				'course_ids' => [],
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 為 fixed 時，limit_value 必須為正整數且 limit_unit 必填
	 *
	 * Example: fixed 模式未提供 limit_value 時建立失敗
	 */
	public function test_fixed模式未提供limit_value時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'       => '限時 30 天',
				'scope_type' => 'all',
				'limit_type' => 'fixed',
				'limit_unit' => 'day',
				// 故意不提供 limit_value
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_value 必須為正整數
	 *
	 * Example: fixed 模式 limit_value 為 0 時建立失敗
	 */
	public function test_fixed模式limit_value為0時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'        => '限時權限',
				'scope_type'  => 'all',
				'limit_type'  => 'fixed',
				'limit_value' => 0,
				'limit_unit'  => 'day',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 為 fixed 時，limit_unit 必須為 day|month|year
	 *
	 * Example: fixed 模式 limit_unit 不合法（timestamp）時建立失敗
	 */
	public function test_fixed模式limit_unit不合法時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'        => '限時權限',
				'scope_type'  => 'all',
				'limit_type'  => 'fixed',
				'limit_value' => 30,
				'limit_unit'  => 'timestamp', // fixed 不允許 timestamp
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- limit_type 為 assigned 時，limit_value 必須為有效 10 位 Unix timestamp
	 *
	 * Example: assigned 模式 limit_value 為負數時建立失敗
	 */
	public function test_assigned模式limit_value為負數時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'        => '指定日期包',
				'scope_type'  => 'all',
				'limit_type'  => 'assigned',
				'limit_value' => -1,
				'limit_unit'  => 'timestamp',
			]
		);
	}

	/**
	 * @test
	 * @group error
	 * Rule: 前置（參數）- assigned 的 limit_value 須落在 10 位 timestamp 區間
	 *
	 * Example: assigned 模式 limit_value 超出範圍（毫秒級 13 位）時建立失敗
	 */
	public function test_assigned模式limit_value超出範圍時建立失敗(): void {
		$this->expectException( \RuntimeException::class );

		Crud::create(
			[
				'name'        => '指定日期包',
				'scope_type'  => 'all',
				'limit_type'  => 'assigned',
				'limit_value' => 1893456000000, // 13 位毫秒值，超出 10 位上界
				'limit_unit'  => 'timestamp',
			]
		);
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功建立全站範圍永久權限包
	 *
	 * Example: 成功建立全站永久權限包
	 *   When 管理員 "Admin" 建立全站永久權限包
	 *   Then scope_type=all, limit_type=unlimited, status=active
	 */
	public function test_成功建立全站永久權限包(): void {
		$pass_id = Crud::create(
			[
				'name'       => '全站課程權限',
				'scope_type' => 'all',
				'limit_type' => 'unlimited',
			]
		);

		// Then：應成功回傳 post ID
		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );

		// Then：CPT 應存在
		$post = \get_post( $pass_id );
		$this->assertInstanceOf( \WP_Post::class, $post, '應可取得 WP_Post' );
		$this->assertSame( CPT::POST_TYPE, $post->post_type, 'post_type 應為 pc_access_pass' );

		// Then：meta 值應正確
		$this->assertSame( 'all', \get_post_meta( $pass_id, 'scope_type', true ), 'scope_type 應為 all' );
		$this->assertSame( 'unlimited', \get_post_meta( $pass_id, 'limit_type', true ), 'limit_type 應為 unlimited' );
		$this->assertSame( 'active', \get_post_meta( $pass_id, 'access_pass_status', true ), 'status 應為 active' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功建立分類標籤範圍權限包，term_ids 取聯集
	 *
	 * Example: 成功建立 HTML 分類權限包（term_ids=[10]）
	 */
	public function test_成功建立category範圍權限包(): void {
		$pass_id = Crud::create(
			[
				'name'       => 'HTML 入門課程權限',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [ $this->term_10 ],
			]
		);

		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );
		$this->assertSame( 'category', \get_post_meta( $pass_id, 'scope_type', true ), 'scope_type 應為 category' );

		// Then：term_ids 應正確儲存（multi-value postmeta）
		$stored_term_ids = \get_post_meta( $pass_id, 'scope_term_ids', false );
		$this->assertContains( $this->term_10, \array_map( 'intval', $stored_term_ids ), 'term_id 10 應存在' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功建立特定課程範圍權限包，course_ids 為固定清單
	 *
	 * Example: 成功建立特定課程權限包（course_ids=[100, 101]）
	 */
	public function test_成功建立specific範圍權限包(): void {
		$pass_id = Crud::create(
			[
				'name'       => 'HTML 進階特別課程權限',
				'scope_type' => 'specific',
				'limit_type' => 'unlimited',
				'course_ids' => [ $this->course_100, $this->course_101 ],
			]
		);

		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );
		$this->assertSame( 'specific', \get_post_meta( $pass_id, 'scope_type', true ), 'scope_type 應為 specific' );

		// Then：course_ids 應正確儲存（multi-value postmeta）
		$stored_course_ids = \get_post_meta( $pass_id, 'scope_course_ids', false );
		$stored_int        = \array_map( 'intval', $stored_course_ids );
		$this->assertContains( $this->course_100, $stored_int, 'course_100 應存在' );
		$this->assertContains( $this->course_101, $stored_int, 'course_101 應存在' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功建立固定期限 N 天權限包，記錄 limit_value 與 limit_unit
	 *
	 * Example: 成功建立固定期限 30 天權限包（fixed）
	 */
	public function test_成功建立fixed_30天權限包(): void {
		$pass_id = Crud::create(
			[
				'name'        => '限時 30 天',
				'scope_type'  => 'all',
				'limit_type'  => 'fixed',
				'limit_value' => 30,
				'limit_unit'  => 'day',
			]
		);

		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );
		$this->assertSame( 'fixed', \get_post_meta( $pass_id, 'limit_type', true ), 'limit_type 應為 fixed' );
		$this->assertSame( '30', (string) \get_post_meta( $pass_id, 'limit_value', true ), 'limit_value 應為 30' );
		$this->assertSame( 'day', \get_post_meta( $pass_id, 'limit_unit', true ), 'limit_unit 應為 day' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 成功建立指定日期到期權限包（assigned），limit_value 為絕對 timestamp、limit_unit=timestamp
	 *
	 * Example: 成功建立指定日期（2030-01-01）到期權限包
	 */
	public function test_成功建立assigned指定日期權限包(): void {
		$assigned_ts = \strtotime( '2030-01-01 00:00:00' );

		$pass_id = Crud::create(
			[
				'name'        => '指定日期包',
				'scope_type'  => 'all',
				'limit_type'  => 'assigned',
				'limit_value' => $assigned_ts,
				'limit_unit'  => 'timestamp',
			]
		);

		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );
		$this->assertSame( 'assigned', \get_post_meta( $pass_id, 'limit_type', true ), 'limit_type 應為 assigned' );
		$this->assertSame( (string) $assigned_ts, (string) \get_post_meta( $pass_id, 'limit_value', true ), 'limit_value 應為指定的絕對 timestamp' );
		$this->assertSame( 'timestamp', \get_post_meta( $pass_id, 'limit_unit', true ), 'limit_unit 應為 timestamp' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- assigned 模式即使未帶 limit_unit，Service 也固定寫入 timestamp
	 *
	 * Example: assigned 模式僅帶合法 timestamp（不帶 limit_unit）仍成功，limit_unit 自動為 timestamp
	 */
	public function test_assigned模式未帶limit_unit時自動補timestamp(): void {
		$assigned_ts = \strtotime( '2031-06-15 12:00:00' );

		$pass_id = Crud::create(
			[
				'name'        => '指定日期包（無 unit）',
				'scope_type'  => 'all',
				'limit_type'  => 'assigned',
				'limit_value' => $assigned_ts,
				// 故意不帶 limit_unit
			]
		);

		$this->assertGreaterThan( 0, $pass_id, '應回傳有效 post ID' );
		$this->assertSame( 'timestamp', \get_post_meta( $pass_id, 'limit_unit', true ), 'assigned 模式 limit_unit 應固定為 timestamp' );
	}
}
