<?php
/**
 * 匯入批改結果 CSV 的嚴格解析器（Issue #131 第二波，DEC-W2-11 / DEC-W2-12）
 *
 * 為什麼自寫：PHP 的 fgetcsv / str_getcsv 對「引號到檔尾沒閉合」「閉合引號後接殘字」「未加引號的欄位出現引號」
 * 都不報錯（靜默吞到檔尾或改寫內容），反斜線 escape 還會吃掉 \"，做不出 feature 要的「無法解析 → 不處理任何列」。
 *
 * 規則：
 *   - 去開頭的 UTF-8 BOM；去 BOM 後必須是合法 UTF-8（Big5 / UTF-16 一律視為無法解析，UC-W2-4）
 *   - 分隔符固定逗號；列分隔 CRLF / LF / CR 皆可（可混用），結尾有無換行皆可
 *   - 引號欄位內的逗號與換行逐字保留（不正規化換行），"" 為跳脫的雙引號；閉合引號後只允許空白再接逗號 / 換行 / 檔尾
 *   - 每格前後空白 trim；第一個非全空的列是表頭（鍵一律經 normalize_header()）
 *   - 比表頭多的格忽略、少的格視為空字串；正規化後表頭撞名時後者覆蓋；全空列略過且不計入列數
 *
 * 「不處理任何列」的四種情況（空檔 / 無法解析 / 沒有資料列 / 超過 MAX_ROWS 列）一律丟 \UnexpectedValueException，
 * 訊息為已翻譯的使用者可見文字，由 Service\CsvImport 轉成 row 0 錯誤。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

/**
 * Class CsvParser
 */
final class CsvParser {

	/** 單批匯入的資料列上限（不含表頭與全空列，對齊參考實作 MAX_CSV_ROWS） */
	public const MAX_ROWS = 5000;

	/** UTF-8 BOM */
	private const BOM = "\xEF\xBB\xBF";

	/** 欄位分隔符 */
	private const DELIMITER = ',';

	/** 引號字元 */
	private const QUOTE = '"';

	/** 未加引號欄位的結束字元（逗號、CR、LF） */
	private const CELL_TERMINATORS = ",\r\n";

	/** 開頭引號前、閉合引號後允許出現的空白 */
	private const INLINE_SPACES = " \t";

	/**
	 * 解析 CSV 原始內容
	 *
	 * @param string $raw CSV 原始位元組
	 * @return list<array<string, string>> 鍵為正規化表頭、值為 trim 後的字串，已略過全空列
	 * @throws \UnexpectedValueException 空檔、無法解析、沒有資料列、超過 MAX_ROWS 列時。
	 */
	public static function parse( string $raw ): array {
		if ( '' === $raw ) {
			throw new \UnexpectedValueException( \__( 'CSV file is empty', 'power-course' ) );
		}

		$rows  = self::to_rows( self::tokenize( self::strip_bom( $raw ) ) );
		$count = \count( $rows );

		if ( 0 === $count ) {
			throw new \UnexpectedValueException( \__( 'CSV has no data rows', 'power-course' ) );
		}

		if ( $count > self::MAX_ROWS ) {
			throw new \UnexpectedValueException(
				\sprintf(
					/* translators: 1: 單批匯入的資料列上限, 2: 這份 CSV 的資料列數 */
					\__( 'Import is limited to %1$d rows per batch. Please split the file and try again (current: %2$d rows)', 'power-course' ),
					self::MAX_ROWS,
					$count
				)
			);
		}

		return $rows;
	}

	/**
	 * 表頭正規化：去 BOM → trim → mb_strtolower → 連續空白（含全形空白）換成 _（DEC-W2-09）
	 *
	 * 匯入端查表頭別名時也用這個函式正規化，兩邊才對得上。
	 *
	 * @param string $header 原始表頭
	 * @return string
	 */
	public static function normalize_header( string $header ): string {
		$header = \mb_strtolower( \trim( self::strip_bom( $header ) ), 'UTF-8' );

		return (string) \preg_replace( '/[\s\p{Z}]+/u', '_', $header );
	}

	/**
	 * 去掉開頭的 UTF-8 BOM
	 *
	 * @param string $text 原始字串
	 * @return string
	 */
	private static function strip_bom( string $text ): string {
		return \str_starts_with( $text, self::BOM ) ? \substr( $text, \strlen( self::BOM ) ) : $text;
	}

	/**
	 * 「CSV 解析失敗」例外（feature 只要求這一句，不附細節，§10 S-02）
	 *
	 * @return \UnexpectedValueException
	 */
	private static function malformed(): \UnexpectedValueException {
		return new \UnexpectedValueException( \__( 'Failed to parse CSV', 'power-course' ) );
	}

