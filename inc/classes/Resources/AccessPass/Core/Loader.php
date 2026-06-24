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

	/** 當前 DB schema 版本 */
	const CURRENT_DB_VERSION = '1.0.0';

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
		\update_option( self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION );
	}
}
