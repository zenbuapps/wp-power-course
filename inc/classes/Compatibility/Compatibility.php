<?php

declare(strict_types=1);

namespace J7\PowerCourse\Compatibility;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\AbstractTable;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\Powerhouse\Settings\Model\Settings as PowerhouseSettings;
use J7\WpUtils\Classes\General;


/** Class Compatibility 不同版本間的相容性設定  */
final class Compatibility
{
	use \J7\WpUtils\Traits\SingletonTrait;

	const AS_COMPATIBILITY_ACTION = 'pc_compatibility_action_scheduler';

	/** Constructor */
	public function __construct()
	{
		$scheduled_version = \get_option('pc_compatibility_action_scheduled');
		if ($scheduled_version === Plugin::$version) {
			return;
		}

		\delete_option('pc_compatibility_action_scheduled');

		// 升級成功後執行
		\add_action('upgrader_process_complete', [__CLASS__, 'compatibility']);

		// 排程只執行一次的兼容設定
		\add_action('init', [__CLASS__, 'compatibility_action_scheduler']);
		\add_action(self::AS_COMPATIBILITY_ACTION, [__CLASS__, 'compatibility']);
	}


	/**
	 * 排程只執行一次的兼容設定
	 *
	 * @return void
	 */
	public static function compatibility_action_scheduler(): void
	{
		\as_enqueue_async_action(self::AS_COMPATIBILITY_ACTION, []);
	}


	/**
	 * 執行排程
	 *
	 * @return void
	 */
	public static function compatibility(): void
	{

		self::add_post_meta_to_course_product();
		/**
		 * ============== START 相容性代碼 ==============
		 */

		// 判斷是否已經有 wp_pc_avl_chaptermeta 這張 table，沒有就建立
		AbstractTable::create_chapter_table();

		// 判斷是否已經有 wp_pc_email_records 這張 table，沒有就建立
		AbstractTable::create_email_records_table();

		// 判斷是否已經有 wp_pc_student_logs 這張 table，沒有就建立
		AbstractTable::create_student_logs_table();

		// 0.8.0 之後使用新的章節結構
		Chapter::migrate_chapter_to_new_structure();
		// 儲存章節使用的編輯器
		Chapter::set_editor_meta_to_chapter();
		Course::set_editor_meta_to_course();

		BundleProduct::set_catalog_visibility_to_hidden();

		$previous_version = (string) \get_option('pc_compatibility_action_scheduled', '0.0.1');
		// 0.9.0 之前版本
		if (version_compare($previous_version, '0.9.0', '<=')) {
			self::migration_bunny_settings();
		}

		// 0.10.0 之後使用新的設定
		if (version_compare($previous_version, '0.10.0', '<=')) {
			self::migration_settings();
		}

		// 0.11.0 之後要對  {$prefix}_pc_email_records table 新增 identifier 欄位
		if (version_compare($previous_version, '0.11.0', '<=')) {
			self::extend_email_records_table_identifier_column();
		}

		/**
		 * ============== END 相容性代碼 ==============
		 */

		// ❗不要刪除此行，註記已經執行過相容設定
		\update_option('pc_compatibility_action_scheduled', Plugin::$version);
		\wp_cache_flush();
		Plugin::logger(Plugin::$version . ' 已執行兼容性設定', 'info');
	}


	/**
	 * 將 bunny_settings 從 option 個別欄位轉移到 option 的 powerhouse_settings array 底下
	 *
	 * @return void
	 */
	private static function migration_bunny_settings(): void
	{
		$bunny_library_id     = \get_option('bunny_library_id');
		$bunny_cdn_hostname   = \get_option('bunny_cdn_hostname');
		$bunny_stream_api_key = \get_option('bunny_stream_api_key');

		if (!$bunny_library_id && !$bunny_cdn_hostname && !$bunny_stream_api_key) {
			return;
		}

		$powerhouse_settings                         = PowerhouseSettings::instance()->to_array();
		$powerhouse_settings['bunny_library_id']     = $bunny_library_id;
		$powerhouse_settings['bunny_cdn_hostname']   = $bunny_cdn_hostname;
		$powerhouse_settings['bunny_stream_api_key'] = $bunny_stream_api_key;

		PowerhouseSettings::instance()->partial_update($powerhouse_settings);

		\delete_option('bunny_library_id');
		\delete_option('bunny_cdn_hostname');
		\delete_option('bunny_stream_api_key');
	}


	/**
	 * Add Post Meta To Course Product
	 * 把每個商品都標示，是否為課程商品
	 *
	 * @return void
	 */
	public static function add_post_meta_to_course_product(): void
	{
		$args = [
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_is_course',
					'compare' => 'NOT EXISTS',
				],
			],
		];

		$ids = \get_posts($args);

		foreach ($ids as $id) {
			\add_post_meta($id, '_is_course', 'no');
		}
	}


	/**
	 * 將設定從 option 轉移到 power_course_settings 這個 option_name 底下
	 *
	 * @return void
	 */
	private static function migration_settings(): void
	{
		$settings = Settings::instance();
		// 取得 $settings 的 public 屬性
		$properties = get_object_vars($settings);
		foreach ($properties as $property => $default_value) {
			$original_value      = \get_option($property, $default_value);
			$settings->$property = General::to_same_type($settings->$property, $original_value);
		}
		$settings->save();
		foreach ($properties as $property => $default_value) {
			\delete_option($property);
		}
	}


	/**
	 * 對  {$prefix}_pc_email_records table 新增 identifier 欄位
	 *
	 * @return void
	 */
	private static function extend_email_records_table_identifier_column(): void
	{
		global $wpdb;

		$table_name = $wpdb->prefix . Plugin::EMAIL_RECORDS_TABLE_NAME;
		try {
			// 檢查表格是否存在
			if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name))) {
				Plugin::logger("表格 {$table_name} 不存在，無法新增 identifier 欄位", 'critical');
				return;
			}

			// 檢查 identifier 欄位是否已存在
			$column_exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
				WHERE TABLE_SCHEMA = %s
				AND TABLE_NAME = %s
				AND COLUMN_NAME = 'identifier'",
					DB_NAME,
					$table_name
				)
			);

			if ($column_exists > 0) {
				// identifier 欄位已存在於表格 table 中，跳過新增
				return;
			}

			// 新增欄位 - 表格名稱已經過驗證，使用反引號包圍確保安全
			// 這是相容性更新，表格名稱來自常數，已經過驗證
			$result = $wpdb->query("ALTER TABLE `{$table_name}` ADD COLUMN `identifier` varchar(255) DEFAULT NULL"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ($result === false) {
				Plugin::logger(
					'新增 identifier 欄位失敗',
					'critical',
					[
						'table_name' => $table_name,
						'error'      => $wpdb->last_error,
						'last_query' => $wpdb->last_query,
					]
				);
				return;
			}

			Plugin::logger(
				"成功新增 identifier 欄位到表格 {$table_name}",
				'info',
				['table_name' => $table_name]
			);
		} catch (\Throwable $th) {
			Plugin::logger(
				'新增 identifier 欄位時發生異常',
				'critical',
				[
					'table_name' => $table_name,
					'error'      => $th->getMessage(),
				]
			);
		}
	}
}
