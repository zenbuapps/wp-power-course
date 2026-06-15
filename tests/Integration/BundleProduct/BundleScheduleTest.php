<?php
/**
 * 銷售方案自動上下線排程 整合測試（Issue #247）
 *
 * 對應規格：
 * - specs/features/bundle/設定銷售方案排程.feature
 * - specs/features/bundle/銷售方案自動上下線.feature
 *
 * 覆蓋範圍：
 * - 設定 / 修改 / 清除 上線、下線時間（選填語義）
 * - Q3=B：設定過去時間 → 立即執行並回傳提示
 * - ActionScheduler 輪詢：到點 publish↔draft、未到點維持、online/offline 互不干擾
 * - 邊界：已是 draft 的下線略過、已刪除方案安全略過、保留資料
 *
 * 注意：時間一律以 time() 為基準（相對 now 設定 meta），不硬編 2026 日期，確保測試與時鐘無關。
 *
 * @group bundle
 * @group issue-247
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\BundleProduct\Service\Schedule;
use J7\PowerCourse\Api\Product as ProductApi;

/**
 * Class BundleScheduleTest
 */
class BundleScheduleTest extends TestCase {

	protected function configure_dependencies(): void {
		// 直接使用 Schedule 服務與 Product API 靜態方法
	}

	/**
	 * 建立一個銷售方案（bundle 商品）
	 *
	 * @param string               $status 文章狀態
	 * @param array<string, mixed> $meta   額外 meta（如 bundle_schedule_offline）
	 * @return int bundle id
	 */
	private function create_bundle( string $status = 'publish', array $meta = [] ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '測試銷售方案',
				'post_status' => $status,
				'post_type'   => 'product',
			]
		);
		\update_post_meta( $bundle_id, 'bundle_type', 'single_course' );
		\update_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, '100' );
		foreach ( $meta as $key => $value ) {
			\update_post_meta( $bundle_id, $key, $value );
		}
		return $bundle_id;
	}

	/**
	 * 取得方案目前狀態
	 *
	 * @param int $bundle_id 方案 id
	 * @return string
	 */
	private function get_status( int $bundle_id ): string {
		\clean_post_cache( $bundle_id );
		return (string) \get_post_status( $bundle_id );
	}

	// ========== 設定 / 選填語義 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_設定未來下線時間_儲存meta且維持發佈(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$future    = time() + DAY_IN_SECONDS;

		$meta_data = ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_OFFLINE_META_KEY => (string) $future ],
			\wc_get_product( $bundle_id )
		);

		$this->assertFalse( \is_wp_error( $meta_data ), '不應回傳錯誤' );
		$helper = Helper::instance( $bundle_id );
		$this->assertSame( $future, $helper->get_schedule_offline(), '下線時間應被儲存' );
		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '尚未到點，狀態應維持 publish' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_為草稿方案設定未來上線時間_儲存meta且維持草稿(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$future    = time() + DAY_IN_SECONDS;

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_ONLINE_META_KEY => (string) $future ],
			\wc_get_product( $bundle_id )
		);

		$helper = Helper::instance( $bundle_id );
		$this->assertSame( $future, $helper->get_schedule_online(), '上線時間應被儲存' );
		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '尚未到點，狀態應維持 draft' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_同時設定上線與下線時間(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$online    = time() + DAY_IN_SECONDS;
		$offline   = time() + ( 3 * DAY_IN_SECONDS );

		ProductApi::handle_special_fields(
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => (string) $online,
				Helper::SCHEDULE_OFFLINE_META_KEY => (string) $offline,
			],
			\wc_get_product( $bundle_id )
		);

		$helper = Helper::instance( $bundle_id );
		$this->assertSame( $online, $helper->get_schedule_online() );
		$this->assertSame( $offline, $helper->get_schedule_offline() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_選填語義_不傳排程時預設為0且不被切換(): void {
		$bundle_id = $this->create_bundle( 'publish' );

		ProductApi::handle_special_fields(
			[ 'name' => '常駐方案v2' ],
			\wc_get_product( $bundle_id )
		);

		$helper = Helper::instance( $bundle_id );
		$this->assertSame( 0, $helper->get_schedule_online(), '未設定時 online 應為 0' );
		$this->assertSame( 0, $helper->get_schedule_offline(), '未設定時 offline 應為 0' );

		// 輪詢不應切換無排程的方案
		Schedule::run_schedule();
		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '無排程方案不被自動下線' );
	}

	// ========== 修改 / 清除 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_修改下線時間_往後延長(): void {
		$old       = time() + DAY_IN_SECONDS;
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => $old ] );
		$new       = time() + ( 5 * DAY_IN_SECONDS );

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_OFFLINE_META_KEY => (string) $new ],
			\wc_get_product( $bundle_id )
		);

		$this->assertSame( $new, Helper::instance( $bundle_id )->get_schedule_offline(), '下線時間應更新為新值' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_清除下線時間_回到手動上下架(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() + DAY_IN_SECONDS ] );

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_OFFLINE_META_KEY => '' ],
			\wc_get_product( $bundle_id )
		);

		$this->assertSame( 0, Helper::instance( $bundle_id )->get_schedule_offline(), '清除後 offline 應為 0' );

		Schedule::run_schedule();
		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '已清除排程，不被自動下線' );
	}

	// ========== Q3=B：過去時間立即執行並提示 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_已發佈方案設定過去下線時間_立即轉草稿並提示(): void {
		$bundle_id = $this->create_bundle( 'publish' );
		$past      = time() - HOUR_IN_SECONDS;

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_OFFLINE_META_KEY => (string) $past ],
			\wc_get_product( $bundle_id )
		);

		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '過去下線時間應立即轉 draft' );
		$this->assertNotNull( ProductApi::$last_schedule_notice, '應有立即下線提示訊息' );
		$this->assertNotSame( '', (string) ProductApi::$last_schedule_notice );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_草稿方案設定過去上線時間_立即發佈並提示(): void {
		$bundle_id = $this->create_bundle( 'draft' );
		$past      = time() - HOUR_IN_SECONDS;

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_ONLINE_META_KEY => (string) $past ],
			\wc_get_product( $bundle_id )
		);

		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '過去上線時間應立即轉 publish' );
		$this->assertNotNull( ProductApi::$last_schedule_notice, '應有立即上線提示訊息' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_非數字排程值_正規化為0(): void {
		$bundle_id = $this->create_bundle( 'publish' );

		ProductApi::handle_special_fields(
			[ Helper::SCHEDULE_OFFLINE_META_KEY => 'not-a-number' ],
			\wc_get_product( $bundle_id )
		);

		$this->assertSame( 0, Helper::instance( $bundle_id )->get_schedule_offline(), '非數字應正規化為 0' );
	}

	// ========== ActionScheduler 輪詢 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線時間已到_輪詢自動轉草稿(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() - 600 ] );

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '到點後方案應自動轉 draft' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線時間未到_輪詢維持發佈(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() + DAY_IN_SECONDS ] );

		Schedule::run_schedule();

		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '未到點方案應維持 publish' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_上線時間已到_輪詢自動發佈(): void {
		$bundle_id = $this->create_bundle( 'draft', [ Helper::SCHEDULE_ONLINE_META_KEY => time() - 600 ] );

		Schedule::run_schedule();

		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '到點後草稿方案應自動發佈' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線後保留方案所有資料(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() - 600 ] );
		\update_post_meta( $bundle_id, '_regular_price', '399' );

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( '399', (string) \get_post_meta( $bundle_id, '_regular_price', true ), '價格資料應保留' );
		$this->assertSame( 'single_course', (string) \get_post_meta( $bundle_id, 'bundle_type', true ), 'bundle_type 應保留' );
		$this->assertSame( '100', (string) \get_post_meta( $bundle_id, Helper::LINK_COURSE_IDS_META_KEY, true ), '綁定課程應保留' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_同方案先自動上線後自動下線(): void {
		// online 在過去、offline 在更久的過去之後（但仍在過去）；模擬先上線後下線兩輪
		$bundle_id = $this->create_bundle(
			'draft',
			[ Helper::SCHEDULE_ONLINE_META_KEY => time() - 600 ]
		);

		// 第一輪：只有 online 到點 → 轉 publish
		Schedule::run_schedule();
		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '第一輪應自動上線' );

		// 設定 offline 也到點，第二輪 → 轉 draft
		\update_post_meta( $bundle_id, Helper::SCHEDULE_OFFLINE_META_KEY, time() - 300 );
		Schedule::run_schedule();
		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '第二輪應自動下線' );
	}

	// ========== 邊界：略過、不報錯 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_已是草稿的下線輪詢略過不報錯(): void {
		$bundle_id = $this->create_bundle( 'draft', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() - 600 ] );

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '已是草稿應維持 draft' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_已刪除方案的排程輪詢安全略過(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() - 600 ] );
		\wp_delete_post( $bundle_id, true );

		// 不應拋出例外
		Schedule::run_schedule();

		$this->assertNull( \get_post( $bundle_id ), '方案已刪除' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_下線後記錄done_at供後台可感知(): void {
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => time() - 600 ] );

		Schedule::run_schedule();

		$done_at = (int) \get_post_meta( $bundle_id, Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY, true );
		$this->assertGreaterThan( 0, $done_at, '應記錄自動下線執行時間 done_at' );
	}

	// ========== API 回傳排程欄位（Q5 後台顯示） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_API回傳排程欄位供列表與編輯頁顯示(): void {
		$offline   = time() + DAY_IN_SECONDS;
		$bundle_id = $this->create_bundle( 'publish', [ Helper::SCHEDULE_OFFLINE_META_KEY => $offline ] );

		$formatted = ProductApi::instance()->format_product_details( \wc_get_product( $bundle_id ) );

		$this->assertArrayHasKey( Helper::SCHEDULE_OFFLINE_META_KEY, $formatted, '應回傳 offline 欄位' );
		$this->assertArrayHasKey( Helper::SCHEDULE_ONLINE_META_KEY, $formatted, '應回傳 online 欄位' );
		$this->assertSame( $offline, $formatted[ Helper::SCHEDULE_OFFLINE_META_KEY ], 'offline 應為設定的 timestamp' );
		$this->assertNull( $formatted[ Helper::SCHEDULE_ONLINE_META_KEY ], '未設定的 online 應為 null（避免 DatePicker Invalid date）' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_API對未設定排程回傳null而非0(): void {
		$bundle_id = $this->create_bundle( 'publish' );

		$formatted = ProductApi::instance()->format_product_details( \wc_get_product( $bundle_id ) );

		$this->assertNull( $formatted[ Helper::SCHEDULE_OFFLINE_META_KEY ], '無排程時 offline 應為 null' );
		$this->assertNull( $formatted[ Helper::SCHEDULE_ONLINE_META_KEY ], '無排程時 online 應為 null' );
	}
}
