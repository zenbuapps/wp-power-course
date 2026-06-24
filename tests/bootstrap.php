<?php
/**
 * PHPUnit 整合測試引導文件
 * 載入順序（重要，不可更改）：
 * 1. Composer autoloader
 * 2. 解析 WP_TESTS_DIR 路徑
 * 3. 確認 WP 測試套件檔案存在
 * 4. 定義 WP_TESTS_PHPUNIT_POLYFILLS_PATH
 * 5. 載入 WP 測試函式 (functions.php)
 * 6. 透過 muplugins_loaded hook 載入插件
 * 7. 載入 WP 測試 bootstrap (bootstrap.php)
 */

declare( strict_types=1 );

// 載入 Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Shim：wp_force_delete_post()（WP core 無此函式）
// AccessPass 整合測試的 tear_down() 以此函式清理 pc_access_pass CPT；
// 等同於 wp_delete_post( $id, true )（略過垃圾桶直接永久刪除）。
// 函式宣告在此（檔案頂部），函式 body 於測試執行時才呼叫，屆時 WP 已完整載入，wp_delete_post 必然存在。
if ( ! function_exists( 'wp_force_delete_post' ) ) {
	/**
	 * 永久刪除文章（測試環境 shim）
	 *
	 * @param int $post_id 文章 ID
	 * @return \WP_Post|false|null 同 wp_delete_post 的回傳值
	 */
	function wp_force_delete_post( int $post_id ) {
		return \wp_delete_post( $post_id, true );
	}
}

// 取得 wp-phpunit 提供的測試目錄路徑
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	// 優先使用 wp-phpunit vendor 套件提供的路徑
	$_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

// 確認 WP 測試套件存在
if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "找不到 WordPress 測試套件：{$_tests_dir}/includes/functions.php\n";
	exit( 1 );
}

// 設定 PHPUnit Polyfills 路徑（yoast/phpunit-polyfills 需要）
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );

// 載入 WP 測試函式
require_once "{$_tests_dir}/includes/functions.php";

/**
 * 在 WordPress muplugins_loaded 時載入插件
 * 順序：WooCommerce → Powerhouse → Power Course
 */
function _power_course_manually_load_plugin(): void {
	// Stub WordPress Abilities API（WP 6.9 前尚未進 core，mcp-adapter 會用到）
	// 必須在此宣告而非檔案頂部：WP core 於 wp-settings.php 載入 abilities-api.php（muplugins_loaded 之前），
	// 故 6.9+ 時 function_exists 守衛在此處才生效、避免與 core 重複宣告 fatal；6.8 時 core 無此函式則補上 stub。
	if ( ! function_exists( 'wp_get_ability' ) ) {
		function wp_get_ability( string $name ): ?array { return null; }
	}
	if ( ! function_exists( 'wp_get_abilities' ) ) {
		function wp_get_abilities(): array { return []; }
	}
	if ( ! function_exists( 'wp_register_ability' ) ) {
		function wp_register_ability( string $name, array $args = [] ): void {}
	}
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		function wp_register_ability_category( string $slug, array $args = [] ): void {}
	}

	// 1. 載入 WooCommerce
	$woo_path = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	if ( file_exists( $woo_path ) ) {
		require_once $woo_path;
	} else {
		echo "警告：WooCommerce 不存在於 {$woo_path}\n";
	}

	// 2. 載入 Powerhouse（提供 J7\WpUtils 工具庫）
	$powerhouse_path = WP_PLUGIN_DIR . '/powerhouse/plugin.php';
	if ( file_exists( $powerhouse_path ) ) {
		require_once $powerhouse_path;
	} else {
		echo "警告：Powerhouse 不存在於 {$powerhouse_path}\n";
	}

	// 3. 載入 Power Course plugin
	require dirname( __DIR__ ) . '/plugin.php';
}

tests_add_filter( 'muplugins_loaded', '_power_course_manually_load_plugin' );

// 設定測試語系為 zh_TW，確保 PluginTrait::load_textdomain() 載入 power-course-zh_TW.mo
tests_add_filter( 'locale', function () {
	return 'zh_TW';
} );

/**
 * 在 plugins_loaded 後強制初始化 Bootstrap
 * 原因：PluginTrait::check_required_plugins() 會呼叫 is_j7rp_complete()
 * 但測試環境中 WooCommerce 和 Powerhouse 是透過 muplugins_loaded 手動載入
 * 並未在 active_plugins DB 選項中登記，導致 is_j7rp_complete() 回傳 false
 * 進而阻止 Bootstrap::instance() 被呼叫，LifeCycle hooks 未被註冊
 * 解決：在 plugins_loaded 之後直接呼叫 Bootstrap::instance()
 */
function _power_course_force_bootstrap(): void {
	if ( class_exists( 'J7\PowerCourse\Bootstrap' ) ) {
		\J7\PowerCourse\Bootstrap::instance();
	}
}

tests_add_filter( 'plugins_loaded', '_power_course_force_bootstrap', 20 );

/**
 * 在 WordPress plugins_loaded 後（tests_loaded 時）
 * 建立自訂資料表（整合測試需要）
 */
function _power_course_create_tables(): void {
	if ( class_exists( 'J7\PowerCourse\Plugin' ) ) {
		require_once dirname( __DIR__ ) . '/inc/classes/AbstractTable.php';
		\J7\PowerCourse\AbstractTable::create_course_table();
		\J7\PowerCourse\AbstractTable::create_chapter_table();
		\J7\PowerCourse\AbstractTable::create_email_records_table();
		\J7\PowerCourse\AbstractTable::create_student_logs_table();
		\J7\PowerCourse\AbstractTable::create_chapter_progress_table();
		\J7\PowerCourse\AbstractTable::create_user_access_pass_table();
	}
}

tests_add_filter( 'after_setup_theme', '_power_course_create_tables' );

// 啟動 WP 測試套件
require "{$_tests_dir}/includes/bootstrap.php";
