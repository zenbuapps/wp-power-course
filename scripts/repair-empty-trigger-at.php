<?php
/**
 * Pe_email 的 trigger_at 空值一次性修復腳本
 *
 * 病灶：`Email::__construct` 舊版把 trigger_at 的讀取放在 condition 早退**之後**，
 * 所以「還沒設過 condition 的信」讀出來永遠是空字串 → 前端 Select 顯示空白 →
 * 使用者一存檔就把 'course_granted' 洗成 ''。
 *
 * 而 `At::schedule_email()` 是直接 `get_posts([ 'meta_key' => 'trigger_at',
 * 'meta_value' => {slug} ])` 查 postmeta，**完全不經過 Email 物件**。
 * 也就是說：程式端的四層防線只能保證「以後不會再變成空的」，
 * DB 裡已經是空字串（或整個 meta 不存在）的舊信，還是永遠不會被排程。
 * 那些信必須由本腳本補上預設值。
 *
 * ⚠️ 一次性腳本，**不進 autoload**、不註冊任何 hook。修復完可刪。
 *
 * 用法：
 *   wp eval-file scripts/repair-empty-trigger-at.php --dry-run
 *   wp eval-file scripts/repair-empty-trigger-at.php --apply
 *   wp eval-file scripts/repair-empty-trigger-at.php --apply --default=chapter_finish
 *
 * 參數：
 *   --dry-run    只列出受影響的信，不做任何寫入（預設）
 *   --apply      實際寫入
 *   --default=X  要補上的 slug（預設 course_granted，需在 CPT::SUPPORTED_TRIGGER_SLUGS 內）
 *
 * @package J7\PowerCourse
 */

declare( strict_types=1 );

use J7\PowerCourse\PowerEmail\Resources\Email\CPT as EmailCPT;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	echo "此腳本必須透過 wp-cli 執行：wp eval-file scripts/repair-empty-trigger-at.php --dry-run\n";
	return;
}

if ( ! class_exists( EmailCPT::class ) ) {
	\WP_CLI::error( '找不到 PowerEmail\Resources\Email\CPT，請確認 power-course 外掛已啟用。' );
}

// ---------------------------------------------------------------------------
// 參數解析
// ---------------------------------------------------------------------------

$argv_all = $GLOBALS['argv'] ?? [];
$apply    = in_array( '--apply', $argv_all, true );
$default  = 'course_granted';

foreach ( $argv_all as $arg ) {
	if ( is_string( $arg ) && str_starts_with( $arg, '--default=' ) ) {
		$default = substr( $arg, 10 );
	}
}

if ( ! in_array( $default, EmailCPT::SUPPORTED_TRIGGER_SLUGS, true ) ) {
	\WP_CLI::error(
		sprintf(
			'--default 必須是下列其中之一：%s',
			implode( ', ', EmailCPT::SUPPORTED_TRIGGER_SLUGS )
		)
	);
}

// ---------------------------------------------------------------------------
// 找出受影響的信
//
// 兩種都要抓：
// 1. trigger_at meta 存在但值為空字串（被前端洗掉的）
// 2. trigger_at meta 根本不存在（更舊的資料，或 meta_input 沒生效）
// 用 get_posts 的 meta_query 一次涵蓋。
// ---------------------------------------------------------------------------

/** @var array<int> $broken_ids */
$broken_ids = get_posts(
	[
		'post_type'      => EmailCPT::POST_TYPE,
		'posts_per_page' => -1,
		'post_status'    => 'any',
		'fields'         => 'ids',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- 一次性維運腳本
		'meta_query'     => [
			'relation' => 'OR',
			[
				'key'     => 'trigger_at',
				'value'   => '',
				'compare' => '=',
			],
			[
				'key'     => 'trigger_at',
				'compare' => 'NOT EXISTS',
			],
		],
	]
);

$broken_ids = array_map( 'intval', (array) $broken_ids );

\WP_CLI::log( sprintf( '找到 %d 封 trigger_at 為空或未設定的信。', count( $broken_ids ) ) );

if ( ! $broken_ids ) {
	\WP_CLI::success( '沒有需要修復的信件。' );
	return;
}

// ---------------------------------------------------------------------------
// 執行
// ---------------------------------------------------------------------------

$repaired = 0;

foreach ( $broken_ids as $email_id ) {
	// 變數名避開 $title / $status —— 那是 WP 全域，PHPCS 的 GlobalVariablesOverride 會擋
	$email_title  = (string) get_the_title( $email_id );
	$email_status = (string) get_post_status( $email_id );
	$state        = metadata_exists( 'post', $email_id, 'trigger_at' ) ? '空字串' : 'meta 不存在';

	if ( ! $apply ) {
		\WP_CLI::log(
			sprintf(
				'[dry-run] #%d「%s」狀態 %s、trigger_at %s → 將補成 %s',
				$email_id,
				$email_title,
				$email_status,
				$state,
				$default
			)
		);
		continue;
	}

	update_post_meta( $email_id, 'trigger_at', $default );
	++$repaired;

	\WP_CLI::log( sprintf( '#%d「%s」trigger_at %s → %s', $email_id, $email_title, $state, $default ) );
}

if ( ! $apply ) {
	\WP_CLI::success(
		sprintf( 'dry-run 完成，共 %d 封待修復。加上 --apply 才會實際寫入。', count( $broken_ids ) )
	);
	return;
}

\WP_CLI::success(
	sprintf(
		'完成：%d 封已補上 trigger_at = %s。請到後台逐一確認觸發時機是否符合預期。',
		$repaired,
		$default
	)
);
