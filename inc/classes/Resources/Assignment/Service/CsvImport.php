<?php
/**
 * 匯入批改結果 CSV 服務（Issue #131 第二波）
 *
 * 對應 specs/features/assignment/匯入批改結果CSV.feature 與計劃 §7.3：
 *   PARSE（Utils\CsvParser）→ HEADER MAP（表頭別名，DEC-W2-09）→ 每一列：
 *     缺 submission_id / 狀態無法辨識 → skipped；其餘呼叫 Grading::grade()，成功 → updated、失敗 → failed
 *
 * - 空檔 / 無法解析 / 沒有資料列 / 超過 5000 列：只回 row 0 錯誤、不處理任何列（DEC-W2-11 / 12，UC-W2-1）
 * - 逐列處理、不開交易（DEC-W2-14）：單列失敗不影響其他列；同一 submission_id 出現多列時依序套用、最後一列為準
 * - 每一列都是一次完整的「批改作業」，副作用（pass 完成章節、pass / fail 批改通知）與批改作業一致；
 *   整份重匯會再寄一次信、沒有回饋欄時評語被清空（DEC-W2-15，UC-W2-2 = A）
 * - 依「表頭名稱」定位欄位，不依位置；「目前狀態」欄（翻譯標籤）不是別名（DEC-W2-10）
 * - 錯誤列號 = 非空資料列序號（從 0 起算）+ 2：含表頭，第一筆資料是第 2 列，全空列不佔列號
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\CsvParser;

/**
 * Class CsvImport
 */
final class CsvImport {

	/**
	 * 匯出表頭以外，submission_id 欄的其他表頭別名
	 * 匯出用的表頭（Csv::grading_headers() 的 submission_id）排在最前面，見 columns()
	 *
	 * @var array<int, string>
	 */
	private const ID_HEADER_ALIASES = [ 'submissionId' ];

	/**
	 * 狀態欄的固定表頭別名（依序取第一個非空的值）
	 * 另外會補上匯出表頭（Csv::grading_headers() 的 status）在目前語系的譯文，見 columns()
	 *
	 * @var array<int, string>
	 */
	private const STATUS_HEADERS = [ '狀態(可改 pending/pass/fail)', '狀態(可改)', '狀態', 'status', 'Status (editable: pending/pass/fail)' ];

	/**
	 * 講師回饋欄的固定表頭別名（依序取第一個非空的值）
	 * 另外會補上匯出表頭（Csv::grading_headers() 的 feedback）在目前語系的譯文，見 columns()
	 *
	 * @var array<int, string>
	 */
	private const FEEDBACK_HEADERS = [ '講師回饋(可改)', '講師回饋', 'Feedback (editable)' ];

	/** @var array<string, string> 狀態欄寫法（trim + mb_strtolower 後）→ 批改狀態（DEC-W2-10） */
	private const STATUS_VALUES = [
		'pending' => 'pending',
		'待批改'  => 'pending',
		'pass'    => 'pass',
		'passed'  => 'pass',
		'通過'    => 'pass',
		'fail'    => 'fail',
		'failed'  => 'fail',
		'未通過'  => 'fail',
		'不通過'  => 'fail',
	];

	/** 第一筆資料列的列號（第 1 列是表頭） */
	private const FIRST_DATA_ROW = 2;

	/** 整份檔案不處理時（空檔 / 無法解析 / 沒有資料列 / 超量）錯誤所標的列號 */
	private const FILE_LEVEL_ROW = 0;

