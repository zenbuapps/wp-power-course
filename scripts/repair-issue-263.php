<?php
/**
 * Issue #263 一次性回填腳本
 *
 * 病灶：WooCommerce 傳統結帳的 resume 分支（重試付款）會先 remove_order_items()
 * 再從購物車重建 items，且走 data store update() 不再觸發 woocommerce_new_order，
 * 導致 power-course 寫在該 hook 的 `_bind_courses_data` item meta，
 * 以及程式用 $order->add_product() 塞進去的「銷售方案內含商品 order item」永久遺失
 * → 訂單完成時課程不開通、開通通知信也不寄。
 *
 * 本腳本負責「找出受害訂單 → 補回內含商品與 item meta → 重跑授權」。
 *
 * ⚠️ 這是一次性腳本，**不進 autoload**、不註冊任何 hook。修復完可刪。
 *
 * 用法：
 *   wp eval-file scripts/repair-issue-263.php --dry-run
 *   wp eval-file scripts/repair-issue-263.php --apply
 *   wp eval-file scripts/repair-issue-263.php --apply --orders=1234,1235
 *   wp eval-file scripts/repair-issue-263.php --apply --no-emails
 *
 * 參數：
 *   --dry-run     只列出受害訂單，不做任何寫入（預設）
 *   --apply       實際執行修復
 *   --orders=...  只處理指定訂單（逗號分隔），略過自動偵測
 *   --no-emails   修復期間暫停 power-course 的開通通知信（避免補寄大量舊信）
 *
 * @package J7\PowerCourse
 */

declare( strict_types=1 );

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "此腳本必須透過 wp-cli 執行：wp eval-file scripts/repair-issue-263.php --dry-run\n";
	return;
}

if ( ! class_exists( \J7\PowerCourse\Resources\Order::class ) ) {
	\WP_CLI::error( '找不到 J7\PowerCourse\Resources\Order，請確認 power-course 外掛已啟用。' );
}

global $wpdb;

// ---------------------------------------------------------------------------
// 參數解析
// ---------------------------------------------------------------------------

$argv_all   = $GLOBALS['argv'] ?? [];
$apply      = in_array( '--apply', $argv_all, true );
$no_emails  = in_array( '--no-emails', $argv_all, true );
$only_ids   = [];

foreach ( $argv_all as $arg ) {
	if ( is_string( $arg ) && str_starts_with( $arg, '--orders=' ) ) {
		$only_ids = array_values(
			array_filter(
				array_map( 'intval', explode( ',', substr( $arg, 9 ) ) )
			)
		);
	}
}

// ---------------------------------------------------------------------------
// 偵測受害訂單
//
// 判準：只要 _handle_add_course_item_meta_by_order_item() 跑過，
// 課程商品必有 `_is_course` item meta、有綁定課程的必有 `_bind_courses_data`。
// 兩者皆缺 = 該 item 從未被處理過。
//
// wp_woocommerce_order_items / order_itemmeta 兩張表在 CPT 與 HPOS 下都共用，
// 故單一 SQL 即可，不需分流。
// ---------------------------------------------------------------------------

if ( $only_ids ) {
	$order_ids = $only_ids;
	\WP_CLI::log( sprintf( '使用 --orders 指定的 %d 筆訂單，略過自動偵測。', count( $order_ids ) ) );
} else {
	$sql = "
		SELECT DISTINCT oi.order_id
		FROM {$wpdb->prefix}woocommerce_order_items AS oi
		INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pim
		        ON pim.order_item_id = oi.order_item_id AND pim.meta_key = '_product_id'
		LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS bcm
		        ON bcm.order_item_id = oi.order_item_id AND bcm.meta_key = '_bind_courses_data'
		LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS icm
		        ON icm.order_item_id = oi.order_item_id AND icm.meta_key = '_is_course'
		LEFT JOIN {$wpdb->postmeta} AS pm_course
		        ON pm_course.post_id = pim.meta_value AND pm_course.meta_key = '_is_course'
		LEFT JOIN {$wpdb->postmeta} AS pm_bind
		        ON pm_bind.post_id = pim.meta_value AND pm_bind.meta_key = 'bind_courses_data'
		LEFT JOIN {$wpdb->postmeta} AS pm_bundle
		        ON pm_bundle.post_id = pim.meta_value AND pm_bundle.meta_key = 'pbp_product_ids'
		WHERE oi.order_item_type = 'line_item'
		  AND bcm.meta_id IS NULL
		  AND icm.meta_id IS NULL
		  AND (
		        pm_course.meta_value IN ('yes','on')
		     OR (pm_bind.meta_value IS NOT NULL AND pm_bind.meta_value <> '')
		     OR (pm_bundle.meta_value IS NOT NULL AND pm_bundle.meta_value <> '')
		  )
		ORDER BY oi.order_id DESC
	";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- 全部為 $wpdb 前綴常數，無使用者輸入
	$order_ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) );

	\WP_CLI::log( sprintf( '自動偵測到 %d 筆疑似受害訂單。', count( $order_ids ) ) );
}

