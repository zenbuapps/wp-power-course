<?php
/**
 * 購買開通權限包 整合測試（Red 階段）
 *
 * Feature: specs/features/access-pass/購買開通權限包.feature
 * Issue #252：課程通行證（Access Pass）— 訂單開通
 *
 * 預期失敗原因（Red 階段）：
 *   - J7\PowerCourse\Resources\AccessPass\Service\Grant 類別尚未實作
 *   - 訂單完成觸發 Grant::on_order_completed 邏輯未存在
 *   - 訂閱首期付款觸發 Grant::on_subscription_payment_complete 邏輯未存在
 *   - pc_user_access_pass 表的授予邏輯未實作
 *
 * @group access-pass
 * @group grant
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Tests\Integration\AccessPass;

use Tests\Integration\TestCase;
use J7\PowerCourse\Resources\AccessPass\Core\CPT;
use J7\PowerCourse\Resources\AccessPass\Service\Grant;
use J7\PowerCourse\Resources\AccessPass\Service\Repository;

/**
 * Class PurchaseGrantAccessPassTest
 * 驗證購買開通權限包（對映 購買開通權限包.feature 的所有 Rule/Example）
 *
 * Grant 開通方式：
 *   1. 一次性訂單完成（order_status=completed）→ Grant::on_order_completed($order_id)
 *   2. 訂閱首期付款（woocommerce_subscription_payment_complete）→ Grant::on_subscription_payment_complete($subscription)
 */
class PurchaseGrantAccessPassTest extends TestCase {

	/** @var int 管理員 ID */
	private int $admin_id;

	/** @var int 學員 UserA ID */
	private int $user_a_id;

	/** @var int 課程 100（HTML 入門課）*/
	private int $course_100;

	/** @var int 全站永久權限包（passId=300）*/
	private int $pass_300;

	/** @var int 限時 30 天權限包（passId=301）*/
	private int $pass_301;

	/** @var int 跟隨訂閱全站包（passId=302）*/
	private int $pass_302;

	/** @var int 商品 500（全站通行證，掛 pass_300）*/
	private int $product_500;

	/** @var int 商品 501（限時通行證，掛 pass_301）*/
	private int $product_501;

	/** @var int 商品 510（月費暢看 subscription，掛 pass_302）*/
	private int $product_510;

	/**
	 * 初始化依賴（Grant 使用靜態方法，尚未實作）
	 */
	protected function configure_dependencies(): void {
		// Grant::class 尚未實作，Red 階段不初始化
	}

	/**
	 * 每個測試前建立 Background fixture
	 *
	 * Background（來自 feature）：
	 *   - Admin（administrator）、UserA（subscriber）
	 *   - course_100（HTML 入門課）
	 *   - pass_300（全站永久）、pass_301（限時 30 天）、pass_302（跟隨訂閱）
	 *   - product_500（掛 pass_300）、product_501（掛 pass_301）、product_510（掛 pass_302，subscription）
	 */
	public function set_up(): void {
		parent::set_up();

		// Background：建立管理員
		$this->admin_id = $this->factory()->user->create(
			[
				'user_login' => 'admin_grant_' . uniqid(),
				'role'       => 'administrator',
			]
		);
		$this->ids['Admin'] = $this->admin_id;

		// Background：建立學員 UserA
		$this->user_a_id = $this->factory()->user->create(
			[
				'user_login' => 'user_a_grant_' . uniqid(),
				'user_email' => 'user_a_grant_' . uniqid() . '@test.com',
				'role'       => 'subscriber',
			]
		);
		$this->ids['UserA'] = $this->user_a_id;

		\wp_set_current_user( $this->admin_id );

		// Background：建立課程 100
		$this->course_100 = $this->create_course( [ 'post_title' => 'HTML 入門課' ] );
		$this->ids['course_100'] = $this->course_100;

		// Background：建立權限包 300（全站永久）
		$this->pass_300 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '全站課程權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_300, 'scope_type', 'all' );
		\update_post_meta( $this->pass_300, 'limit_mode', 'permanent' );
		\update_post_meta( $this->pass_300, 'access_pass_status', 'active' );
		$this->ids['pass_300'] = $this->pass_300;

		// Background：建立權限包 301（限時 30 天）
		$this->pass_301 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '限時全站權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_301, 'scope_type', 'all' );
		\update_post_meta( $this->pass_301, 'limit_mode', 'limited' );
		\update_post_meta( $this->pass_301, 'limit_value', 30 );
		\update_post_meta( $this->pass_301, 'limit_unit', 'day' );
		\update_post_meta( $this->pass_301, 'access_pass_status', 'active' );
		$this->ids['pass_301'] = $this->pass_301;

		// Background：建立權限包 302（跟隨訂閱）
		$this->pass_302 = $this->factory()->post->create(
			[
				'post_type'   => CPT::POST_TYPE,
				'post_title'  => '訂閱全站權限',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->pass_302, 'scope_type', 'all' );
		\update_post_meta( $this->pass_302, 'limit_mode', 'follow_subscription' );
		\update_post_meta( $this->pass_302, 'access_pass_status', 'active' );
		$this->ids['pass_302'] = $this->pass_302;

		// Background：建立商品 500（全站通行證，掛 pass_300）
		$this->product_500 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '全站通行證',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_500, '_price', '1999' );
		\update_post_meta( $this->product_500, '_regular_price', '1999' );
		\update_post_meta( $this->product_500, 'access_pass_id', $this->pass_300 );
		$this->ids['product_500'] = $this->product_500;

		// Background：建立商品 501（限時通行證，掛 pass_301）
		$this->product_501 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '限時通行證',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_501, '_price', '999' );
		\update_post_meta( $this->product_501, '_regular_price', '999' );
		\update_post_meta( $this->product_501, 'access_pass_id', $this->pass_301 );
		$this->ids['product_501'] = $this->product_501;

