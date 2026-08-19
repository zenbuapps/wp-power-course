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

/*
 * 補載 power-course 的翻譯（.mo）—— 測試環境專用
 *
 * 為什麼不能靠 PluginTrait::load_textdomain()：
 * wp-env 容器內的 Powerhouse 由 .wp-env.json 從 GitHub release zip 安裝（3.5.4），
 * 其挾帶的 vendor/j7-dev/wp-plugin-trait 仍是舊版，load_textdomain() 送出的是
 *     \load_plugin_textdomain( self::$snake, false, self::$dir . '/languages' );
 * 兩個參數都錯 —— domain 應為 kebab 的 'power-course'（不是 'power_course'），
 * 第三參數應為「相對 WP_PLUGIN_DIR」的路徑（不是絕對路徑 self::$dir），
 * 所以在容器內永遠回 false，textdomain 從未載入。
 * （power-course 自己 vendor 內的 wp-plugin-trait 0.2.20 已修好，但 Powerhouse 的
 *  autoloader 先註冊，實際被載入的是舊版那一份。）
 *
 * 為什麼正式站沒事：外掛已啟用，WP_Textdomain_Registry 解析得到
 * wp-content/plugins/power-course/languages/，__() 走 WP 6.7 just-in-time 載入仍回中文。
 * PHPUnit 環境的外掛不在 active_plugins（由 muplugins_loaded 手動 require），
 * registry->get() 回 false，JIT 也救不回來。
 *
 * 放在 WP 測試套件啟動「之後」：此時 init 已跑完，不會觸發 WP 6.7 的
 * _doing_it_wrong（translation loaded too early）警告。
 *
 * 找不到 / 載不動 .mo 就直接中止，不允許靜默 fallback 成英文 msgid ——
 * 否則所有斷言中文輸出的測試會變成假紅，或被誤改成斷言英文而失去驗證翻譯的能力。
 */
$_pc_locale = \determine_locale();
$_pc_mofile = \dirname( __DIR__ ) . "/languages/power-course-{$_pc_locale}.mo";

if ( ! \is_readable( $_pc_mofile ) ) {
	echo "找不到 power-course 的 {$_pc_locale} 翻譯檔：{$_pc_mofile}\n";
	echo "請先執行 pnpm run i18n:build 後再跑測試。\n";
	exit( 1 );
}

if ( ! \load_textdomain( 'power-course', $_pc_mofile, $_pc_locale ) ) {
	echo "載入 power-course 翻譯失敗：{$_pc_mofile}\n";
	exit( 1 );
}