if ( ! $order_ids ) {
	\WP_CLI::success( '沒有需要修復的訂單。' );
	return;
}

// ---------------------------------------------------------------------------
// 選用：暫停開通通知信
//
// 回填會補開通課程 → 觸發 LifeCycle::ADD_STUDENT_TO_COURSE_ACTION → 可能寄出開通信。
// 少量訂單建議讓信寄出（學員本來就該收到）；大量回填時用 --no-emails 擋掉。
// ---------------------------------------------------------------------------

if ( $apply && $no_emails ) {
	add_filter( 'power_email_can_send', '__return_false', PHP_INT_MAX );
	\WP_CLI::log( '已暫停開通通知信（--no-emails）。' );
}

// ---------------------------------------------------------------------------
// 執行
// ---------------------------------------------------------------------------

$order_resource = \J7\PowerCourse\Resources\Order::instance();

// 授權狀態集合（course_access_trigger + completed）。
// add_meta_to_avl_course() 被**直接呼叫**時不看訂單狀態，
// 沒有這道閘門會把課程送給已取消 / 已退款 / 從未付款的客戶。
$grantable_statuses = \J7\PowerCourse\Resources\AccessPass\Service\Grant::grant_statuses();

$repaired       = 0;
$granted        = 0;
$items_only     = 0;
$skipped        = 0;

foreach ( $order_ids as $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order instanceof \WC_Order ) {
		\WP_CLI::warning( sprintf( '訂單 %d 不存在或非 WC_Order，略過。', $order_id ) );
		++$skipped;
		continue;
	}

	$before_count = count( $order->get_items() );
	$status       = $order->get_status();

	$is_grantable = in_array( $status, $grantable_statuses, true );

	if ( ! $apply ) {
		\WP_CLI::log(
			sprintf(
				'[dry-run] 訂單 #%d：狀態 %s（%s）、目前 %d 個 item、客戶 %d',
				$order_id,
				$status,
				$is_grantable ? '會補 item 並重跑授權' : '非授權狀態 → 只補 item，不授權',
				$before_count,
				(int) $order->get_customer_id()
			)
		);
		continue;
	}

	try {
		// 1. 補回方案內含商品與 item meta（冪等：已展開過的不會重複塞；只補漏不覆寫快照）
		$order_resource->repair_order_items( $order );

		// 2. 重跑授權 —— 只對「本來就該授權」的狀態執行。
		//    add_meta_to_avl_course() 直接呼叫時不看狀態，
		//    對 cancelled / refunded / pending 訂單跑下去會把課程送給不該有的人。
		if ( $is_grantable ) {
			$order_resource->add_meta_to_avl_course( $order_id );
			++$granted;
		} else {
			++$items_only;
		}
	} catch ( \Throwable $e ) {
		\WP_CLI::warning( sprintf( '訂單 #%d 修復失敗：%s', $order_id, $e->getMessage() ) );
		++$skipped;
		continue;
	}

	$after       = wc_get_order( $order_id );
	$after_count = $after instanceof \WC_Order ? count( $after->get_items() ) : 0;

	\WP_CLI::log(
		sprintf(
			'訂單 #%d 已修復：item %d → %d（%s）',
			$order_id,
			$before_count,
			$after_count,
			$is_grantable ? '已重跑授權' : '非授權狀態，未授權'
		)
	);
	++$repaired;
}

if ( ! $apply ) {
	\WP_CLI::success(
		sprintf( 'dry-run 完成，共 %d 筆待修復。加上 --apply 才會實際寫入。', count( $order_ids ) )
	);
	return;
}

\WP_CLI::success(
	sprintf(
		'完成：修復 %d 筆（其中 %d 筆已重跑授權、%d 筆非授權狀態只補 item）、略過 %d 筆。',
		$repaired,
		$granted,
		$items_only,
		$skipped
	)
);