	/**
	 * 匯入批改結果 CSV
	 *
	 * @param string $raw      CSV 原始位元組
	 * @param int    $actor_id 批改者（目前管理員）user ID，寫入 graded_by
	 * @return array{total:int, updated:int, skipped:int, failed:int, errors:list<array{row:int, message:string}>}
	 */
	public static function import( string $raw, int $actor_id ): array {
		try {
			$rows = CsvParser::parse( $raw );
		} catch ( \UnexpectedValueException $e ) {
			$summary             = self::empty_summary();
			$summary['errors'][] = [
				'row'     => self::FILE_LEVEL_ROW,
				'message' => $e->getMessage(),
			];

			return $summary;
		}

		$columns = self::columns();
		$summary = self::empty_summary();

		foreach ( $rows as $index => $row ) {
			$summary = self::tally( $summary, self::import_row( $row, $columns, $actor_id ), $index + self::FIRST_DATA_ROW );
		}

		return $summary;
	}

	// ========== 表頭對應 ==========

	/**
	 * 各欄的正規化表頭別名（DEC-W2-09）
	 *
	 * 除了固定別名，再補上匯出（Csv::grading_headers()）的表頭在目前請求語系的譯文，
	 * 讓 en / ja 等語系站台自己匯出的檔案可以原封不動匯回。
	 *
	 * @return array{id: list<string>, status: list<string>, feedback: list<string>}
	 */
	private static function columns(): array {
		$export_headers = Csv::grading_headers();

		return [
			'id'       => self::normalize_headers( [ $export_headers['submission_id'], ...self::ID_HEADER_ALIASES ] ),
			'status'   => self::normalize_headers( [ ...self::STATUS_HEADERS, $export_headers['status'] ] ),
			'feedback' => self::normalize_headers( [ ...self::FEEDBACK_HEADERS, $export_headers['feedback'] ] ),
		];
	}

	/**
	 * 表頭別名逐一正規化（與 CsvParser 對資料列表頭的正規化相同，兩邊才對得上）
	 *
	 * @param array $headers 原始表頭別名
	 * @phpstan-param list<string> $headers
	 * @return list<string>
	 */
	private static function normalize_headers( array $headers ): array {
		return \array_map( [ CsvParser::class, 'normalize_header' ], $headers );
	}

	// ========== 列處理 ==========

	/**
	 * 處理一列
	 *
	 * 檢查順序同參考實作 importGrades：缺 id → 狀態 → 批改（id 格式不對與繳交不存在同屬「批改失敗」）。
	 *
	 * @param array<string, string>                                                 $row      解析後的資料列
	 * @param array{id: list<string>, status: list<string>, feedback: list<string>} $columns  各欄的正規化表頭別名
	 * @param int                                                                   $actor_id 批改者 user ID
	 * @return array{0: 'updated'|'skipped'|'failed', 1: string|null} [結果, 錯誤訊息（成功時為 null）]
	 */
	private static function import_row( array $row, array $columns, int $actor_id ): array {
		$submission_id = self::pick( $row, $columns['id'] );
		if ( '' === $submission_id ) {
			return [ 'skipped', \__( 'Missing submission_id', 'power-course' ) ];
		}

		$status_raw = self::pick( $row, $columns['status'] );
		$status     = self::normalize_status( $status_raw );
		if ( null === $status ) {
			return [ 'skipped', self::unrecognized_status_message( $status_raw ) ];
		}

		// 非正整數（abc、500.0、500abc、-500、0）一律視為不存在，不可 (int) 轉型後誤批到別人的繳交
		if ( ! self::is_positive_int( $submission_id ) ) {
			return [ 'failed', \__( 'Submission not found', 'power-course' ) ];
		}

		return self::grade_row( (int) $submission_id, $status, self::pick( $row, $columns['feedback'] ), $actor_id );
	}