	/**
	 * 把整份文字切成「列 → 格」（格已 trim，尚未對應表頭）
	 *
	 * 先確認是合法 UTF-8（DEC-W2-11 (a)）；換行與逗號都是 ASCII，UTF-8 的多位元組序列不會含 ASCII 位元組，
	 * 所以確認之後可以逐位元組掃描。
	 *
	 * @param string $text 已去 BOM 的內容
	 * @return array<int, array<int, string>>
	 * @throws \UnexpectedValueException 無法解析時。
	 */
	private static function tokenize( string $text ): array {
		if ( ! \mb_check_encoding( $text, 'UTF-8' ) ) {
			throw self::malformed();
		}

		$length  = \strlen( $text );
		$records = [];
		$record  = [];
		$offset  = 0;

		if ( 0 === $length ) {
			return [];
		}

		while ( true ) {
			[ $cell, $offset ] = self::read_cell( $text, $offset, $length );
			$record[]          = \trim( $cell );

			if ( $offset >= $length ) {
				$records[] = $record;
				break;
			}

			if ( self::DELIMITER === $text[ $offset ] ) {
				++$offset;
				continue;
			}

			// 走到這裡一定是換行：CRLF 算一個換行，單獨的 CR 或 LF 也各算一個
			$is_crlf   = "\r" === $text[ $offset ] && $offset + 1 < $length && "\n" === $text[ $offset + 1 ];
			$offset   += $is_crlf ? 2 : 1;
			$records[] = $record;
			$record    = [];

			// 檔尾的換行不多出一列空白列
			if ( $offset >= $length ) {
				break;
			}
		}

		return $records;
	}

	/**
	 * 從 $offset 讀一格
	 *
	 * @param string $text   CSV 內容
	 * @param int    $offset 這一格的起點
	 * @param int    $length CSV 內容長度（bytes）
	 * @return array{0: string, 1: int} [格內容（未 trim）, 讀完後的位置（指向逗號 / 換行 / 檔尾）]
	 * @throws \UnexpectedValueException 未加引號的欄位出現引號（DEC-W2-11 (d)），或引號欄位不合法時。
	 */
	private static function read_cell( string $text, int $offset, int $length ): array {
		$quote_at = $offset + \strspn( $text, self::INLINE_SPACES, $offset );
		if ( $quote_at < $length && self::QUOTE === $text[ $quote_at ] ) {
			return self::read_quoted_cell( $text, $quote_at + 1, $length );
		}

		$end  = $offset + \strcspn( $text, self::CELL_TERMINATORS, $offset );
		$cell = \substr( $text, $offset, $end - $offset );

		if ( \str_contains( $cell, self::QUOTE ) ) {
			throw self::malformed();
		}

		return [ $cell, $end ];
	}

	/**
	 * 讀引號欄位（$offset 指向開頭引號的下一個位元組）
	 *
	 * @param string $text   CSV 內容
	 * @param int    $offset 開頭引號的下一個位置
	 * @param int    $length CSV 內容長度（bytes）
	 * @return array{0: string, 1: int} [格內容（引號內原文，"" 已還原為 "）, 讀完後的位置]
	 * @throws \UnexpectedValueException 引號到檔尾未閉合（DEC-W2-11 (b)），或閉合引號後接的不是逗號 / 換行 / 空白（(c)）時。
	 */
	private static function read_quoted_cell( string $text, int $offset, int $length ): array {
		$cell = '';

		while ( true ) {
			$close = \strpos( $text, self::QUOTE, $offset );
			if ( false === $close ) {
				throw self::malformed();
			}

			$cell  .= \substr( $text, $offset, $close - $offset );
			$offset = $close + 1;

			// 連續兩個引號 = 跳脫的雙引號，繼續找真正的閉合引號
			if ( $offset < $length && self::QUOTE === $text[ $offset ] ) {
				$cell .= self::QUOTE;
				++$offset;
				continue;
			}

			break;
		}

		$offset += \strspn( $text, self::INLINE_SPACES, $offset );
		if ( $offset < $length && ! \str_contains( self::CELL_TERMINATORS, $text[ $offset ] ) ) {
			throw self::malformed();
		}

		return [ $cell, $offset ];
	}

	/**
	 * 列 → 以正規化表頭為鍵的資料列（第一個非全空的列是表頭，其後的全空列略過）
	 *
	 * @param array<int, array<int, string>> $records tokenize() 的結果
	 * @return list<array<string, string>>
	 */
	private static function to_rows( array $records ): array {
		$headers = null;
		$rows    = [];

		foreach ( $records as $record ) {
			if ( null === $headers ) {
				if ( ! self::is_blank( $record ) ) {
					$headers = \array_map( [ self::class, 'normalize_header' ], $record );
				}
				continue;
			}

			// 比表頭多的格不屬於任何欄，不參與「是否全空」的判斷
			$cells = \array_slice( $record, 0, \count( $headers ) );
			if ( self::is_blank( $cells ) ) {
				continue;
			}

			$row = [];
			foreach ( $headers as $index => $key ) {
				// 表頭撞名時後面的欄覆蓋前面的；比表頭少的格視為空字串
				$row[ $key ] = \array_key_exists( $index, $cells ) ? $cells[ $index ] : '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * 是否為全空的列（每一格 trim 後都是空字串）
	 *
	 * @param array<int, string> $cells 已 trim 的格
	 * @return bool
	 */
	private static function is_blank( array $cells ): bool {
		foreach ( $cells as $cell ) {
			if ( '' !== $cell ) {
				return false;
			}
		}

		return true;
	}
}
