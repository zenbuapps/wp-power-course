<?php
/**
 * 銷售方案上、下線時間都到期後不應每輪重新上架再下架（Issue #293）
 *
 * 舊行為：每輪先 online 再 offline，查詢只看「目前狀態 + 排程是否到期」。
 * 下線轉回草稿後，下一輪草稿又符合「上線時間已到」→ 重新發佈 → 再下線，無限循環。
 *
 * @group bundle
 * @group issue-293
 */

declare( strict_types=1 );

namespace Tests\Integration\BundleProduct;

use Tests\Integration\TestCase;
use J7\PowerCourse\BundleProduct\Helper;
use J7\PowerCourse\BundleProduct\Service\Schedule;

/**
 * Class BundleScheduleRepeatTest
 */
class BundleScheduleRepeatTest extends TestCase {

	/** 初始化依賴 */
	protected function configure_dependencies(): void {
		// 直接使用 Schedule 服務
	}

	/**
	 * 建立銷售方案
	 *
	 * @param string               $status 文章狀態
	 * @param array<string, mixed> $meta   排程 meta
	 * @return int
	 */
	private function create_bundle( string $status, array $meta = [] ): int {
		$bundle_id = $this->factory()->post->create(
			[
				'post_title'  => '重複排程測試方案',
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
	 * @param int $bundle_id 方案 ID
	 * @return string
	 */
	private function get_status( int $bundle_id ): string {
		\clean_post_cache( $bundle_id );
		return (string) \get_post_status( $bundle_id );
	}

	/**
	 * @param int    $bundle_id 方案 ID
	 * @param string $key       meta key
	 * @return int
	 */
	private function meta_int( int $bundle_id, string $key ): int {
		\clean_post_cache( $bundle_id );
		return (int) \get_post_meta( $bundle_id, $key, true );
	}

	/**
	 * 記錄 post_status 變動次數
	 *
	 * @param int $bundle_id 方案 ID
	 * @return \ArrayObject<int, string>
	 */
	private function watch_transitions( int $bundle_id ): \ArrayObject {
		$log = new \ArrayObject();
		\add_action(
			'transition_post_status',
			static function ( $new_status, $old_status, $post ) use ( $bundle_id, $log ) {
				if ( (int) $post->ID === $bundle_id && $new_status !== $old_status ) {
					$log->append( "{$old_status}->{$new_status}" );
				}
			},
			10,
			3
		);
		return $log;
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue重現_已下線的方案後續輪詢不再上架再下架(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_ONLINE_META_KEY          => $now - 7200,
				Helper::SCHEDULE_OFFLINE_META_KEY         => $now - 3600,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY  => $now - 7000,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);
		$transitions = $this->watch_transitions( $bundle_id );

		Schedule::run_schedule();
		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( [], $transitions->getArrayCopy(), '下線到期後不應再有任何狀態切換' );
		$this->assertSame( $now - 7000, $this->meta_int( $bundle_id, Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY ), '上線執行時間不應被更新' );
		$this->assertSame( $now - 3500, $this->meta_int( $bundle_id, Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY ), '下線執行時間不應被更新' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_上下線都已到期的草稿方案_直接維持草稿不先上架(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => $now - 7200,
				Helper::SCHEDULE_OFFLINE_META_KEY => $now - 3600,
			]
		);
		$transitions = $this->watch_transitions( $bundle_id );

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( [], $transitions->getArrayCopy(), '較晚的下線已到期，舊的上線設定不應執行' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_發佈中方案下線到期_轉草稿後多輪都維持草稿(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'publish',
			[
				Helper::SCHEDULE_ONLINE_META_KEY         => $now - 7200,
				Helper::SCHEDULE_OFFLINE_META_KEY        => $now - 60,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY => $now - 7000,
			]
		);
		$transitions = $this->watch_transitions( $bundle_id );

		Schedule::run_schedule();
		Schedule::run_schedule();
		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( [ 'publish->draft' ], $transitions->getArrayCopy(), '只應下線一次' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_管理員手動重新上架_舊的下線設定不再把它轉回草稿(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'publish',
			[
				Helper::SCHEDULE_ONLINE_META_KEY          => $now - 7200,
				Helper::SCHEDULE_OFFLINE_META_KEY         => $now - 3600,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY  => $now - 7000,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);

		Schedule::run_schedule();

		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '已執行過的下線設定不應再生效' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_管理員設定新的上線時間_到點後仍正常上線(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_ONLINE_META_KEY          => $now - 7200,
				Helper::SCHEDULE_OFFLINE_META_KEY         => $now - 3600,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY  => $now - 7000,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);

		// 管理員設定新的上線時間（儲存當下在未來）
		$product = \wc_get_product( $bundle_id );
		$this->assertInstanceOf( \WC_Product::class, $product );
		\J7\PowerCourse\Api\Product::handle_schedule_fields( [ Helper::SCHEDULE_ONLINE_META_KEY => $now + 600 ], $product );
		Schedule::run_schedule();
		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '新的上線時間未到，應維持草稿' );

		// 模擬時間經過：新上線時間到點
		\update_post_meta( $bundle_id, Helper::SCHEDULE_ONLINE_META_KEY, $now - 60 );
		Schedule::run_schedule();

		$this->assertSame( 'publish', $this->get_status( $bundle_id ), '新的上線設定應生效' );
		$this->assertGreaterThanOrEqual( $now - 60, $this->meta_int( $bundle_id, Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY ) );

		// 之後的輪詢不應被舊的下線設定拉回草稿
		Schedule::run_schedule();
		$this->assertSame( 'publish', $this->get_status( $bundle_id ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_下線在前上線在後_重新開賣期間不被舊下線設定拉回草稿(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_OFFLINE_META_KEY         => $now - 7200,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 7000,
				Helper::SCHEDULE_ONLINE_META_KEY          => $now - 60,
			]
		);

		Schedule::run_schedule();
		Schedule::run_schedule();

		$this->assertSame( 'publish', $this->get_status( $bundle_id ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_上下線時間相同且都到期_草稿方案不先上架(): void {
		$at          = time() - 600;
		$bundle_id   = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => $at,
				Helper::SCHEDULE_OFFLINE_META_KEY => $at,
			]
		);
		$transitions = $this->watch_transitions( $bundle_id );

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( [], $transitions->getArrayCopy(), '時間相同時以下線為準，不應先上架再下架' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_上下線時間相同且都到期_發佈中方案照常下線(): void {
		$at        = time() - 600;
		$bundle_id = $this->create_bundle(
			'publish',
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => $at,
				Helper::SCHEDULE_OFFLINE_META_KEY => $at,
			]
		);

		Schedule::run_schedule();

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_手動重新上架後存檔帶著沒改過的過去下線時間_不被立即轉回草稿(): void {
		$now       = time();
		$online    = $now - 7200;
		$offline   = $now - 3600;
		$bundle_id = $this->create_bundle(
			'publish',
			[
				Helper::SCHEDULE_ONLINE_META_KEY          => $online,
				Helper::SCHEDULE_OFFLINE_META_KEY         => $offline,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY  => $now - 7000,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);
		$product   = \wc_get_product( $bundle_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		// 表單存檔會把兩個沒改過的排程時間一起送出
		\J7\PowerCourse\Api\Product::handle_schedule_fields(
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => (string) $online,
				Helper::SCHEDULE_OFFLINE_META_KEY => (string) $offline,
			],
			$product
		);

		$this->assertSame( 'publish', $this->get_status( $bundle_id ) );
		$this->assertNull( \J7\PowerCourse\Api\Product::$last_schedule_notice );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_已下線的草稿方案存檔帶著沒改過的過去時間_不先上架再下架(): void {
		$now         = time();
		$online      = $now - 7200;
		$offline     = $now - 3600;
		$bundle_id   = $this->create_bundle(
			'draft',
			[
				Helper::SCHEDULE_ONLINE_META_KEY          => $online,
				Helper::SCHEDULE_OFFLINE_META_KEY         => $offline,
				Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY  => $now - 7000,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);
		$transitions = $this->watch_transitions( $bundle_id );
		$product     = \wc_get_product( $bundle_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		\J7\PowerCourse\Api\Product::handle_schedule_fields(
			[
				Helper::SCHEDULE_ONLINE_META_KEY  => (string) $online,
				Helper::SCHEDULE_OFFLINE_META_KEY => (string) $offline,
			],
			$product
		);

		$this->assertSame( 'draft', $this->get_status( $bundle_id ) );
		$this->assertSame( [], $transitions->getArrayCopy() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_發佈中方案把下線時間改成另一個過去時間_仍立即下線(): void {
		$now       = time();
		$bundle_id = $this->create_bundle(
			'publish',
			[
				Helper::SCHEDULE_OFFLINE_META_KEY         => $now - 3600,
				Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY => $now - 3500,
			]
		);
		$product   = \wc_get_product( $bundle_id );
		$this->assertInstanceOf( \WC_Product::class, $product );

		\J7\PowerCourse\Api\Product::handle_schedule_fields( [ Helper::SCHEDULE_OFFLINE_META_KEY => $now - 60 ], $product );

		$this->assertSame( 'draft', $this->get_status( $bundle_id ), '時間有變動 = 管理員明確指定，照常立即執行' );
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider is_due_cases
	 *
	 * @param string $direction 方向
	 * @param int    $online    上線
	 * @param int    $offline   下線
	 * @param int    $on_done   上線 done_at
	 * @param int    $off_done  下線 done_at
	 * @param bool   $expected  預期
	 */
	public function test_is_due判斷表( string $direction, int $online, int $offline, int $on_done, int $off_done, bool $expected ): void {
		$this->assertSame( $expected, Schedule::is_due( $direction, $online, $offline, $on_done, $off_done, 1000 ) );
	}

	/**
	 * @return array<string, array{string, int, int, int, int, bool}>
	 */
	public static function is_due_cases(): array {
		return [
			'未設定'               => [ Schedule::ONLINE, 0, 0, 0, 0, false ],
			'未到點'               => [ Schedule::ONLINE, 1001, 0, 0, 0, false ],
			'到點且未執行'         => [ Schedule::ONLINE, 900, 0, 0, 0, true ],
			'剛好到點'             => [ Schedule::ONLINE, 1000, 0, 0, 0, true ],
			'已執行過'             => [ Schedule::ONLINE, 900, 0, 905, 0, false ],
			'舊的執行紀錄早於新設定' => [ Schedule::ONLINE, 900, 0, 500, 0, true ],
			'較晚的下線已到期'     => [ Schedule::ONLINE, 800, 900, 0, 0, false ],
			'下線未到期不影響上線' => [ Schedule::ONLINE, 800, 1100, 0, 0, true ],
			'下線較早不影響上線'   => [ Schedule::ONLINE, 900, 800, 0, 850, true ],
			'下線到點且未執行'     => [ Schedule::OFFLINE, 800, 900, 805, 0, true ],
			'下線已執行過'         => [ Schedule::OFFLINE, 800, 900, 805, 905, false ],
			'較晚的上線已到期'     => [ Schedule::OFFLINE, 900, 800, 0, 0, false ],
			'時間相同_上線被取代'  => [ Schedule::ONLINE, 900, 900, 0, 0, false ],
			'時間相同_下線照常'    => [ Schedule::OFFLINE, 900, 900, 0, 0, true ],
		];
	}
}
