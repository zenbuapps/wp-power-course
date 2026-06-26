<?php
/**
 * AccessPass Resource Loader（Issue #252）
 *
 * 初始化課程權限包相關模組，並負責既有站台升級時的補建表（版本閘門 migration）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Core;

use J7\PowerCourse\AbstractTable;

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
	 */
	const CURRENT_DB_VERSION = '1.1.0';

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

		// 既有站台升級補建資料表：activate() 只在「啟用外掛」時觸發，
		// 單純更新版本（覆蓋檔案）不會重跑，故以 plugins_loaded + 版本比對守門補上 migration。
		\add_action( 'plugins_loaded', [ __CLASS__, 'maybe_upgrade' ] );
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
}
