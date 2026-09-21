<?php
/**
 * Assignment Resource Loader（Issue #131）
 *
 * 初始化作業模組，並補掛自訂表升級檢查（DEC-04）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\PowerCourse\AbstractTable;

/** Class Loader */
final class Loader {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		CPT::instance();
		Api::instance();
		MyApi::instance();
		LifeCycle::instance();

		// 作業三張自訂表（DB 1.2.0）的升級補建：AbstractTable::maybe_upgrade 原本只掛 admin_init，
		// 學員端 REST 請求不會觸發，故另掛 plugins_loaded。
		// priority 20 的理由同 AccessPass\Core\Loader：本 class 在 plugins_loaded:10 期間被建構，
		// 掛回 10 會隨宿主 Bootstrap 的 priority 而靜默失效。
		\add_action( 'plugins_loaded', [ AbstractTable::class, 'maybe_upgrade' ], 20 );
	}
}
