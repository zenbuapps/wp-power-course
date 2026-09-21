<?php
/**
 * 作業繳交的 CSV 匯出服務（Issue #131 P4）
 *
 * 對應兩支 feature：
 *   - 匯出單一作業繳交CSV.feature → write_submission_csv()
 *   - 匯出批改用CSV.feature       → write_grading_csv()
 *
 * 設計重點：
 *   1. 篩選條件（status / course_id / assignment_id / 排除垃圾桶作業 / 排序）一律由
 *      Service\SubmissionFilter 產生，與繳交列表（Service\Reporting）共用同一份 SQL，
 *      「列表與 CSV 對同一組篩選的 id 順序一致」才不會漂移（計劃 §9 P4 成功標準）。
 *   2. 輸出寫進 php://temp（超過 5MB 才落地暫存檔），回傳 stream resource 交給
 *      Utils\Stream::csv() 串流出去，最多 5000 筆也不會把整份 CSV 佔在記憶體（R-15）。
 *   3. 寫檔前一律先 Utils::prime_user_caches()，姓名欄才不會退化成逐列查 user meta（R-15）。
 *   4. 格式：UTF-8 BOM（Excel 開中文不亂碼）、CRLF 換行、含 , " CR LF 的值以雙引號包覆並把
 *      內部雙引號重複一次；時間欄一律 get_gmt_from_date() 轉 ISO 8601 UTC（DEC-03）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Clock;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;

/**
 * Class Csv
 */
final class Csv {

	/** 單次匯出的筆數上限（feature：最多 5000 筆） */
	public const MAX_ROWS = 5000;

	/** UTF-8 BOM（Excel 需要它才會用 UTF-8 解讀中文） */
	private const BOM = "\xEF\xBB\xBF";

	/** 換行一律 CRLF（Excel / Windows 友善） */
	private const EOL = "\r\n";

	/** 暫存串流：超過 5MB 才落地成暫存檔，其餘留在記憶體 */
	private const TEMP_STREAM = 'php://temp/maxmemory:5242880';

	/** 附件清單的分隔字串（對齊參考實作 serializeSubmissionsToCsv） */
	private const ATTACHMENT_SEPARATOR = ' | ';

	/** 檔名中的時間戳格式（UTC，對齊參考實作 toISOString().replace(/[:T]/g,'-').slice(0,19)） */
	private const FILE_NAME_TIMESTAMP_FORMAT = 'Y-m-d-H-i-s';

	/** 檔名中作業標題的字元上限（對齊參考實作 slice(0, 40)） */
	private const FILE_NAME_TITLE_LENGTH = 40;

	/** 時間欄位格式：ISO 8601 UTC，毫秒固定 .000（DEC-03） */
	private const ISO_8601_UTC = 'Y-m-d\TH:i:s.000\Z';

	/**
	 * 單一作業的繳交 CSV（匯出單一作業繳交CSV.feature）
	 *
	 * 欄位：繳交時間 / 學員 Email / 學員姓名 / 狀態 / 文字內容 / 附件數 / 附件清單 / 批改時間 / 講師回饋
	 * 作業是否存在（含垃圾桶）由呼叫端先行檢查；本方法只負責產生內容。
	 *
	 * @param int $assignment_id 作業 ID
	 * @return resource 可讀取的 CSV 串流（呼叫端負責 rewind / 關閉）
	 * @throws AssignmentException 無法開啟暫存串流時（500）。
	 */
	public static function write_submission_csv( int $assignment_id ) {
		$rows = self::fetch_rows( SubmissionFilter::for_scope( 0, $assignment_id ) );

		$stream = self::open_stream();
		self::write_row(
			$stream,
			[
				\_x( 'Submitted At', 'assignment csv', 'power-course' ),
				\_x( 'Student Email', 'assignment csv', 'power-course' ),
				\_x( 'Student Name', 'assignment csv', 'power-course' ),
				\_x( 'Status', 'assignment csv', 'power-course' ),
				\_x( 'Text Content', 'assignment csv', 'power-course' ),
				\_x( 'Attachment Count', 'assignment csv', 'power-course' ),
				\_x( 'Attachment List', 'assignment csv', 'power-course' ),
				\_x( 'Graded At', 'assignment csv', 'power-course' ),
				\_x( 'Feedback', 'assignment csv', 'power-course' ),
			]
		);

		$submissions = self::to_submissions( $rows );
		$file_names  = self::collect_attachment_names( $submissions );

		foreach ( $rows as $index => $row ) {
			$submission = $submissions[ $index ];

			self::write_row(
				$stream,
				[
					self::to_utc_iso( $submission->submitted_at ),
					self::cell( $row['customer_email'] ?? null ),
					(string) AssignmentUtils::resolve_customer_name( self::to_joined_user_id( $row ) ),
					AssignmentUtils::status_label( $submission->status ),
					(string) $submission->text_body,
					(string) \count( $submission->attachments ),
					self::build_attachment_list( $submission, $file_names ),
					self::to_utc_iso( $submission->graded_at ),
					(string) $submission->feedback,
				]
			);
		}

		return $stream;
	}

