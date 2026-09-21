<?php
/**
 * 作業模組共用工具（Issue #131）
 *
 * 目前只有課程觀看權閘門 can_access_course()：學員端所有端點（查詢、繳交）
 * 的第一道 domain 檢查，對齊參考實作 hasActiveAccess（計劃 §6.1）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\User as UserUtils;

/**
 * Class Utils
 */
final class Utils {

	/** @var array<int, string> 附件大類（對齊參考實作 resolveAttachmentKind 的 AttachmentKind，P4） */
	public const ATTACHMENT_KINDS = [ 'image', 'video', 'audio', 'pdf', 'doc', 'spreadsheet', 'archive', 'file' ];

	/** 批次預熱使用者快取時，單批最多幾個 user ID（CSV 一次最多 5000 列） */
	public const USER_CACHE_CHUNK_SIZE = 500;

	/**
	 * 學員對課程是否有有效觀看權
	 *
	 * 三個條件同時成立：
	 *   1. is_avl()：已購買（avl_course_ids）或持有涵蓋此課程的有效權限包（Issue #252 OR 疊加）
	 *   2. ! is_expired()：觀看權未到期
	 *   3. is_course_ready()：課程已開課（course_schedule）
	 *
	 * 注意 is_expired() / is_course_ready() 讀的是 time() 而非 Clock::now()（R-06），
	 * 這是既有 Utils\Course 的行為，本模組不改寫。
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   學員 user ID
	 * @return bool
	 */
	public static function can_access_course( int $course_id, int $user_id ): bool {
		if ( $course_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( ! CourseUtils::is_avl( $course_id, $user_id ) ) {
			return false;
		}

		$product = \wc_get_product( $course_id );
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( CourseUtils::is_expired( $product, $user_id ) ) {
			return false;
		}

		return CourseUtils::is_course_ready( $product );
	}

	/**
	 * 沒有有效觀看權時中斷（403）
	 *
	 * @param int $course_id 課程（商品）ID
	 * @param int $user_id   學員 user ID
	 * @throws AssignmentException 無有效觀看權時（403）。
	 */
	public static function assert_course_access( int $course_id, int $user_id ): void {
		if ( ! self::can_access_course( $course_id, $user_id ) ) {
			throw AssignmentException::access_denied( self::not_enrolled_message() );
		}
	}

	/**
	 * 「尚未報名此課程」訊息（DEC-12）
	 *
	 * 作業不存在或在垃圾桶時也一律回這個訊息，不洩漏作業是否存在。
	 *
	 * @return string 已翻譯的錯誤訊息
	 */
	public static function not_enrolled_message(): string {
		return \__( 'You are not enrolled in this course', 'power-course' );
	}

	// ========== P4：批改頁 / CSV 共用的呈現輔助 ==========

	/**
	 * 由 MIME 類型與檔名副檔名推導附件大類（純函式，對齊參考實作 resolveAttachmentKind）
	 *
	 * 前端據此決定縮圖 / 圖示呈現方式。優先看 MIME，取不到才 fallback 看副檔名。
	 *
	 * @param string|null $mime_type MIME 類型
	 * @param string|null $file_name 原始檔名
	 * @return string ATTACHMENT_KINDS 之一
	 */
	public static function resolve_attachment_kind( ?string $mime_type, ?string $file_name ): string {
		$mime = \strtolower( \trim( (string) $mime_type ) );

		$by_mime = self::kind_by_mime( $mime );
		if ( null !== $by_mime ) {
			return $by_mime;
		}

		$parts     = \explode( '.', \strtolower( (string) $file_name ) );
		$extension = \count( $parts ) > 1 ? (string) \array_pop( $parts ) : '';

		return self::kind_by_extension( $extension );
	}

	/**
	 * 依 MIME 類型判斷附件大類
	 *
	 * @param string $mime 已轉小寫的 MIME 類型
	 * @return string|null 無法判斷時回傳 null（交給副檔名判斷）
	 */
	private static function kind_by_mime( string $mime ): ?string {
		if ( '' === $mime ) {
			return null;
		}

		foreach ( [ 'image', 'video', 'audio' ] as $prefix ) {
			if ( \str_starts_with( $mime, $prefix . '/' ) ) {
				return $prefix;
			}
		}

		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}

		if (
			\str_contains( $mime, 'word' )
			|| 'text/plain' === $mime
			|| 'application/rtf' === $mime
		) {
			return 'doc';
		}

		if ( \str_contains( $mime, 'spreadsheet' ) || \str_contains( $mime, 'excel' ) || 'text/csv' === $mime ) {
			return 'spreadsheet';
		}

		foreach ( [ 'zip', 'compressed', 'tar', 'rar', '7z' ] as $needle ) {
			if ( \str_contains( $mime, $needle ) ) {
				return 'archive';
			}
		}

		return null;
	}

