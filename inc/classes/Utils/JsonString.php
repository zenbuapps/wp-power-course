<?php
/**
 * JSON 字串欄位的寫入守門工具
 *
 * 背景（詳見 JSON_PARSE_ERROR.md）：
 * 編輯器內容（easy-email JSON、BlockNote HTML）以「不透明字串」存進 LONGTEXT 欄位，
 * 前端 JSON.stringify 一次、JSON.parse 一次，PHP 不碰結構。
 * 本類別負責寫入前的最後防線：擋掉非法 JSON、自癒歷史雙重編碼資料。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Utils;

/** Class JsonString */
abstract class JsonString {

	/** @var int 雙重編碼自癒的最大拆解層數，防止惡意 payload 無限迴圈 */
	private const MAX_UNWRAP_DEPTH = 5;

	/**
	 * 正規化要存入 DB 的 JSON 字串
	 *
	 * 1. 空字串視為「清空內容」，直接放行
	 * 2. 非法 JSON 回傳 WP_Error（400），拒絕落地，避免下次讀取 parse error
	 * 3. 自癒：偵測「雙重（多重）編碼」——decode 出字串且其內容仍是 JSON 物件/陣列時，
	 *    拆到最裡層再存（歷史壞資料存回時順帶修復）
	 *
	 * @param string $value          要驗證的 JSON 字串
	 * @param bool   $require_object 是否要求最終結果必須為物件/陣列（編輯器內容必為物件）
	 * @return string|\WP_Error 正規化後的 JSON 字串，非法時回傳 WP_Error
	 */
	public static function normalize( string $value, bool $require_object = false ): string|\WP_Error {
		if ( '' === trim( $value ) ) {
			return '';
		}

		$decoded = json_decode( $value );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return self::invalid_json_error();
		}

		// 拆解雙重（多重）編碼：decode 出來還是字串，且該字串本身仍是合法 JSON
		// （物件/陣列 = 拆到目標；字串 = 還有下一層編碼，繼續拆；純量 = 停止，保留原值）
		$depth = 0;
		while ( is_string( $decoded ) && $depth < self::MAX_UNWRAP_DEPTH ) {
			$inner = json_decode( $decoded );
			if ( JSON_ERROR_NONE !== json_last_error() ) {
				break;
			}
			if ( ! is_object( $inner ) && ! is_array( $inner ) && ! is_string( $inner ) ) {
				break;
			}
			$value   = $decoded;
			$decoded = $inner;
			++$depth;
		}

		if ( $require_object && ! is_object( $decoded ) && ! is_array( $decoded ) ) {
			return self::invalid_json_error();
		}

		return $value;
	}

	/** @return \WP_Error 統一的非法 JSON 錯誤（HTTP 400） */
	private static function invalid_json_error(): \WP_Error {
		return new \WP_Error(
			'invalid_json_content',
			__( 'Content is not valid JSON. Save aborted to prevent data loss.', 'power-course' ),
			[ 'status' => 400 ]
		);
	}
}
