<?php
/**
 * REST 檔案串流輸出器（Issue #131，DEC-08）
 *
 * 下載端點的 callback 不能在 callback 內 readfile + exit（會殺掉 PHPUnit，也跳過 REST 流程），
 * 也不能把最大 50MB 的檔案讀進記憶體再 JSON 回傳。做法：
 *   1. callback 回傳帶標頭的 WP_REST_Response（Content-Type / Content-Disposition / X-Content-Type-Options），
 *      PHPUnit 以 rest_get_server()->dispatch() 取得後可直接斷言標頭
 *   2. 同時登記一次性的 rest_pre_serve_request filter：真實 HTTP 請求時 WP_REST_Server::serve_request()
 *      送完標頭後觸發，清空輸出緩衝、readfile 串流檔案並回傳 true（告知 WP 已輸出，不再輸出 JSON）
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

/**
 * Class Stream
 */
final class Stream {

	/**
	 * 建立檔案下載回應，並登記一次性串流輸出器
	 *
	 * @param string $path      要輸出的實體檔案絕對路徑（呼叫端須先驗權並確認檔案存在）
	 * @param string $mime_type 回應 Content-Type
	 * @param string $file_name 下載時的檔名（原始檔名，UTF-8）
	 * @return \WP_REST_Response
	 */
	public static function file( string $path, string $mime_type, string $file_name ): \WP_REST_Response {
		self::register_output(
			static function () use ( $path ): void {
				\readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			}
		);

		return self::download_response( $mime_type, $file_name );
	}

	/**
	 * 建立 CSV 下載回應，並登記一次性串流輸出器（Issue #131 P4）
	 *
	 * 與 file() 同一套機制，只是輸出來源是已經寫好的 php://temp 串流而非實體檔案：
	 * 匯出最多 5000 筆的 CSV 不落地成檔案，也不整包讀進記憶體再回傳（R-15）。
	 *
	 * @param resource $stream    已寫入 CSV 內容的串流（本方法輸出後自行關閉）
	 * @param string   $file_name 下載時的檔名（UTF-8）
	 * @return \WP_REST_Response
	 */
	public static function csv( $stream, string $file_name ): \WP_REST_Response {
		self::register_output(
			static function () use ( $stream ): void {
				if ( ! \is_resource( $stream ) ) {
					return;
				}

				\rewind( $stream );
				\fpassthru( $stream );
				\fclose( $stream );
			}
		);

		return self::download_response( 'text/csv; charset=utf-8', $file_name );
	}

	/**
	 * 共用的「強制下載」回應（檔名用 RFC 5987 格式，中文檔名不亂碼）
	 *
	 * @param string $mime_type 回應 Content-Type
	 * @param string $file_name 下載時的檔名（UTF-8）
	 * @return \WP_REST_Response
	 */
	private static function download_response( string $mime_type, string $file_name ): \WP_REST_Response {
		return new \WP_REST_Response(
			null,
			200,
			[
				'Content-Type'           => $mime_type,
				'Content-Disposition'    => "attachment; filename*=UTF-8''" . \rawurlencode( $file_name ),
				'X-Content-Type-Options' => 'nosniff',
			]
		);
	}

	/**
	 * 登記一次性的 rest_pre_serve_request 輸出器
	 *
	 * @param callable(): void $emit 真正把內容寫到輸出緩衝的閉包
	 * @return void
	 */
	private static function register_output( callable $emit ): void {
		$callback = null;
		$callback = static function ( $served, $result, $request ) use ( $emit, &$callback ) {
			\remove_filter( 'rest_pre_serve_request', $callback, 10 );

			// 已被其他 filter 輸出、回應被改成錯誤、或 HEAD 請求：交回 WP 原流程
			if ( $served
				|| ! ( $result instanceof \WP_HTTP_Response )
				|| 200 !== $result->get_status()
				|| ( $request instanceof \WP_REST_Request && 'HEAD' === $request->get_method() ) ) {
				return $served;
			}

			// 清空所有輸出緩衝，避免前面殘留的輸出混進檔案內容，也避免 50MB 檔案被整包緩衝在記憶體（R-15）
			while ( \ob_get_level() > 0 ) {
				if ( ! @\ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					break;
				}
			}

			$emit();

			return true;
		};

		\add_filter( 'rest_pre_serve_request', $callback, 10, 3 );
	}
}
