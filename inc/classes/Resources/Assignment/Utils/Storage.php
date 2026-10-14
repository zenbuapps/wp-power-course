<?php
/**
 * 作業附件受保護儲存目錄（Issue #131，DEC-09）
 *
 * - 根目錄：常數 PC_ASSIGNMENT_STORAGE_DIR（有定義且非空時）> wp_upload_dir()['basedir'] . '/power-course-private/assignments/'，
 *   最後再經 power_course_assignment_storage_dir filter（站長可搬到 web root 外）
 * - 根目錄建立時寫入 .htaccess（Apache 2.2 + 2.4）、web.config（IIS）、index.php（防列目錄）
 * - 實體檔名為 64 位隨機 hex、不帶副檔名，存於 {root}/{Y}/{m}/ 之下；
 *   DB 的 stored_path 存「相對於根目錄」的路徑（erm.dbml pc_assignment_attachments.stored_path Note）
 * - Nginx 不吃 .htaccess（R-04），不可猜的檔名是最後一道防線；API 永遠不輸出 stored_path 或 URL
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

/**
 * Class Storage
 */
final class Storage {

	/** 儲存根目錄的 filter 名稱 */
	public const DIR_FILTER = 'power_course_assignment_storage_dir';

	/** 覆寫儲存根目錄的常數名稱（一律 defined() 守門，phpstan.neon 忽略 Constant not found，拼錯抓不到） */
	private const DIR_CONSTANT = 'PC_ASSIGNMENT_STORAGE_DIR';

	/** Apache 2.2 + 2.4 拒絕直連 */
	private const HTACCESS = "# Power Course assignment attachments: deny direct access\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder deny,allow\n\tDeny from all\n</IfModule>\n";

	/** IIS 拒絕直連 */
	private const WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n\t<system.webServer>\n\t\t<authorization>\n\t\t\t<deny users=\"*\" />\n\t\t</authorization>\n\t</system.webServer>\n</configuration>\n";

	/** 防止列目錄 */
	private const INDEX_PHP = "<?php\n// Silence is golden.\n";

	/**
	 * 取得儲存根目錄（絕對路徑、正規化斜線、結尾帶 /）
	 *
	 * @return string
	 */
	public static function get_root_dir(): string {
		$dir = '';

		if ( \defined( self::DIR_CONSTANT ) ) {
			$constant = \constant( self::DIR_CONSTANT );
			if ( \is_string( $constant ) && '' !== \trim( $constant ) ) {
				$dir = $constant;
			}
		}

		if ( '' === $dir ) {
			// 第二個參數 false：只取路徑，不順手建立當月的一般上傳目錄
			$uploads = \wp_upload_dir( null, false );
			$dir     = $uploads['basedir'] . '/power-course-private/assignments';
		}

		$filtered = \apply_filters( self::DIR_FILTER, \trailingslashit( $dir ) );
		$dir      = \is_string( $filtered ) && '' !== \trim( $filtered ) ? $filtered : $dir;

		return \trailingslashit( \wp_normalize_path( $dir ) );
	}

	/**
	 * 把暫存檔搬進受保護目錄，回傳相對於根目錄的 stored_path（{Y}/{m}/{64 位 hex}）
	 *
	 * @param string $source_path 來源暫存檔絕對路徑（上傳暫存檔或一般檔案）
	 * @return string 相對於根目錄的儲存路徑
	 * @throws \RuntimeException 目錄不可寫或搬檔失敗時。
	 */
	public static function store_file( string $source_path ): string {
		$root = self::get_root_dir();

		if ( ! self::ensure_dir( $root ) ) {
			throw new \RuntimeException( "Assignment storage directory is not writable: {$root}" );
		}
		self::write_protection_files( $root );

		$sub_dir = (string) \wp_date( 'Y/m', Clock::now() );
		$dir     = $root . $sub_dir . '/';
		if ( ! self::ensure_dir( $dir ) ) {
			throw new \RuntimeException( "Assignment storage directory is not writable: {$dir}" );
		}

		do {
			$file_name = \bin2hex( \random_bytes( 32 ) );
		} while ( \file_exists( $dir . $file_name ) );

		$destination = $dir . $file_name;

		if ( ! self::move_file( $source_path, $destination ) ) {
			throw new \RuntimeException( "Failed to move uploaded file into assignment storage: {$destination}" );
		}

		return $sub_dir . '/' . $file_name;
	}

