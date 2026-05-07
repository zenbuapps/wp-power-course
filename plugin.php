<?php

/**
 * Plugin Name:       Power Course | WordPress 最好用的課程外掛
 * Plugin URI:        https://github.com/zenbuapps/wp-power-course
 * Description:       WordPress 最好用的課程外掛
 * Version:           1.4.0
 * Requires at least: 5.7
 * Requires PHP:      8.0
 * Author:            J7
 * Author URI:        https://github.com/j7-dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       power-course
 * Domain Path:       /languages
 * Tags: LMS, online course, online learning, e-learning, e-learning platform
 */

declare(strict_types=1);

namespace J7\PowerCourse;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

if (!\class_exists('J7\PowerCourse\Plugin')) {
	$autoload = __DIR__ . '/vendor/autoload.php';
	if (! \is_readable($autoload)) {
		return;
	}
	require_once $autoload;

	/**
	 * Class Plugin
	 */
	final class Plugin {




		/** @var bool  */
		public static $is_local = false;

		use \J7\WpUtils\Traits\PluginTrait;
		use \J7\WpUtils\Traits\SingletonTrait;

		const COURSE_TABLE_NAME           = 'pc_avl_coursemeta';
		const CHAPTER_TABLE_NAME          = 'pc_avl_chaptermeta';
		const EMAIL_RECORDS_TABLE_NAME    = 'pc_email_records';
		const STUDENT_LOGS_TABLE_NAME     = 'pc_student_logs';
		const CHAPTER_PROGRESS_TABLE_NAME = 'pc_chapter_progress';

		/**
		 * Constructor
		 */
		public function __construct() {
			self::$is_local            = \wp_get_environment_type() === 'local';
			self::$template_page_names = [ 'course-product', 'classroom', 'my-account', '404' ];

			$this->required_plugins = [
				[
					'name'     => 'WooCommerce',
					'slug'     => 'woocommerce',
					'required' => true,
					'version'  => '7.6.0',
				],
				[
					'name'     => 'Powerhouse',
					'slug'     => 'powerhouse',
					'source'   => 'https://github.com/zenbuapps/wp-powerhouse/releases/latest/download/powerhouse.zip',
					'version'  => '3.3.41',
					'required' => true,
				],
			];

			$this->init(
				[
					'app_name'    => 'Power Course',
					'github_repo' => 'https://github.com/zenbuapps/wp-power-course',
					'callback'    => [ Bootstrap::class, 'instance' ],
					'capability'  => 'manage_woocommerce',
				]
			);
		}

		/**
		 * Activate
		 * 啟用時創建 avl_coursemeta table 與 MCP 相關資料表
		 *
		 * @return void
		 * @throws \Exception Exception.
		 */
		public function activate(): void {
			require_once __DIR__ . '/inc/classes/AbstractTable.php';
			AbstractTable::create_course_table();
			AbstractTable::create_chapter_table();
			AbstractTable::create_email_records_table();
			AbstractTable::create_student_logs_table();
			AbstractTable::create_chapter_progress_table();

			// 建立 MCP 資料表（wp_pc_mcp_tokens, wp_pc_mcp_activity）
			Api\Mcp\Migration::install();

			self::set_default_product_meta();
		}

		/**
		 * 設定預設的產品 meta
		 * 將所有產品 _is_course 初始值設為 no
		 *
		 * @return void
		 */
		private static function set_default_product_meta(): void {
			$post_ids = \get_posts(
				[
					'post_type'   => 'product',
					'numberposts' => -1,
					'fields'      => 'ids',
				]
			);
			foreach ($post_ids as $post_id) {
				$is_course = \get_post_meta($post_id, '_is_course', true);
				if (!$is_course) {
					\update_post_meta($post_id, '_is_course', 'no');
				}
			}
		}


		/**
		 * 記錄日誌
		 *
		 * @param string               $message 訊息
		 * @param string               $level 日誌等級
		 * @param array<string, mixed> $context 上下文
		 * @param int                  $trace_limit 堆疊限制
		 * @return void
		 */
		public static function logger( string $message, string $level = 'debug', array $context = [], int $trace_limit = 0 ): void {
			\J7\WpUtils\Classes\WC::logger($message, $level, $context, 'power-course', $trace_limit);
		}
	}

	// text domain 由 wp-plugin-trait >= 0.2.20 的 PluginTrait::load_textdomain() 自動載入，無需手動呼叫
	Plugin::instance();
}
