<?php
/**
 * AccessPass Resource Loader（Issue #252）
 *
 * 初始化課程權限包相關模組，並負責既有站台升級時的補建表（版本閘門 migration）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Core;

use J7\PowerCourse\AbstractTable;
use J7\PowerCourse\Plugin;
use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Resources\AccessPass\Service\ExpiringNotifier;

/** Class Loader */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** AccessPass DB 版本 option key */
	const DB_VERSION_OPTION = 'pc_access_pass_db_version';

	/**
	 * 當前 DB schema 版本
	 *
	 * 1.0.0 — 初版：建立 pc_user_access_pass 表
	 * 1.1.0 — 期限模型對齊課程 WatchLimit：postmeta limit_mode → limit_type
	 *         （permanent→unlimited / limited→fixed / follow_subscription 不變），新增 assigned 能力
	 * 1.2.0 — 手動發放 / 到期預警：pc_user_access_pass 新增 granted_by 欄位（發放者 user ID）
	 *         與 idx_user_pass_expire 索引（每日到期掃描用）
	 */
	const CURRENT_DB_VERSION = '1.2.0';

	/**
	 * 舊期限模式值 → 新期限模式值 映射（limit_mode → limit_type）
	 *
	 * @var array<string, string>
	 */
	const LIMIT_MODE_TO_TYPE_MAP = [
		'permanent'           => 'unlimited',
		'limited'             => 'fixed',
		'follow_subscription' => 'follow_subscription',
	];

	/** Constructor */
	public function __construct() {
		CPT::instance();
		Api::instance();
		ExpiringNotifier::instance();

		// 既有站台升級補建資料表：activate() 只在「啟用外掛」時觸發，
		// 單純更新版本（覆蓋檔案）不會重跑，故以 plugins_loaded + 版本比對守門補上 migration。
		//
		// priority 20 是刻意的：本 class 自己就是在 plugins_loaded:10 執行期間被建構的
		// （PluginTrait::init 把 Bootstrap 掛在 plugins_loaded，預設 priority 10）。
		// 掛回 10 等於在「正在迭代的那個 bucket」尾端追加 callback——WP 會不會執行它
		// 取決於迭代實作細節，而且只要宿主把 Bootstrap 換到更晚的 priority（PHPUnit
		// bootstrap 就是掛 20），migration 就會靜默地永遠不跑。挪到 20 讓它與 Bootstrap
		// 的實際 priority 脫鉤。
		\add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ], 20 );
	}

	/**
	 * 依版本比對決定是否需要建表
	 *
	 * 以 option 版本比對為守門，僅在版本落後或從未安裝時才跑一次冪等的建表。
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$installed = \get_option( self::DB_VERSION_OPTION );
		if ( \is_string( $installed ) && \version_compare( $installed, self::CURRENT_DB_VERSION, '>=' ) ) {
			return;
		}

		AbstractTable::create_user_access_pass_table();
		self::migrate_limit_mode_to_limit_type();
		self::migrate_add_granted_by_column();

		\update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}

	/**
	 * 期限 meta key 遷移：limit_mode → limit_type（對齊課程 WatchLimit 模型）
	 *
	 * 對所有 pc_access_pass post：讀舊 limit_mode meta，依映射寫入新 limit_type meta，再刪除舊 limit_mode meta。
	 * 值映射：permanent→unlimited、limited→fixed、follow_subscription→follow_subscription。
	 *
	 * 冪等保證：
	 *   - 無舊 limit_mode meta（已遷移 / 全新安裝）→ 略過，不動作。
	 *   - 已存在新 limit_type meta → 不覆蓋（尊重現值），僅清掉殘留舊 meta。
	 *   - 舊值不在映射表（理論上不會發生）→ 保守 fallback 為 unlimited。
	 *
	 * 全程使用 WP meta API（cache-aware），不直接 raw SQL 寫 postmeta，故無需手動 clean_post_cache。
	 *
	 * @return void
	 */
	public static function migrate_limit_mode_to_limit_type(): void {
		$pass_ids = \get_posts(
			[
				'post_type'        => CPT::POST_TYPE,
				'post_status'      => 'any',
				'numberposts'      => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
			]
		);

		foreach ( $pass_ids as $pass_id ) {
			$pass_id = (int) $pass_id;

			$old_value = \get_post_meta( $pass_id, 'limit_mode', true );
			// 無舊 meta → 已遷移或全新安裝，冪等略過
			if ( '' === $old_value || null === $old_value ) {
				continue;
			}

			// 僅在尚無新 meta 時寫入（避免覆蓋已遷移 / 已用新契約建立的值）
			$existing_new = \get_post_meta( $pass_id, 'limit_type', true );
			if ( '' === $existing_new || null === $existing_new ) {
				$new_value = self::LIMIT_MODE_TO_TYPE_MAP[ (string) $old_value ] ?? 'unlimited';
				\update_post_meta( $pass_id, 'limit_type', $new_value );
			}

			// 清除舊 meta（收斂到單一 limit_type 來源）
			\delete_post_meta( $pass_id, 'limit_mode' );
		}
	}

	/**
	 * 資料表欄位遷移：補上 granted_by 欄位與 idx_user_pass_expire 索引（DB 1.2.0）
	 *
	 * AbstractTable::create_user_access_pass_table() 開頭有 WP::is_table_exists() 早退，
	 * 既有站台的表**不會**被 dbDelta 補欄位，故必須以獨立 ALTER TABLE 處理。
	 *
	 * 冪等保證：
	 *   - 表尚不存在 → 直接跳過（全新安裝時 create_user_access_pass_table 已建出含新欄位的表）。
	 *   - 欄位 / 索引已存在 → SHOW COLUMNS / SHOW INDEX 探測後跳過，不重複 ALTER。
	 *
	 * @return void
	 */
	public static function migrate_add_granted_by_column(): void {
		global $wpdb;
		$table_name = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;

		if ( ! WP::is_table_exists( $table_name ) ) {
			return;
		}

		$has_column = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$table_name}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'granted_by'
			)
		);
		if ( ! $has_column ) {
			$wpdb->query( "ALTER TABLE `{$table_name}` ADD COLUMN granted_by bigint(20) DEFAULT NULL AFTER expire_date" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$has_index = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SHOW INDEX FROM `{$table_name}` WHERE Key_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'idx_user_pass_expire'
			)
		);
		if ( ! $has_index ) {
			$wpdb->query( "ALTER TABLE `{$table_name}` ADD KEY idx_user_pass_expire (expire_date)" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}