	/**
	 * 批改用（可往返）CSV（匯出批改用CSV.feature）
	 *
	 * 欄位：submission_id / 學員 Email / 學員姓名 / 作業 / 繳交時間 / 目前狀態 /
	 *       狀態(可改 pending/pass/fail) / 講師回饋(可改)
	 * submission_id 是回寫時的對齊鍵，固定英文不翻譯。
	 *
	 * @param array<string, mixed> $filters 篩選條件（course_id / status，非法 status 視同 all）
	 * @return resource 可讀取的 CSV 串流（呼叫端負責 rewind / 關閉）
	 * @throws AssignmentException 無法開啟暫存串流時（500）。
	 */
	public static function write_grading_csv( array $filters = [] ) {
		$rows = self::fetch_rows(
			SubmissionFilter::from_params(
				[
					'status'        => $filters['status'] ?? null,
					'course_id'     => $filters['course_id'] ?? null,
					'assignment_id' => $filters['assignment_id'] ?? null,
				]
			)
		);

		$stream = self::open_stream();
		self::write_row(
			$stream,
			[
				'submission_id',
				\_x( 'Student Email', 'assignment csv', 'power-course' ),
				\_x( 'Student Name', 'assignment csv', 'power-course' ),
				\_x( 'Assignment', 'assignment csv', 'power-course' ),
				\_x( 'Submitted At', 'assignment csv', 'power-course' ),
				\_x( 'Current Status', 'assignment csv', 'power-course' ),
				\_x( 'Status (editable: pending/pass/fail)', 'assignment csv', 'power-course' ),
				\_x( 'Feedback (editable)', 'assignment csv', 'power-course' ),
			]
		);

		$submissions = self::to_submissions( $rows );

		foreach ( $rows as $index => $row ) {
			$submission = $submissions[ $index ];

			self::write_row(
				$stream,
				[
					(string) $submission->id,
					self::cell( $row['customer_email'] ?? null ),
					(string) AssignmentUtils::resolve_customer_name( self::to_joined_user_id( $row ) ),
					self::cell( $row['assignment_title'] ?? null ),
					self::to_utc_iso( $submission->submitted_at ),
					AssignmentUtils::status_label( $submission->status ),
					$submission->status,
					(string) $submission->feedback,
				]
			);
		}

		return $stream;
	}

	/**
	 * 單一作業繳交 CSV 的下載檔名
	 *
	 * 規則（對齊參考實作 exportSubmissionsCsv）：
	 * assignment-{標題中非文字字元換成 _，取前 40 字}-{UTC Y-m-d-H-i-s}.csv
	 *
	 * @param string $title 作業標題
	 * @return string
	 */
	public static function submission_file_name( string $title ): string {
		$safe = (string) \preg_replace( '/[^\p{L}\p{N}]+/u', '_', $title );

		return \sprintf(
			'assignment-%s-%s.csv',
			\mb_substr( $safe, 0, self::FILE_NAME_TITLE_LENGTH ),
			self::file_name_timestamp()
		);
	}

	/**
	 * 批改用 CSV 的下載檔名：submissions-grades-{UTC Y-m-d-H-i-s}.csv
	 *
	 * @return string
	 */
	public static function grading_file_name(): string {
		return \sprintf( 'submissions-grades-%s.csv', self::file_name_timestamp() );
	}

	// ========== 查詢 ==========

