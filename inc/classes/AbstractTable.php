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
	 * 創建章節續播進度 table
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_chapter_progress_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::CHAPTER_PROGRESS_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
								id bigint(20) NOT NULL AUTO_INCREMENT,
								user_id bigint(20) NOT NULL,
								chapter_id bigint(20) NOT NULL,
								course_id bigint(20) NOT NULL,
								last_position_seconds int(11) NOT NULL DEFAULT 0,
								updated_at datetime DEFAULT NULL,
								created_at datetime DEFAULT NULL,
								PRIMARY KEY  (id),
								UNIQUE KEY uq_chapter_progress_user_chapter (user_id, chapter_id),
								KEY idx_course_id (course_id),
								KEY idx_updated_at (updated_at)
						) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			\dbDelta( $sql );
		} catch ( \Throwable $th ) {
			throw new \Exception( $th->getMessage() );
		}
	}

	/**
	 * 創建使用者持有課程權限包 table（Issue #252）
	 *
	 * 對應 erm.dbml TABLE: pc_user_access_pass。
	 * 採 compute-on-read，故僅儲存「持有關係 + 到期表達式」，不展開課程 id 至 avl_course_ids。
	 * expire_date 沿用既有 expire_date 慣例（varchar）：null/"0"=永久、10 位 timestamp=限時、"subscription_{id}"=跟隨訂閱。
	 * (user_id, pass_id) 複合索引供 UPSERT 去重查找使用（idx_user_pass_lookup）。
	 *
	 * @return void
	 * @throws \Exception Exception.
	 */
	public static function create_user_access_pass_table(): void {
		try {
			global $wpdb;
			$table_name      = $wpdb->prefix . Plugin::USER_ACCESS_PASS_TABLE_NAME;
			$is_table_exists = WP::is_table_exists( $table_name );
			if ( $is_table_exists ) {
				return;
			}

			$charset_collate = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE $table_name (
								id bigint(20) NOT NULL AUTO_INCREMENT,
								user_id bigint(20) NOT NULL,
								pass_id bigint(20) NOT NULL,
								source_order_id bigint(20) DEFAULT NULL,
								expire_date varchar(30) DEFAULT NULL,
								granted_at datetime DEFAULT NULL,
								PRIMARY KEY  (id),
								KEY idx_user_pass_user (user_id),
								KEY idx_user_pass_lookup (user_id, pass_id),
								KEY idx_user_pass_pass (pass_id)
						) $charset_collate;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			\dbDelta( $sql );
		} catch ( \Throwable $th ) {
			throw new \Exception( $th->getMessage() );
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