	/**
	 * 以批改作業寫入一列（副作用與批改作業一致，DEC-W2-15）
	 *
	 * @param int    $submission_id 繳交 ID（已確認是正整數）
	 * @param string $status        批改狀態（pending / pass / fail）
	 * @param string $feedback      評語（空字串 = 清空）
	 * @param int    $actor_id      批改者 user ID
	 * @return array{0: 'updated'|'skipped'|'failed', 1: string|null} [結果, 錯誤訊息（成功時為 null）]
	 */
	private static function grade_row( int $submission_id, string $status, string $feedback, int $actor_id ): array {
		try {
			Grading::grade(
				$submission_id,
				[
					'status'   => $status,
					'feedback' => $feedback,
				],
				$actor_id
			);
		} catch ( AssignmentException $e ) {
			return [ 'failed', $e->getMessage() ];
		} catch ( \Throwable $e ) {
			Plugin::logger( '匯入批改結果失敗：' . $e->getMessage(), 'error', [ 'submission_id' => $submission_id ] );

			return [ 'failed', \__( 'Failed to save grading result', 'power-course' ) ];
		}

		return [ 'updated', null ];
	}

	/**
	 * 依序取第一個「非空」的欄位值（同參考實作 pick；值已由解析器 trim）
	 *
	 * @param array<string, string> $row  解析後的資料列
	 * @param array<int, string>    $keys 正規化表頭別名
	 * @return string 都沒有值時為空字串
	 */
	private static function pick( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( \array_key_exists( $key, $row ) && '' !== $row[ $key ] ) {
				return $row[ $key ];
			}
		}

		return '';
	}

	/**
	 * 狀態欄寫法 → 批改狀態（不分大小寫；不認得時回 null）
	 *
	 * @param string $raw 狀態欄原值
	 * @return string|null
	 */
	private static function normalize_status( string $raw ): ?string {
		$value = \mb_strtolower( \trim( $raw ), 'UTF-8' );

		return \array_key_exists( $value, self::STATUS_VALUES ) ? self::STATUS_VALUES[ $value ] : null;
	}

	/**
	 * 「狀態無法辨識」訊息：帶入 trim 後的原值（不轉小寫），空值時為「空白」（DEC-W2-16）
	 *
	 * @param string $raw 狀態欄原值
	 * @return string
	 */
	private static function unrecognized_status_message( string $raw ): string {
		$value = \trim( $raw );

		return \sprintf(
			/* translators: %s: CSV 中的狀態原值（空值時為「空白」） */
			\__( 'Unrecognized status (%s), must be pending/pass/fail', 'power-course' ),
			'' === $value ? \_x( 'blank', 'assignment import empty cell', 'power-course' ) : $value
		);
	}

	/**
	 * 是否為正整數字串（不以 0 開頭、只有 ASCII 數字、不溢位）
	 *
	 * @param string $value submission_id 欄的值
	 * @return bool
	 */
	private static function is_positive_int( string $value ): bool {
		return 1 === \preg_match( '/^[1-9][0-9]*\z/', $value ) && (string) (int) $value === $value;
	}

	// ========== 摘要組裝 ==========

	/**
	 * 全部歸零的摘要
	 *
	 * @return array{total:int, updated:int, skipped:int, failed:int, errors:list<array{row:int, message:string}>}
	 */
	private static function empty_summary(): array {
		return [
			'total'   => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
			'errors'  => [],
		];
	}

	/**
	 * 把一列的結果計入摘要（total 與對應的計數各加一；有錯誤訊息時附上列號）
	 *
	 * @param array{total:int, updated:int, skipped:int, failed:int, errors:list<array{row:int, message:string}>} $summary    目前的摘要
	 * @param array{0: 'updated'|'skipped'|'failed', 1: string|null}                                              $result     import_row() 的結果
	 * @param int                                                                                                 $row_number 這一列在 CSV 中的列號
	 * @return array{total:int, updated:int, skipped:int, failed:int, errors:list<array{row:int, message:string}>}
	 */
	private static function tally( array $summary, array $result, int $row_number ): array {
		[ $outcome, $message ] = $result;

		++$summary['total'];
		++$summary[ $outcome ];

		if ( null !== $message ) {
			$summary['errors'][] = [
				'row'     => $row_number,
				'message' => $message,
			];
		}

		return $summary;
	}
}