	/**
	 * 依篩選條件取出繳交列（最多 MAX_ROWS 筆），並預熱學員快取
	 *
	 * SubmissionFilter::get_limit() 是「列表分頁」用的上限（夾在 1～200），CSV 一律用
	 * MAX_ROWS，故此處只取它的 FROM / WHERE / ORDER BY，不取 limit。
	 *
	 * @param SubmissionFilter $filter 篩選條件
	 * @return array<int, array<string, mixed>>
	 */
	private static function fetch_rows( SubmissionFilter $filter ): array {
		global $wpdb;

		[ $where, $args ] = $filter->where();
		$from             = $filter->from_sql();
		$order_by         = $filter->order_by();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FROM / WHERE 片段由 SubmissionFilter 產生，僅含佔位符與固定識別字
		$sql = "SELECT s.*, p.post_title AS assignment_title,
					u.ID AS customer_user_id, u.user_email AS customer_email
				FROM {$from}
				WHERE {$where}
				{$order_by}
				LIMIT %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, \array_merge( $args, [ self::MAX_ROWS ] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		// 迴圈外一次灌滿使用者與 user meta 的物件快取，resolve_customer_name() 才不會逐列查（R-15）
		$user_ids = [];
		foreach ( $rows as $row ) {
			$user_ids[] = self::to_joined_user_id( $row );
		}
		AssignmentUtils::prime_user_caches( $user_ids );

		return $rows;
	}

	/**
	 * 查詢結果列轉 Submission Model（保持與 $rows 相同的索引順序）
	 *
	 * @param array<int, array<string, mixed>> $rows 查詢結果列
	 * @return array<int, SubmissionModel>
	 */
	private static function to_submissions( array $rows ): array {
		$submissions = [];
		foreach ( $rows as $index => $row ) {
			$submissions[ $index ] = SubmissionModel::from_row( $row );
		}

		return $submissions;
	}

	/**
	 * 一次取回本批次所有附件的原始檔名（繳交列的 attachments JSON 可能沒存檔名）
	 *
	 * @param array<int, SubmissionModel> $submissions 本批次繳交
	 * @return array<int, string> 附件 ID => 原始檔名
	 */
	private static function collect_attachment_names( array $submissions ): array {
		$attachment_ids = [];
		foreach ( $submissions as $submission ) {
			$attachment_ids = \array_merge( $attachment_ids, $submission->get_attachment_ids() );
		}

		$names = [];
		foreach ( AttachmentRepository::get_refs( $attachment_ids ) as $attachment_id => $ref ) {
			$names[ $attachment_id ] = $ref['file_name'];
		}

		return $names;
	}

	/**
	 * 組出附件清單欄（以 " | " 分隔原始檔名；查不到檔名時退回附件 ID）
	 *
	 * @param SubmissionModel    $submission 繳交
	 * @param array<int, string> $file_names 附件 ID => 原始檔名
	 * @return string
	 */
	private static function build_attachment_list( SubmissionModel $submission, array $file_names ): string {
		$labels = [];
		foreach ( $submission->attachments as $ref ) {
			$attachment_id = $ref['attachment_id'];
			$file_name     = $ref['file_name'];

			if ( null === $file_name || '' === $file_name ) {
				$file_name = $file_names[ $attachment_id ] ?? '';
			}

			$labels[] = '' === $file_name ? (string) $attachment_id : $file_name;
		}

		return \implode( self::ATTACHMENT_SEPARATOR, $labels );
	}

	// ========== CSV 輸出 ==========

	/**
	 * 開啟暫存串流並寫入 UTF-8 BOM
	 *
	 * @return resource
	 * @throws AssignmentException 無法開啟暫存串流時（500）。
	 */
	private static function open_stream() {
		$stream = \fopen( self::TEMP_STREAM, 'r+' );

		if ( false === $stream ) {
			throw AssignmentException::internal( \__( 'Failed to generate CSV file', 'power-course' ) );
		}

		\fwrite( $stream, self::BOM );

		return $stream;
	}

	/**
	 * 寫一列 CSV（含結尾 CRLF）
	 *
	 * 每一列都補 CRLF（含最後一列），這樣「只有標題列」的 CSV 也是合法的 CRLF 檔案。
	 *
	 * @param resource           $stream 目標串流
	 * @param array<int, string> $cells  各欄位值（未跳脫）
	 */
	private static function write_row( $stream, array $cells ): void {
		\fwrite( $stream, \implode( ',', \array_map( [ self::class, 'escape' ], $cells ) ) . self::EOL );
	}

	/**
	 * CSV 儲存格跳脫：含逗號 / 雙引號 / 換行時以雙引號包覆，內部雙引號重複一次
	 *
	 * @param string $value 原始值
	 * @return string
	 */
	private static function escape( string $value ): string {
		if ( 1 !== \preg_match( '/[",\r\n]/', $value ) ) {
			return $value;
		}

		return '"' . \str_replace( '"', '""', $value ) . '"';
	}

	// ========== 型別 / 格式轉換 ==========

	/**
	 * 站台時區時間字串 → ISO 8601 UTC（含固定毫秒 .000，DEC-03）
	 *
	 * @param string|null $datetime 站台時區的 Y-m-d H:i:s（null / 空字串代表沒有值）
	 * @return string 空值時回傳空字串
	 */
	private static function to_utc_iso( ?string $datetime ): string {
		$value = \trim( (string) $datetime );

		if ( '' === $value || \str_starts_with( $value, '0000-00-00' ) ) {
			return '';
		}

		return \get_gmt_from_date( $value, self::ISO_8601_UTC );
	}

	/**
	 * 檔名用的 UTC 時間戳（吃 Clock::now()，測試可注入固定時間）
	 *
	 * @return string
	 */
	private static function file_name_timestamp(): string {
		return \gmdate( self::FILE_NAME_TIMESTAMP_FORMAT, Clock::now() );
	}

	/**
	 * 取出 LEFT JOIN wp_users 得到的 u.ID（學員已被刪除時為 0）
	 *
	 * @param array<string, mixed> $row 查詢結果列
	 * @return int
	 */
	private static function to_joined_user_id( array $row ): int {
		return isset( $row['customer_user_id'] ) && \is_numeric( $row['customer_user_id'] ) ? (int) $row['customer_user_id'] : 0;
	}

	/**
	 * 資料庫值轉 CSV 儲存格字串（null / 非純量一律空字串）
	 *
	 * @param mixed $value 資料庫值
	 * @return string
	 */
	private static function cell( mixed $value ): string {
		return ( null === $value || ! \is_scalar( $value ) ) ? '' : (string) $value;
	}
}
