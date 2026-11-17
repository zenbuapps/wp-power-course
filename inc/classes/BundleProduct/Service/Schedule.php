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
 * 光靠狀態守門不夠（Issue #293）：上、下線時間都已到期時，下線把方案轉回草稿，
 * 下一輪草稿又符合「上線時間已到」→ 重新發佈 → 再下線，每 10 分鐘循環一次。
 * 所以輪詢另外以 is_due() 判斷「這一次的排程設定」是否還需要執行：
 *   - 已執行過（done_at >= 排程時間）→ 不再執行；管理員改成新的時間後才會再生效
 *   - 另一方向的排程也已到期且時間較晚 → 以較晚的為準，較早的那筆視為過期
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
			$product_id = $product->get_id();
			// 防呆：輪詢僅切換銷售方案商品（一般商品不會有排程 meta，此為雙保險）
			if ( ! \get_post_meta( $product_id, 'bundle_type', true ) ) {
				continue;
			}
			$is_due = self::is_due(
				$direction,
				(int) \get_post_meta( $product_id, Helper::SCHEDULE_ONLINE_META_KEY, true ),
				(int) \get_post_meta( $product_id, Helper::SCHEDULE_OFFLINE_META_KEY, true ),
				(int) \get_post_meta( $product_id, Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY, true ),
				(int) \get_post_meta( $product_id, Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY, true ),
				$now
			);
			if ( ! $is_due ) {
				continue;
			}
			self::switch_status( $product, $direction );
		}
	}

	/**
	 * 判斷某方向的排程在本輪是否需要執行（Issue #293）
	 *
	 * 只看排程時間與執行紀錄，不看 post_status（狀態由 run_direction 的查詢條件把關）。
	 *
	 * @param string $direction       self::ONLINE | self::OFFLINE
	 * @param int    $online          上線時間（0 = 未設定）
	 * @param int    $offline         下線時間（0 = 未設定）
	 * @param int    $online_done_at  最近一次自動上線的執行時間（0 = 從未執行）
	 * @param int    $offline_done_at 最近一次自動下線的執行時間（0 = 從未執行）
	 * @param int    $now             目前時間
	 * @return bool
	 */
	public static function is_due( string $direction, int $online, int $offline, int $online_done_at, int $offline_done_at, int $now ): bool {
		$is_offline = self::OFFLINE === $direction;
		$scheduled  = $is_offline ? $offline : $online;
		$done_at    = $is_offline ? $offline_done_at : $online_done_at;
		$other      = $is_offline ? $online : $offline;

		// 未設定或還沒到
		if ( $scheduled <= 0 || $scheduled > $now ) {
			return false;
		}

		// 這一次的設定已執行過（done_at 記錄的是執行當下的 time()，必定 >= 排程時間）。
		// 執行後管理員手動改回原狀態，也不會被同一筆舊設定再切回去。
		if ( $done_at >= $scheduled ) {
			return false;
		}

		// 另一方向也已到期且時間較晚 → 較晚的才是現在該有的狀態，這筆較早的設定已過期。
		// 例：上線 11/12、下線 11/14，現在 11/15 → 只該下線，不該先上線。
		if ( $other > $scheduled && $other <= $now ) {
			return false;
		}

		return true;
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
	 * 將方案停放為草稿，等待排程上線（Issue #260）
	 *
	 * 使用情境：站長為「已發佈」的方案設定了**未來**的上線時間。
	 * 若不轉草稿，方案會一路 publish 到上線時間之後，上線排程也因為
	 * 「online 只作用於 draft」而靜默跳過，等同上線時間形同虛設。
	 *
	 * 與 run_immediately( OFFLINE ) 的差別：本方法**不寫** done_at meta，
	 * 因為這不是「自動下線」的執行結果，後台不該顯示「已於 X 自動下線」。
	 *
	 * @param \WC_Product $product 方案商品
	 * @return bool 是否成功轉為草稿
	 */
	public static function park_as_draft( \WC_Product $product ): bool {
		return self::update_status( $product, 'draft' );
	}

	/**
	 * 切換方案狀態並記錄 done_at
	 *
	 * @param \WC_Product $product   方案商品
	 * @param string      $direction self::ONLINE | self::OFFLINE
	 * @return bool 是否成功切換
	 */
	private static function switch_status( \WC_Product $product, string $direction ): bool {
		$is_offline = self::OFFLINE === $direction;
		$new_status = $is_offline ? 'draft' : 'publish';
		$done_key   = $is_offline ? Helper::SCHEDULE_OFFLINE_DONE_AT_META_KEY : Helper::SCHEDULE_ONLINE_DONE_AT_META_KEY;

		if ( ! self::update_status( $product, $new_status ) ) {
			return false;
		}

		// 記錄自動執行時間，供後台列表 / 編輯頁顯示「已於 X 自動上/下線」（Q4=A、Q5=A）
		\update_post_meta( $product->get_id(), $done_key, time() );

		return true;
	}

	/**
	 * 實際寫入 post_status（失敗時記錄 log）
	 *
	 * 一律走 wp_update_post()（內部自動 clean_post_cache），不使用 raw SQL 直寫。
	 *
	 * @param \WC_Product $product    方案商品
	 * @param string      $new_status 目標狀態 publish | draft
	 * @return bool 是否成功寫入
	 */
	private static function update_status( \WC_Product $product, string $new_status ): bool {
		$product_id = $product->get_id();

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

		return true;
	}
}