		// Background：建立商品 510（月費暢看 subscription，掛 pass_302）
		$this->product_510 = $this->factory()->post->create(
			[
				'post_type'   => 'product',
				'post_title'  => '月費暢看',
				'post_status' => 'publish',
			]
		);
		\update_post_meta( $this->product_510, '_price', '299' );
		\update_post_meta( $this->product_510, '_regular_price', '299' );
		\update_post_meta( $this->product_510, 'access_pass_id', $this->pass_302 );
		$this->ids['product_510'] = $this->product_510;
	}

	/**
	 * 每個測試後清理 CPT 與自訂表
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

	/**
	 * 建立 WooCommerce 測試訂單（含指定商品與狀態）
	 *
	 * @param int    $user_id    購買者 ID
	 * @param int    $product_id 商品 ID
	 * @param string $status     訂單狀態（processing/completed 等）
	 * @return int 訂單 ID
	 */
	private function create_wc_order( int $user_id, int $product_id, string $status = 'completed' ): int {
		$order = \wc_create_order( [ 'customer_id' => $user_id ] );

		if ( \is_wp_error( $order ) ) {
			$this->fail( 'wc_create_order 失敗：' . $order->get_error_message() );
		}

		$product = \wc_get_product( $product_id );
		if ( $product ) {
			$order->add_product( $product, 1 );
		}

		$order->update_status( $status );
		$order->save();

		return $order->get_id();
	}

	/**
	 * 確認用戶是否持有指定權限包（查詢 pc_user_access_pass 表）
	 *
	 * @param int $user_id 學員 ID
	 * @param int $pass_id 權限包 ID
	 * @return bool
	 */
	private function user_holds_pass( int $user_id, int $pass_id ): bool {
		$rows = Repository::find_by_user( $user_id );
		foreach ( $rows as $row ) {
			if ( (int) $row->pass_id === $pass_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 取得用戶持有的權限包列（指定 pass_id）
	 *
	 * @param int $user_id 學員 ID
	 * @param int $pass_id 權限包 ID
	 * @return object|null
	 */
	private function get_user_pass_row( int $user_id, int $pass_id ): ?object {
		$rows = Repository::find_by_user( $user_id );
		foreach ( $rows as $row ) {
			if ( (int) $row->pass_id === $pass_id ) {
				return $row;
			}
		}
		return null;
	}

	// ========== 冒煙測試（Smoke Tests）==========

	/**
	 * @test
	 * @group smoke
	 * 確認 Grant 類別存在（Red：預期失敗，類別尚未建立）
	 */
	public function test_grant_類別存在(): void {
		$this->assertTrue(
			\class_exists( Grant::class ),
			'J7\PowerCourse\Resources\AccessPass\Service\Grant 類別不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Grant::on_order_completed 方法存在（Red：預期失敗）
	 */
	public function test_grant_on_order_completed_方法存在(): void {
		$this->assertTrue(
			\method_exists( Grant::class, 'on_order_completed' ),
			'Grant::on_order_completed 方法不存在'
		);
	}

	/**
	 * @test
	 * @group smoke
	 * 確認 Grant::on_subscription_payment_complete 方法存在（Red：預期失敗）
	 */
	public function test_grant_on_subscription_payment_complete_方法存在(): void {
		$this->assertTrue(
			\method_exists( Grant::class, 'on_subscription_payment_complete' ),
			'Grant::on_subscription_payment_complete 方法不存在'
		);
	}

	// ========== 錯誤處理（Error Tests）==========

	/**
	 * @test
	 * @group error
	 * Rule: 前置（狀態）- 訂單未達開通條件（processing）時，使用者尚未取得權限包
	 *
	 * Example: 訂單仍為處理中時尚未授予權限包
	 *   Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "processing"
	 *   When 系統檢查訂單開通條件
	 *   Then 學員 "UserA" 尚未持有權限包 300
	 */
	public function test_訂單處理中尚未授予權限包(): void {
		// Given：UserA 下單，狀態為 processing
		$order_id = $this->create_wc_order( $this->user_a_id, $this->product_500, 'processing' );

		// When：觸發 Grant::on_order_completed（若狀態非 completed 應不開通）
		try {
			Grant::on_order_completed( $order_id );
		} catch ( \Throwable $e ) {
			// Grant 尚未實作，可能拋出例外；此處只驗證未授予
		}

		// Then：UserA 應尚未持有權限包 300
		$this->assertFalse(
			$this->user_holds_pass( $this->user_a_id, $this->pass_300 ),
			'訂單狀態 processing 不應授予權限包'
		);
	}

	// ========== 快樂路徑（Happy Tests）==========

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 訂單完成後，永久權限包授予使用者且無到期
	 *
	 * Example: 購買全站永久權限包後取得持有關係
	 *   Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "completed"
	 *   When 系統處理訂單開通
	 *   Then 操作成功
	 *   And 學員 "UserA" 應持有權限包 300
	 *   And 學員 "UserA" 持有的權限包 300 無到期時間
	 */
	public function test_訂單完成後永久包授予且無到期(): void {
		// Given：UserA 下單，狀態為 completed
		$order_id = $this->create_wc_order( $this->user_a_id, $this->product_500, 'completed' );

		// When：系統處理訂單開通
		Grant::on_order_completed( $order_id );

		// Then：UserA 應持有權限包 300
		$this->assertTrue(
			$this->user_holds_pass( $this->user_a_id, $this->pass_300 ),
			'訂單完成後 UserA 應持有全站永久包 pass_300'
		);

		// And：無到期時間（null/"0"/""/0）
		$row = $this->get_user_pass_row( $this->user_a_id, $this->pass_300 );
		$this->assertNotNull( $row, '應找到 pass_300 持有列' );
		$expire = $row->expire_date;
		$this->assertTrue(
			$expire === null || (string) $expire === '0' || $expire === '',
			"永久包 expire_date 應為 null、'0' 或空字串，實際值：" . \var_export( $expire, true )
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 訂單完成後，限時權限包依 limit_value 計算到期時間
	 *
	 * Example: 購買限時 30 天權限包後設定到期時間
	 *   Given 學員 "UserA" 下單購買商品 501，訂單狀態為 "completed"
	 *   When 系統處理訂單開通
	 *   Then 操作成功
	 *   And 學員 "UserA" 應持有權限包 301
	 *   And 學員 "UserA" 持有的權限包 301 到期時間為購買後 30 天
	 */
	public function test_訂單完成後限時包依limit_value計算到期(): void {
		$before_grant = time();

		// Given：UserA 下單限時 30 天包，狀態為 completed
		$order_id = $this->create_wc_order( $this->user_a_id, $this->product_501, 'completed' );

		// When：系統處理訂單開通
		Grant::on_order_completed( $order_id );

		$after_grant = time();

		// Then：UserA 應持有權限包 301
		$this->assertTrue(
			$this->user_holds_pass( $this->user_a_id, $this->pass_301 ),
			'訂單完成後 UserA 應持有限時包 pass_301'
		);

		// And：到期時間應為購買後約 30 天（允許 1 分鐘誤差）
		$row = $this->get_user_pass_row( $this->user_a_id, $this->pass_301 );
		$this->assertNotNull( $row, '應找到 pass_301 持有列' );
		$expire = (int) $row->expire_date;

		$expected_min = $before_grant + ( 30 * DAY_IN_SECONDS );
		$expected_max = $after_grant + ( 30 * DAY_IN_SECONDS );

		$this->assertGreaterThanOrEqual(
			$expected_min,
			$expire,
			'限時包到期時間應不早於購買後 30 天'
		);
		$this->assertLessThanOrEqual(
			$expected_max + 60,
			$expire,
			'限時包到期時間不應超過購買後 30 天 + 1 分鐘容差'
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 訂閱首期付款完成後，跟隨訂閱權限包綁定訂閱
	 *
	 * Example: 訂閱首期付款完成後取得跟隨訂閱權限包
	 *   Given 學員 "UserA" 訂閱商品 510，首期付款完成，訂閱狀態為 "active"
	 *   When 系統處理訂閱開通
	 *   Then 操作成功
	 *   And 學員 "UserA" 應持有權限包 302
	 *   And 學員 "UserA" 持有的權限包 302 綁定該訂閱（expire_date = "subscription_{id}"）
	 */
	public function test_訂閱首期付款後跟隨訂閱包綁定訂閱(): void {
		if ( ! \class_exists( 'WC_Subscription' ) ) {
			$this->markTestSkipped( 'WC_Subscription 不存在，跳過訂閱開通測試' );
		}

		// Given：建立 active 訂閱（WC_Subscription）
		$subscription = \wcs_create_subscription(
			[
				'customer_id' => $this->user_a_id,
				'status'      => 'active',
			]
		);
		$this->assertNotWPError( $subscription, 'wcs_create_subscription 失敗' );
		$subscription->add_product( \wc_get_product( $this->product_510 ), 1 );
		$subscription->save();
		$sub_id = $subscription->get_id();

		// When：系統處理訂閱開通
		Grant::on_subscription_payment_complete( $subscription );

		// Then：UserA 應持有權限包 302
		$this->assertTrue(
			$this->user_holds_pass( $this->user_a_id, $this->pass_302 ),
			'訂閱首期付款後 UserA 應持有跟隨訂閱包 pass_302'
		);

		// And：expire_date 應為 "subscription_{sub_id}"（綁定該訂閱）
		$row = $this->get_user_pass_row( $this->user_a_id, $this->pass_302 );
		$this->assertNotNull( $row, '應找到 pass_302 持有列' );
		$this->assertSame(
			"subscription_{$sub_id}",
			$row->expire_date,
			"跟隨訂閱包 expire_date 應為 subscription_{$sub_id}"
		);
	}

	/**
	 * @test
	 * @group happy
	 * Rule: 後置（狀態）- 不展開課程 id 寫入 avl_course_ids（動態範圍由觀看時計算）
	 *
	 * Example: 購買全站權限包不寫入個別課程到 avl_course_ids
	 *   Given 學員 "UserA" 下單購買商品 500，訂單狀態為 "completed"
	 *   When 系統處理訂單開通
	 *   Then 操作成功
	 *   And 學員 "UserA" 的 avl_course_ids 不包含課程 100
	 */
	public function test_購買全站包不寫入avl_course_ids(): void {
		// Given：UserA 下單，狀態為 completed
		$order_id = $this->create_wc_order( $this->user_a_id, $this->product_500, 'completed' );

		// When：系統處理訂單開通
		Grant::on_order_completed( $order_id );

		// Then：UserA 的 avl_course_ids 不應包含 course_100（採 compute-on-read，不展開）
		$avl_course_ids = \get_user_meta( $this->user_a_id, 'avl_course_ids' );
		$avl_int        = \array_map( 'intval', (array) $avl_course_ids );

		$this->assertNotContains(
			$this->course_100,
			$avl_int,
			'購買全站包不應把個別課程 id 寫入 avl_course_ids（compute-on-read 架構）'
		);
	}
}