	/**
	 * 依副檔名判斷附件大類（MIME 無法判斷時的 fallback）
	 *
	 * @param string $extension 已轉小寫的副檔名（不含點）
	 * @return string ATTACHMENT_KINDS 之一
	 */
	private static function kind_by_extension( string $extension ): string {
		$map = [
			'image'       => [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp' ],
			'video'       => [ 'mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v' ],
			'audio'       => [ 'mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac' ],
			'pdf'         => [ 'pdf' ],
			'doc'         => [ 'doc', 'docx', 'txt', 'rtf', 'pages', 'md' ],
			'spreadsheet' => [ 'xls', 'xlsx', 'csv', 'numbers' ],
			'archive'     => [ 'zip', 'rar', '7z', 'tar', 'gz' ],
		];

		foreach ( $map as $kind => $extensions ) {
			if ( \in_array( $extension, $extensions, true ) ) {
				return $kind;
			}
		}

		return 'file';
	}

	/**
	 * 批改狀態的在地化標籤（CSV 匯出與前端共用）
	 *
	 * @param string $status pending | pass | fail
	 * @return string 已翻譯的標籤；未知狀態原樣回傳
	 */
	public static function status_label( string $status ): string {
		switch ( $status ) {
			case 'pending':
				return \_x( 'Pending', 'assignment submission status', 'power-course' );
			case 'pass':
				return \_x( 'Passed', 'assignment submission status', 'power-course' );
			case 'fail':
				return \_x( 'Failed', 'assignment submission status', 'power-course' );
			default:
				return $status;
		}
	}

	/**
	 * 學員顯示名稱（批改列表 / 學員背景資料 / CSV 共用）
	 *
	 * 一律走專案既有的 Utils\User::get_formatted_name()
	 * （fallback chain：billing 姓名 → WP meta 姓名 → display_name），
	 * 與學員列表 Resources\Student\Core\Api::extend_meta_keys() 的 formatted_name 同一條規則——
	 * 兩個後台頁面顯示同一位學員時名字必須一致，不得各自解析。
	 *
	 * ⚠️ 呼叫端必須先對本批次的所有 user_id 呼叫 prime_user_caches()：
	 * get_formatted_name() 每位學員要讀 user row + 2～4 個 meta，沒預熱就是逐列查 DB。
	 *
	 * @param int $user_id 學員 user ID（取自 LEFT JOIN wp_users 的 u.ID，0 / 不存在代表學員已刪除）
	 * @return string|null 學員顯示名稱；使用者不存在時為 null
	 */
	public static function resolve_customer_name( int $user_id ): ?string {
		if ( $user_id <= 0 ) {
			return null;
		}

		$user = \get_user_by( 'ID', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		$name = UserUtils::get_formatted_name( $user_id );

		return '' === $name ? null : $name;
	}

	/**
	 * 批次預熱使用者與其 meta 的物件快取（呼叫 resolve_customer_name() 前必做）
	 *
	 * 走 get_formatted_name() 時每位學員要讀 user row + 2～4 個 meta；批改列表一頁最多 200 列、
	 * 批改用 CSV 一次最多 5000 列，逐列查會退化成數千次查詢。先把本頁 / 本批次的 user_id
	 * 一次灌進物件快取（cache_users() 撈 user row、update_meta_cache() 撈 user meta，
	 * 每批各 1 次查詢），之後每列的 get_user_by() / get_user_meta() 都是記憶體命中。
	 *
	 * @param array<int, int> $user_ids 學員 user ID（可含重複 / 0）
	 */
	public static function prime_user_caches( array $user_ids ): void {
		$ids = \array_values(
			\array_unique(
				\array_filter(
					\array_map( 'intval', $user_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( ! $ids ) {
			return;
		}

		foreach ( \array_chunk( $ids, self::USER_CACHE_CHUNK_SIZE ) as $chunk ) {
			// cache_users() 只會替「本次新撈到的 id」補 meta，故另外補一次 update_meta_cache()；
			// 兩者都會自行略過已在快取中的 id，重覆呼叫不會多打查詢。
			\cache_users( $chunk );
			\update_meta_cache( 'user', $chunk );
		}
	}
}
