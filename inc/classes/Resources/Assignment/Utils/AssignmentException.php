<?php
/**
 * 作業模組領域例外（Issue #131）
 *
 * 帶 HTTP status 與 REST error code，由 Api 層轉成 WP_Error。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

/**
 * Class AssignmentException
 */
final class AssignmentException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string $message    已翻譯的錯誤訊息
	 * @param int    $status     HTTP status
	 * @param string $error_code REST error code
	 */
	public function __construct(
		string $message,
		private int $status = 400,
		private string $error_code = 'rest_invalid_param'
	) {
		parent::__construct( $message );
	}

	/**
	 * 參數驗證失敗（400 rest_invalid_param）
	 *
	 * @param string $message 已翻譯的錯誤訊息
	 * @return self
	 */
	public static function invalid_param( string $message ): self {
		return new self( $message, 400, 'rest_invalid_param' );
	}

	/**
	 * 資源不存在（404 rest_not_found）
	 *
	 * @param string $message 已翻譯的錯誤訊息
	 * @return self
	 */
	public static function not_found( string $message ): self {
		return new self( $message, 404, 'rest_not_found' );
	}

	/**
	 * 無權存取（403 rest_forbidden）
	 *
	 * @param string $message 已翻譯的錯誤訊息
	 * @return self
	 */
	public static function forbidden( string $message ): self {
		return new self( $message, 403, 'rest_forbidden' );
	}

	/**
	 * 前置狀態不符（403，error code 為字面字串 '403'）
	 *
	 * 「尚未報名此課程」與「此章節尚未解鎖…」用的是字面字串 '403'，不是 rest_forbidden：
	 * 沿用既有 Resources\Chapter\Core\Api::post_toggle_finish_chapters_with_id_callback()
	 * 對同一句鎖定訊息的回應格式，並與 specs/api/api.yml 該端點的內嵌範例一致。
	 *
	 * @param string $message 已翻譯的錯誤訊息
	 * @return self
	 */
	public static function access_denied( string $message ): self {
		return new self( $message, 403, '403' );
	}

	/**
	 * 寫入失敗等伺服器端錯誤（500 rest_assignment_error）
	 *
	 * @param string $message 已翻譯的錯誤訊息
	 * @return self
	 */
	public static function internal( string $message ): self {
		return new self( $message, 500, 'rest_assignment_error' );
	}

	/**
	 * 取得 HTTP status
	 *
	 * @return int
	 */
	public function get_status(): int {
		return $this->status;
	}

	/**
	 * 取得 REST error code
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}
}
