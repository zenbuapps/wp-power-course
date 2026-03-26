<?php

namespace J7\PowerCourse;

use J7\WpUtils\Classes\WP;

if ( class_exists( 'AbstractTable' ) ) {
	return;
}

/**
 * 抽象類別，用來創建 table
 * 功能
 * 1. 創建 course table
 * 2. 創建 chapter table
 * 3. 創建 email records table
 * 4. 創建 student logs table
 */
abstract class AbstractTable {
	/**
	 * 創建課程 meta table
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_course_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::COURSE_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$wpdb->avl_coursemeta = $table_name;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
										meta_id bigint(20) NOT NULL AUTO_INCREMENT,
										post_id bigint(20) NOT NULL,
										user_id bigint(20) NOT NULL,
										meta_key varchar(255) DEFAULT NULL,
										meta_value longtext,
										PRIMARY KEY  (meta_id),
										KEY post_id (post_id),
										KEY user_id (user_id),
										KEY meta_key (meta_key(191))
								) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = \dbDelta($sql);
		} catch (\Throwable $th) {
			throw new \Exception($th->getMessage());
		}
	}


	/**
	 * 創建章節 meta table
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_chapter_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::CHAPTER_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$wpdb->avl_chaptermeta = $table_name;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
										meta_id bigint(20) NOT NULL AUTO_INCREMENT,
										post_id bigint(20) NOT NULL,
										user_id bigint(20) NOT NULL,
										meta_key varchar(255) DEFAULT NULL,
										meta_value longtext,
										PRIMARY KEY  (meta_id),
										KEY post_id (post_id),
										KEY user_id (user_id),
										KEY meta_key (meta_key(191))
								) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = \dbDelta($sql);
		} catch (\Throwable $th) {
			throw new \Exception($th->getMessage());
		}
	}

	/**
	 * 創建 email records table
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_email_records_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::EMAIL_RECORDS_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$wpdb->email_records = $table_name;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
										id bigint(20) NOT NULL AUTO_INCREMENT,
										post_id bigint(20) NOT NULL,
										user_id bigint(20) NOT NULL,
										email_id bigint(20) NOT NULL,
										email_subject varchar(255) DEFAULT NULL,
										trigger_at varchar(30) DEFAULT NULL,
										mark_as_sent tinyint(1) DEFAULT 0,
										identifier varchar(255) DEFAULT NULL,
										email_date datetime DEFAULT NULL,
										PRIMARY KEY  (id),
										KEY post_id (post_id),
										KEY user_id (user_id),
										KEY email_id (email_id)
								) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = \dbDelta($sql);
		} catch (\Throwable $th) {
			throw new \Exception($th->getMessage());
		}
	}


	/**
	 * 創建學員課程紀錄 table
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_student_logs_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::STUDENT_LOGS_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$wpdb->student_logs = $table_name;

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
										id bigint(20) NOT NULL AUTO_INCREMENT,
										user_id bigint(20) NOT NULL,
										course_id bigint(20) NOT NULL,
										chapter_id bigint(20) DEFAULT NULL,
										log_type varchar(20) DEFAULT NULL,
										title varchar(255) DEFAULT NULL,
										content longtext DEFAULT NULL,
										user_ip varchar(100) DEFAULT NULL,
										created_at datetime DEFAULT NULL,
										PRIMARY KEY  (id),
										KEY user_id (user_id),
										KEY course_id (course_id),
										KEY chapter_id (chapter_id)
								) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$result = \dbDelta($sql);
		} catch (\Throwable $th) {
			throw new \Exception($th->getMessage());
		}
	}
}