	/**
	 * 把來源檔搬到目的路徑
	 *
	 * 真實上傳檔走 move_uploaded_file（不受 open_basedir 限制來源）；
	 * 其餘（測試 / 側載）走 rename，失敗再退回 copy + unlink。
	 *
	 * @param string $source_path 來源檔絕對路徑
	 * @param string $destination 目的檔絕對路徑
	 * @return bool 是否搬移成功
	 */
	private static function move_file( string $source_path, string $destination ): bool {
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename, WordPress.WP.AlternativeFunctions.unlink_unlink
		if ( \is_uploaded_file( $source_path ) ) {
			$moved = @\move_uploaded_file( $source_path, $destination );
		} else {
			$moved = @\rename( $source_path, $destination );
		}

		if ( ! $moved && @\copy( $source_path, $destination ) ) {
			@\unlink( $source_path );
			$moved = true;
		}
		// phpcs:enable

		return $moved;
	}

	/**
	 * 把 stored_path 解析為可讀取的實體檔案絕對路徑
	 *
	 * - 相對路徑（本模組寫入的格式）：接在根目錄後以 realpath 解析，結果必須仍在根目錄內（防 path traversal）
	 * - 絕對路徑（非本模組寫入的既有資料）：以 realpath 解析，只要求是可讀檔案
	 *
	 * @param string $stored_path DB 中的 stored_path
	 * @return string|null 檔案真實絕對路徑；不存在、不可讀或逃逸出根目錄時回傳 null
	 */
	public static function resolve_path( string $stored_path ): ?string {
		if ( '' === \trim( $stored_path ) ) {
			return null;
		}

		$root_real = null;
		if ( \path_is_absolute( $stored_path ) ) {
			$candidate = $stored_path;
		} else {
			$root_real = \realpath( self::get_root_dir() );
			if ( false === $root_real ) {
				return null;
			}
			$candidate = $root_real . '/' . \ltrim( $stored_path, '/\\' );
		}

		$real = \realpath( $candidate );
		if ( false === $real || ! \is_file( $real ) || ! \is_readable( $real ) ) {
			return null;
		}

		if ( null !== $root_real
			&& ! \str_starts_with( \wp_normalize_path( $real ), \trailingslashit( \wp_normalize_path( $root_real ) ) ) ) {
			return null;
		}

		return $real;
	}

	/**
	 * 刪除 stored_path 對應的實體檔案（上傳流程 DB 寫入失敗時回滾用）
	 *
	 * @param string $stored_path 相對於根目錄的儲存路徑
	 * @return void
	 */
	public static function delete( string $stored_path ): void {
		$path = self::resolve_path( $stored_path );
		if ( null !== $path ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			@\unlink( $path );
		}
	}

	/**
	 * 確保目錄存在且可寫
	 *
	 * @param string $dir 目錄絕對路徑
	 * @return bool
	 */
	private static function ensure_dir( string $dir ): bool {
		if ( ! \is_dir( $dir ) ) {
			\wp_mkdir_p( $dir );
		}

		return \is_dir( $dir ) && \wp_is_writable( $dir );
	}

	/**
	 * 在根目錄寫入 .htaccess / web.config / index.php（已存在則略過）
	 *
	 * @param string $root 根目錄絕對路徑（結尾帶 /）
	 * @return void
	 */
	private static function write_protection_files( string $root ): void {
		$files = [
			'.htaccess'  => self::HTACCESS,
			'web.config' => self::WEB_CONFIG,
			'index.php'  => self::INDEX_PHP,
		];

		foreach ( $files as $name => $content ) {
			if ( \file_exists( $root . $name ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@\file_put_contents( $root . $name, $content );
		}
	}
}
