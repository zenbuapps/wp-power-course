<?php
/**
 * 權限包觀看判定 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/權限包觀看判定.feature
 * Issue #252：課程通行證（Access Pass）— keystone 觀看判定
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Gate 類別尚未實作
 *   - J7\PowerCourse\Utils\Course::is_avl 尚不具備 pass-aware 行為
 *   - pc_user_access_pass 資料表授予邏輯未與觀看判定整合
 *
 * 測試分組：
 *   Smoke   — 確認 Gate 類別與方法存在
 *   Happy   — 各 scope/limit_type 正常觀看路徑
 *   Error   — 未登入、未授予等不可觀看路徑
 *   Edge    — OR 疊加邏輯、動態範圍、訂閱狀態矩陣
 *
 * @group access-pass
 * @group gate
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Gate;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;
use J7\PowerCourse\Utils\Course as CourseUtils;

/**
 * Class AccessPassGateTest
 * 驗證權限包觀看判定（對映 權限包觀看判定.feature 的所有 Rule/Example）
 *
 * 觀看判定呼叫點：
 *   1. Gate::user_has_valid_pass_for_course($user_id, $course_id) — 直接測試 pass 判定
 *   2. CourseUtils::is_avl($course_id, $user_id) — 整合 pass 後的最終判定
 */
class AccessPassGateTest extends TestCase {

	/** @var int 學員 UserA ID */
	private int $user_a_id;

	/** @var int 課程 100（HTML 入門課，掛 term_id=10）*/
	private int $course_100;

	/** @var int 課程 101（前端工程課，掛子分類 term_id=11）*/
	private int $course_101;

	/** @var int 課程 200（PHP 基礎課，無分類）*/
	private int $course_200;

	/** @var int 課程 300（進階架構課，無分類）*/
	private int $course_300;

	/** @var int 分類 term_id（HTML 父分類）*/
	private int $term_10;

	/** @var int 分類 term_id（前端 子分類，parent=10）*/
	private int $term_11;

	/**
	 * 初始化依賴（Gate 尚未實作，Red 階段不需注入）
	 */
	protected function configure_dependencies(): void {
		// Gate::class 尚未實作，Red 階段僅確認類別存在
	}

	/**
	 * 每個測試前建立 Background fixture
	 *
	 * Background（來自 feature）：
	 *   - UserA（subscriber）
	 *   - term 10（HTML，product_cat，parent=0）
	 *   - term 11（前端，product_cat，parent=10）
	 *   - course 100（HTML 入門課，product_cat=[10]）
	 *   - course 101（前端工程課，product_cat=[11]）
	 *   - course 200（PHP 基礎課，無分類）
	 *   - course 300（進階架構課，無分類）
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立 UserA（subscriber）
		$this->user_a_id = $this->factory()->user->create(
			[
				'user_login' => 'user_a_' . uniqid(),
				'user_email' => 'user_a_' . uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->ids['UserA'] = $this->user_a_id;

		// Background：建立課程分類（父：HTML, 子：前端）
		$html_result    = \wp_insert_term( 'HTML', 'product_cat' );
		$this->term_10  = \is_wp_error( $html_result ) ? 0 : (int) $html_result['term_id'];
		$this->ids['term_10'] = $this->term_10;

		$frontend_result = \wp_insert_term( '前端', 'product_cat', [ 'parent' => $this->term_10 ] );
		$this->term_11   = \is_wp_error( $frontend_result ) ? 0 : (int) $frontend_result['term_id'];
		$this->ids['term_11'] = $this->term_11;

		// Background：建立課程 100（HTML 入門課，掛父分類 term_10）
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		\wp_set_object_terms( $this->course_100, $this->term_10, 'product_cat' );
		$this->ids['course_100'] = $this->course_100;

		// Background：建立課程 101（前端工程課，掛子分類 term_11）
		$this->course_101 = $this->create_course( [ 'post_title' => '前端工程課' ] );
		\wp_set_object_terms( $this->course_101, $this->term_11, 'product_cat' );
		$this->ids['course_101'] = $this->course_101;

		// Background：建立課程 200（PHP 基礎課，無分類）
		$this->course_200 = $this->create_course( [ 'post_title' => 'PHP 基礎課' ] );
		$this->ids['course_200'] = $this->course_200;

		// Background：建立課程 300（進階架構課，無分類）
		$this->course_300 = $this->create_course( [ 'post_title' => '進階架構課' ] );
		$this->ids['course_300'] = $this->course_300;

		// 清除 UserA 登入狀態（測試開始時未登入）
		\wp_set_current_user( 0 );
	}

	/**
	 * 每個測試後清理 pc_access_pass CPT 與 pc_user_access_pass 表
	 */
	public function tear_down(): void {
		// 清除 pc_access_pass CPT
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

	// ========== Fixture Helper：建立權限包 CPT ==========

	/**
	 * 建立課程權限包（pc_access_pass CPT）並設定 postmeta
	 *
	 * @param array<string, mixed> $args 設定值（scope_type, limit_type, term_ids, course_ids 等）
	 * @return int pass_id
	 */
	private function create_access_pass( array $args ): int {
		$pass_id = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => $args['name'] ?? '測試權限包',
				'post_status' => 'publish',
			]
		);

