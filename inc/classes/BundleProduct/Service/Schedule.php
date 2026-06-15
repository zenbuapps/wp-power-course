<?php
/**
 * 銷售方案自動上下線排程服務（Issue #247）
 *
 * 沿用「排程開課」（course_schedule）的成熟 pattern：掛在每 10 分鐘執行一次的
 * Bootstrap::SCHEDULE_ACTION，查詢到期的銷售方案並切換 post_status（publish ↔ draft）。
 *
 * - 自動下線：now >= bundle_schedule_offline 且 status=publish → 轉 draft（保留資料，非刪除）
 * - 自動上線：now >= bundle_schedule_online  且 status=draft   → 轉 publish
 *
 * 為避免同一輪 online → offline 互相干擾（先 publish 又被 offline 立刻 draft 的誤判），
 * 一律「先處理 online，再處理 offline」，並以方案「當下 post_status」作為天然守門：
 *   - online 只作用於 draft、offline 只作用於 publish，狀態本身即互斥條件。
 *
 * 狀態切換一律走 wp_update_post()（內部自動 clean_post_cache），不使用 raw SQL 直寫，
 * 避免 object cache stale（見 wordpress.rule.md）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\BundleProduct\Service;

use J7\PowerCourse\Bootstrap;
use J7\PowerCourse\BundleProduct\Helper;

/**
 * Class Schedule
 */
final class Schedule {
	use \J7\WpUtils\Traits\SingletonTrait;

	// 排程方向
	const ONLINE  = 'online';
	const OFFLINE = 'offline';

	/** Constructor */
	public function __construct() {
		// 註冊到既有每 10 分鐘輪詢的 hook（與排程開課同機制，Q6=A）
		\add_action( Bootstrap::SCHEDULE_ACTION, [ __CLASS__, 'run_schedule' ] );
	}

	/**
	 * 執行排程輪詢：先上線、後下線
	 *
	 * @return void
	 */
	public static function run_schedule(): void {
		// 先 online（draft → publish），再 offline（publish → draft），避免互相干擾
		self::run_direction( self::ONLINE );
		self::run_direction( self::OFFLINE );
	}

	/**
	 * 依方向查詢到期方案並切換狀態
	 *
	 * @param string $direction self::ONLINE | self::OFFLINE
	 * @return void
	 */
	private static function run_direction( string $direction ): void {
		global $wpdb;

		$is_offline   = self::OFFLINE === $direction;
		$meta_key     = $is_offline ? Helper::SCHEDULE_OFFLINE_META_KEY : Helper::SCHEDULE_ONLINE_META_KEY;
		$from_status  = $is_offline ? 'publish' : 'draft'; // 下線作用於 publish、上線作用於 draft
		$now          = time();

		// 查詢：product 型別、目前狀態符合、排程 > 0 且已到點
		// @phpstan-ignore-next-line
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = 'product' AND p.post_status = %s AND pm.meta_value > 0 AND pm.meta_value <= %d",
				$meta_key,
				$from_status,
				$now
			)
		);

		foreach ( $post_ids as $post_id ) {
			$product = \wc_get_product( (int) $post_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			// 防呆：輪詢僅切換銷售方案商品（一般商品不會有排程 meta，此為雙保險）
			if ( ! \get_post_meta( $product->get_id(), 'bundle_type', true ) ) {
				continue;
			}
			self::switch_status( $product, $direction );
		}
	}

	/**
	 * 立即切換單一方案狀態（Q3=B：設定過去時間時於儲存當下立即執行）
	 *
	 * @param \WC_Product $product   方案商品
	 * @param string      $direction self::ONLINE | self::OFFLINE
	 * @return bool 是否成功切換
	 */
	public static function run_immediately( \WC_Product $product, string $direction ): bool {
		return self::switch_status( $product, $direction );
	}

	/**
	 * 切換方案狀態並記錄 done_at
	 *
	 * @param \WC_Product $product   方案商品
	 * @param string      $direction self::ONLINE | self::OFFLINE
	 * @return bool 是否成功切換
	 */
	private static function switch_status( \WC_Product $product, string $direction ): bool {
		$product_id = $product->get_id();

		$is_offline = self::OFFLINE === $direction;
		$new_status = $is_offline ? 'draft' : 'publish';
		$done_key   = $is_offline ? Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY : Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY;

		$result = \wp_update_post(
			[
				'ID'          => $product_id,
				'post_status' => $new_status,
			],
			true
		);

		if ( \is_wp_error( $result ) ) {
			\J7\WpUtils\Classes\WC::logger(
				sprintf(
					/* translators: 1: 方案 ID, 2: 錯誤訊息 */
					__( 'Failed to auto switch bundle #%1$d status, %2$s', 'power-course' ),
					$product_id,
					$result->get_error_message()
				),
				'error'
			);
			return false;
		}

		// 記錄自動執行時間，供後台列表 / 編輯頁顯示「已於 X 自動上/下線」（Q4=A、Q5=A）
		\update_post_meta( $product_id, $done_key, time() );

		return true;
	}
}