		\update_post_meta( $pass_id, 'scope_type', $args['scope_type'] ?? 'all' );
		\update_post_meta( $pass_id, 'limit_type', $args['limit_type'] ?? 'unlimited' );
		\update_post_meta( $pass_id, 'access_pass_status', $args['status'] ?? 'active' );

		// 分類範圍：scope_term_ids（multi-value meta）
		if ( ! empty( $args['term_ids'] ) ) {
			foreach ( (array) $args['term_ids'] as $tid ) {
				\add_post_meta( $pass_id, 'scope_term_ids', $tid, false );
			}
		}

		// 特定課程範圍：scope_course_ids（multi-value meta）
		if ( ! empty( $args['course_ids'] ) ) {
			foreach ( (array) $args['course_ids'] as $cid ) {
				\add_post_meta( $pass_id, 'scope_course_ids', $cid, false );
			}
		}

		return $pass_id;
	}

	/**
	 * 授予用戶持有權限包（直接寫入 pc_user_access_pass）
	 *
	 * @param int         $user_id     學員 ID
	 * @param int         $pass_id     權限包 ID
	 * @param string|null $expire_date 到期表達式（null=永久；timestamp=限時；subscription_X=跟隨訂閱）
	 */
	private function grant_pass_to_user( int $user_id, int $pass_id, ?string $expire_date = null ): void {
		Repository::insert_or_update( $user_id, $pass_id, null, $expire_date );
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Gate 類別存在（Red：預期失敗，類別尚未建立）
	 */
	public function test_gate_類別存在(): void {
		$this->assertTrue(
			\class_exists( Gate::class ),
			'J7\PowerCourse\Resources\AccessPass\Service\Gate 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Gate::user_has_valid_pass_for_course 方法存在（Red：預期失敗）
	 */
	public function test_gate_user_has_valid_pass_for_course_方法存在(): void {
		$this->assertTrue(
			\method_exists( Gate::class, 'user_has_valid_pass_for_course' ),
			'Gate::user_has_valid_pass_for_course 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 未登入使用者一律不可觀看
	 *
	 * Example: 未登入訪客判定為不可觀看
	 *   Given 使用者未登入（user_id=0）
	 *   When 系統判定對課程 100 的觀看權限
	 *   Then 觀看權限應為「不可觀看」
	 */
	public function test_未登入訪客不可觀看(): void {
		// Given：使用者未登入
		\wp_set_current_user( 0 );

		// When：Gate 直接判定 user_id=0
		$result = Gate::user_has_valid_pass_for_course( 0, $this->course_100 );

		// Then：不可觀看
		$this->assertFalse( $result, '未登入 user_id=0 不可觀看任何課程' );
	}

	/**
	 * @test
	 * @group error
	 * 無持有任何權限包時，不可觀看課程
	 */
	public function test_無持有任何權限包時不可觀看(): void {
		// Given：UserA 未持有任何權限包

		// When：判定 UserA 對課程 200 的觀看權限
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：不可觀看
		$this->assertFalse( $result, '無持有任何權限包時不可觀看' );
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（回應）- 持有有效全站永久權限包時，任一課程皆可觀看
	 *
	 * Example: 持有全站永久包可觀看任意課程
	 *   Given 學員 "UserA" 持有全站永久包（passId=300, scope_type=all, limit_type=unlimited）
	 *   When 系統判定學員 "UserA" 對課程 200 的觀看權限
	 *   Then 觀看權限應為「可觀看」
	 */
	public function test_持有全站永久包可觀看任意課程(): void {
		// Given：建立全站永久權限包，並授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => '全站課程權限',
				'scope_type' => 'all',
				'limit_type' => 'unlimited',
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：可觀看
		$this->assertTrue( $result, '持有全站永久包應可觀看任意課程' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 全站包可觀看購買後才新增的課程（動態範圍）
	 *
	 * Example: 持有全站包可觀看購買後才新增的課程
	 *   Given 學員 "UserA" 持有全站永久包
	 *   And 系統新增課程 999（全新上架課）
	 *   When 系統判定學員 "UserA" 對課程 999 的觀看權限
	 *   Then 觀看權限應為「可觀看」
	 */
	public function test_全站包可觀看購買後才新增的課程(): void {
		// Given：建立全站永久包，授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => '全站課程權限',
				'scope_type' => 'all',
				'limit_type' => 'unlimited',
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// And：新增課程 999（全新上架課，購買後才有的）
		$course_999 = $this->create_course( [ 'post_title' => '全新上架課' ] );

		// When：判定 UserA 對新課程 999 的觀看權限
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $course_999 );

		// Then：可觀看（動態範圍，全站包涵蓋日後新增課程）
		$this->assertTrue( $result, '全站包應可觀看購買後才新增的課程（動態範圍）' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（回應）- 持有分類權限包時，僅範圍內課程可觀看
	 *
	 * Example: 持有 HTML 分類包可觀看 HTML 課程（course_100 掛 term_10）
	 */
	public function test_分類包可觀看範圍內課程(): void {
		// Given：建立 HTML 分類包（term_ids=[term_10]），授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => 'HTML 分類包',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [ $this->term_10 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// When：判定 UserA 對課程 100（HTML 入門課，屬 term_10）
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_100 );

		// Then：可觀看
		$this->assertTrue( $result, '持有分類包應可觀看範圍內課程' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 分類範圍涵蓋子分類課程
	 *
	 * Example: 持有父分類 HTML 包可觀看子分類「前端」課程（course_101 掛 term_11，parent=term_10）
	 */
	public function test_父分類包可觀看子分類課程(): void {
		// Given：建立 HTML 分類包（term_ids=[term_10]），授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => 'HTML 父分類包',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [ $this->term_10 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// When：判定 UserA 對課程 101（前端工程課，屬子分類 term_11，parent=term_10）
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_101 );

		// Then：可觀看（父分類包涵蓋子分類）
		$this->assertTrue( $result, '持有父分類包應可觀看子分類課程' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 持有特定課程包時，可觀看清單內課程
	 *
	 * Example: 持有特定課程包可觀看清單內課程（course_ids=[100, 101]）
	 */
	public function test_特定課程包可觀看清單內課程(): void {
		// Given：建立特定課程包（course_ids=[course_100, course_101]），授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => '特定課程包',
				'scope_type' => 'specific',
				'limit_type' => 'unlimited',
				'course_ids' => [ $this->course_100, $this->course_101 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// When：判定 UserA 對課程 100
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_100 );

		// Then：可觀看
		$this->assertTrue( $result, '持有特定課程包應可觀看清單內課程' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 限時權限包未到期時可觀看
	 *
	 * Example: 限時權限包未到期時可觀看
	 *   Given 學員 "UserA" 持有 fixed 包，到期狀態=未到期
	 *   Then 觀看權限應為「可觀看」
	 */
	public function test_限時包未到期可觀看(): void {
		// Given：建立全站限時包，授予 UserA，到期時間設為未來（2030-01-01）
		$pass_id = $this->create_access_pass(
			[
				'name'       => '限時全站包（未到期）',
				'scope_type' => 'all',
				'limit_type' => 'fixed',
			]
		);
		$future_expire = (string) strtotime( '+30 days' );
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $future_expire );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：可觀看（未到期）
		$this->assertTrue( $result, '限時包未到期應可觀看' );
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 後置（回應）- 分類包不涵蓋範圍外課程
	 *
	 * Example: 持有 HTML 分類包不可觀看範圍外的 PHP 課程
	 *   Given 學員 "UserA" 持有 HTML 分類包（term_ids=[10]）
	 *   When 系統判定學員 "UserA" 對課程 200（PHP 基礎課，無分類）
	 *   Then 觀看權限應為「不可觀看」
	 */
	public function test_分類包不可觀看範圍外課程(): void {
		// Given：建立 HTML 分類包，授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => 'HTML 分類包',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [ $this->term_10 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// When：判定 UserA 對課程 200（PHP，無分類）
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：不可觀看
		$this->assertFalse( $result, '分類包不應涵蓋範圍外課程' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 特定課程包不涵蓋日後新增的同分類課程（靜態範圍）
	 *
	 * Example: 特定課程包不涵蓋日後新增的同分類課程
	 *   Given 學員 "UserA" 持有特定包（course_ids=[course_100]）
	 *   And 系統新增課程 998（HTML 新課，同 product_cat=term_10）
	 *   When 系統判定對課程 998 的觀看權限
	 *   Then 觀看權限應為「不可觀看」
	 */
	public function test_特定包不隨新增同分類課程擴張(): void {
		// Given：建立特定包（只含 course_100），授予 UserA
		$pass_id = $this->create_access_pass(
			[
				'name'       => '特定包（靜態範圍）',
				'scope_type' => 'specific',
				'limit_type' => 'unlimited',
				'course_ids' => [ $this->course_100 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// And：日後新增同分類課程 998
		$course_998 = $this->create_course( [ 'post_title' => 'HTML 新課' ] );
		\wp_set_object_terms( $course_998, $this->term_10, 'product_cat' );

		// When：判定 UserA 對新課程 998
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $course_998 );

		// Then：不可觀看（特定包不自動擴張）
		$this->assertFalse( $result, '特定包不應涵蓋日後新增的同分類課程' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 限時權限包已到期時不可觀看
	 *
	 * Example: 限時權限包已到期時不可觀看
	 *   Given 學員 "UserA" 持有 fixed 包，expire_date=過去 timestamp
	 *   Then 觀看權限應為「不可觀看」
	 */
	public function test_限時包已到期不可觀看(): void {
		// Given：建立全站限時包，授予 UserA，到期時間設為過去（2021-01-01）
		$pass_id = $this->create_access_pass(
			[
				'name'       => '限時全站包（已到期）',
				'scope_type' => 'all',
				'limit_type' => 'fixed',
			]
		);
		$past_expire = (string) strtotime( '-30 days' );
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $past_expire );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：不可觀看（已到期）
		$this->assertFalse( $result, '限時包已到期不應可觀看' );
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 指定日期到期（assigned）包，now < 絕對 timestamp 時可觀看
	 *
	 * Example: assigned 包指定的到期日尚未到（未來 timestamp）→ 可觀看
	 */
	public function test_assigned包未到期可觀看(): void {
		// Given：建立全站 assigned 包，expire_date 設為未來絕對 timestamp（2030-01-01）
		$pass_id = $this->create_access_pass(
			[
				'name'       => '指定日期全站包（未到期）',
				'scope_type' => 'all',
				'limit_type' => 'assigned',
			]
		);
		$future_ts = (string) strtotime( '2030-01-01 00:00:00' );
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $future_ts );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：可觀看（指定到期日未到）
		$this->assertTrue( $result, 'assigned 包在指定到期日前應可觀看' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 指定日期到期（assigned）包，now > 絕對 timestamp 時不可觀看
	 *
	 * Example: assigned 包指定的到期日已過（過去 timestamp）→ 不可觀看
	 */
	public function test_assigned包已到期不可觀看(): void {
		// Given：建立全站 assigned 包，expire_date 設為過去絕對 timestamp（2020-01-01）
		$pass_id = $this->create_access_pass(
			[
				'name'       => '指定日期全站包（已到期）',
				'scope_type' => 'all',
				'limit_type' => 'assigned',
			]
		);
		$past_ts = (string) strtotime( '2020-01-01 00:00:00' );
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $past_ts );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：不可觀看（指定到期日已過）
		$this->assertFalse( $result, 'assigned 包在指定到期日後不應可觀看' );
	}

	/**
	 * @test
	 * @group error
	 * Rule: 指定日期到期（assigned）包，expire_date <= 0 時 fail-closed（不可觀看）
	 *
	 * Example: assigned 包持有列 expire_date 異常為 0 → 視為失效（與 fixed 一致的 fail-closed）
	 */
	public function test_assigned包expire為0時fail_closed(): void {
		// Given：建立全站 assigned 包，授予列 expire_date = '0'（異常/未設定）
		$pass_id = $this->create_access_pass(
			[
				'name'       => '指定日期全站包（expire=0）',
				'scope_type' => 'all',
				'limit_type' => 'assigned',
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, '0' );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：不可觀看（fail-closed）
		$this->assertFalse( $result, 'assigned 包 expire_date<=0 應 fail-closed 不可觀看' );
	}

	// ========== 邊緣案例（Edge Tests）==========

	/**
	 * @test
	 * @group edge
	 * Rule: 跟隨訂閱 — 訂閱狀態 active 時可觀看
	 *
	 * Scenario Outline（訂閱狀態=active → 觀看權限=可觀看）
	 */
	public function test_跟隨訂閱active可觀看(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		// Given：建立跟隨訂閱全站包，授予 UserA，expire_date = subscription_{id}（active 訂閱）
		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（active）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		$sub         = \wcs_create_subscription( [ 'status' => 'active' ] );
		$sub_id      = \is_wp_error( $sub ) ? 0 : $sub->get_id();
		$expire_date = 'subscription_' . $sub_id;
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		// When：判定 UserA 對課程 200
		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );

		// Then：可觀看
		$this->assertTrue( $result, '訂閱狀態 active 應可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 跟隨訂閱 — 訂閱狀態 pending-cancel 時仍可觀看
	 *
	 * Scenario Outline（訂閱狀態=pending-cancel → 觀看權限=可觀看）
	 */
	public function test_跟隨訂閱pending_cancel仍可觀看(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（pending-cancel）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		$sub         = \wcs_create_subscription( [ 'status' => 'pending-cancel' ] );
		$sub_id      = \is_wp_error( $sub ) ? 0 : $sub->get_id();
		$expire_date = 'subscription_' . $sub_id;
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );
		$this->assertTrue( $result, '訂閱狀態 pending-cancel 仍應可觀看（付款期未結束）' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 跟隨訂閱 — 訂閱狀態 on-hold 時不可觀看
	 *
	 * Scenario Outline（訂閱狀態=on-hold → 觀看權限=不可觀看）
	 */
	public function test_跟隨訂閱on_hold不可觀看(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（on-hold）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		$sub         = \wcs_create_subscription( [ 'status' => 'on-hold' ] );
		$sub_id      = \is_wp_error( $sub ) ? 0 : $sub->get_id();
		$expire_date = 'subscription_' . $sub_id;
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );
		$this->assertFalse( $result, '訂閱狀態 on-hold 不可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 跟隨訂閱 — 訂閱狀態 cancelled 時不可觀看
	 *
	 * Scenario Outline（訂閱狀態=cancelled → 觀看權限=不可觀看）
	 */
	public function test_跟隨訂閱cancelled不可觀看(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（cancelled）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		$sub         = \wcs_create_subscription( [ 'status' => 'cancelled' ] );
		$sub_id      = \is_wp_error( $sub ) ? 0 : $sub->get_id();
		$expire_date = 'subscription_' . $sub_id;
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );
		$this->assertFalse( $result, '訂閱狀態 cancelled 不可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: 跟隨訂閱 — 訂閱狀態 expired 時不可觀看
	 *
	 * Scenario Outline（訂閱狀態=expired → 觀看權限=不可觀看）
	 */
	public function test_跟隨訂閱expired不可觀看(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱狀態測試' );
		}

		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（expired）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		$sub         = \wcs_create_subscription( [ 'status' => 'expired' ] );
		$sub_id      = \is_wp_error( $sub ) ? 0 : $sub->get_id();
		$expire_date = 'subscription_' . $sub_id;
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		$result = Gate::user_has_valid_pass_for_course( $this->user_a_id, $this->course_200 );
		$this->assertFalse( $result, '訂閱狀態 expired 不可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: OR 疊加 - 逐課綁定（avl_course_ids）命中即可觀看
	 *
	 * Example: 逐課綁定命中時可觀看（即使無權限包涵蓋）
	 *   Given 學員 "UserA" 已透過逐課綁定取得課程 300 的觀看權限（avl_course_ids 含 300）
	 *   When 系統判定學員 "UserA" 對課程 300 的觀看權限
	 *   Then 觀看權限應為「可觀看」
	 */
	public function test_逐課綁定命中可觀看(): void {
		// Given：UserA 無任何權限包，但 avl_course_ids 含 course_300
		\add_user_meta( $this->user_a_id, 'avl_course_ids', $this->course_300, false );

		// When：透過 is_avl 判定（整合 avl_course_ids + pass 的最終判定）
		$result = CourseUtils::is_avl( $this->course_300, $this->user_a_id );

		// Then：可觀看（avl_course_ids 命中）
		$this->assertTrue( $result, '逐課綁定（avl_course_ids）命中時應可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: OR 疊加 - 訂閱包失效但單獨購買的課程仍可觀看
	 *
	 * Example: 訂閱包失效但單獨購買（avl_course_ids）的課程仍可觀看
	 *   Given 學員 "UserA" 持有 follow_subscription 包（cancelled）
	 *   And 學員 "UserA" 已透過逐課綁定取得課程 300 的觀看權限
	 *   When 系統判定學員 "UserA" 對課程 300 的觀看權限
	 *   Then 觀看權限應為「可觀看」
	 */
	public function test_訂閱包失效但逐課綁定仍可觀看(): void {
		// Given：建立 cancelled 訂閱包，授予 UserA
		$pass_id     = $this->create_access_pass(
			[
				'name'       => '跟隨訂閱包（cancelled）',
				'scope_type' => 'all',
				'limit_type' => 'follow_subscription',
			]
		);
		// 模擬 cancelled 訂閱：expire_date = '0'（WC_Subscription 不存在時 fallback）
		// 或若有 WC_Subscription，可建立 cancelled 訂閱
		$expire_date = \class_exists( 'WC_Subscription' )
			? 'subscription_' . ( \wcs_create_subscription( [ 'status' => 'cancelled' ] )?->get_id() ?? 0 )
			: 'subscription_0'; // 無法解析的 subscription ID 視為失效
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, $expire_date );

		// And：UserA 透過逐課綁定取得課程 300
		\add_user_meta( $this->user_a_id, 'avl_course_ids', $this->course_300, false );

		// When：透過 is_avl 判定
		$result = CourseUtils::is_avl( $this->course_300, $this->user_a_id );

		// Then：可觀看（avl_course_ids OR 疊加，訂閱包失效但單獨購買仍有效）
		$this->assertTrue( $result, '訂閱包失效但逐課綁定存在時應仍可觀看' );
	}

	/**
	 * @test
	 * @group edge
	 * Rule: OR 疊加 - 兩個來源皆涵蓋同一課程時可觀看
	 *
	 * Example: 分類包 + 逐課綁定皆涵蓋 course_100 時可觀看
	 */
	public function test_兩來源皆涵蓋時可觀看(): void {
		// Given：UserA 持有 HTML 分類包（涵蓋 course_100）
		$pass_id = $this->create_access_pass(
			[
				'name'       => 'HTML 分類包',
				'scope_type' => 'category',
				'limit_type' => 'unlimited',
				'term_ids'   => [ $this->term_10 ],
			]
		);
		$this->grant_pass_to_user( $this->user_a_id, $pass_id, null );

		// And：UserA 透過逐課綁定取得 course_100
		\add_user_meta( $this->user_a_id, 'avl_course_ids', $this->course_100, false );

		// When：判定 UserA 對課程 100
		$result = CourseUtils::is_avl( $this->course_100, $this->user_a_id );

		// Then：可觀看（任一來源允許即可）
		$this->assertTrue( $result, '兩來源皆涵蓋時應可觀看' );
	}
}
