<?php
/**
 * 作業批改頁的報表查詢服務（Issue #131 P4）
 *
 * 對應四支 feature：
 *   - 查詢作業繳交列表.feature      → list_submissions()
 *   - 查詢作業批改統計.feature      → stats()
 *   - 查詢有繳交紀錄的課程.feature  → courses_with_submissions()
 *   - 查詢作業學員背景資料.feature  → student_overview()
 *
 * 篩選條件（status / course_id / assignment_id / 排除垃圾桶作業 / 排序）一律由
 * Service\SubmissionFilter 產生，CSV 匯出共用同一份，不得另寫第二份篩選 SQL。
 * 所有 SQL 一律 $wpdb->prepare()。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Resources\Assignment\Model\Submission as SubmissionModel;
use J7\PowerCourse\Resources\Assignment\Utils\AssignmentException;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\Datetime;

/**
 * Class Reporting
 */
final class Reporting {

	/**
	 * 繳交列表（{items, total}）
	 *
	 * 依 submitted_at 由新到舊，附學員 / 作業 / 課程摘要、附件（含附件大類）與討論串統計。
	 *
	 * @param array<string, mixed> $params 查詢參數（status / course_id / assignment_id / limit / offset）
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public static function list_submissions( array $params ): array {
		global $wpdb;

		$filter          = SubmissionFilter::from_params( $params );
		[ $where, $args ] = $filter->where();
		$from            = $filter->from_sql();

		$order_by = $filter->order_by();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FROM / WHERE 片段由 SubmissionFilter 產生，僅含佔位符與固定識別字
		$count_sql = "SELECT COUNT(*) FROM {$from} WHERE {$where}";
		$list_sql  = "SELECT s.*, p.post_title AS assignment_title, c.ID AS course_id, c.post_title AS course_title,
					u.ID AS customer_user_id, u.user_email AS customer_email
				FROM {$from}
				WHERE {$where}
				{$order_by}
				LIMIT %d OFFSET %d";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $count_sql, $args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $list_sql, \array_merge( $args, [ $filter->get_limit(), $filter->get_offset() ] ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return [
			'items' => self::enrich_rows( $rows ),
			'total' => $total,
		];
	}

	/**
	 * 批改統計（total / pending / pass / fail / pass_rate / avg_grading_seconds）
	 *
	 * 通過率為通過佔「已判定（pass + fail）」的百分比，分母為 0 時回 0；
	 * 平均批改時間計入所有 graded_at 不為空的繳交（含被改回待批改者），無則 null。
	 *
	 * @param array<string, mixed> $params 查詢參數（course_id / assignment_id）
	 * @return array{total: int, pending: int, pass: int, fail: int, pass_rate: int, avg_grading_seconds: int|null}
	 */
	public static function stats( array $params ): array {
		global $wpdb;

		$filter          = SubmissionFilter::for_scope(
			self::to_id( $params['course_id'] ?? null ),
			self::to_id( $params['assignment_id'] ?? null )
		);
		[ $where, $args ] = $filter->where();
		$from            = $filter->from_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FROM / WHERE 片段由 SubmissionFilter 產生，僅含佔位符與固定識別字
		$sql = "SELECT COUNT(*) AS total,
					SUM( CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END ) AS pending_count,
					SUM( CASE WHEN s.status = 'pass' THEN 1 ELSE 0 END ) AS pass_count,
					SUM( CASE WHEN s.status = 'fail' THEN 1 ELSE 0 END ) AS fail_count,
					AVG( CASE WHEN s.graded_at IS NOT NULL THEN TIMESTAMPDIFF( SECOND, s.submitted_at, s.graded_at ) END ) AS avg_seconds
				FROM {$from}
				WHERE {$where}";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$row     = \is_array( $row ) ? $row : [];
		$pass    = self::to_int( $row['pass_count'] ?? null );
		$fail    = self::to_int( $row['fail_count'] ?? null );
		$judged  = $pass + $fail;
		$average = $row['avg_seconds'] ?? null;

		return [
			'total'               => self::to_int( $row['total'] ?? null ),
			'pending'             => self::to_int( $row['pending_count'] ?? null ),
			'pass'                => $pass,
			'fail'                => $fail,
			'pass_rate'           => $judged > 0 ? (int) \round( $pass / $judged * 100 ) : 0,
			'avg_grading_seconds' => ( null === $average || '' === $average ) ? null : (int) \round( (float) $average ),
		];
	}

	/**
	 * 有繳交紀錄的課程（扁平陣列），依繳交數由多到少、同數量依課程名稱排序
	 *
	 * @return array<int, array{id: int, title: string, count: int}>
	 */
	public static function courses_with_submissions(): array {
		global $wpdb;

		$filter          = SubmissionFilter::for_scope();
		[ $where, $args ] = $filter->where();
		$from            = $filter->from_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FROM / WHERE 片段由 SubmissionFilter 產生，僅含佔位符與固定識別字
		$sql = "SELECT c.ID AS course_id, c.post_title AS course_title, COUNT(*) AS submission_count
				FROM {$from}
				WHERE {$where} AND c.ID IS NOT NULL
				GROUP BY c.ID, c.post_title
				ORDER BY submission_count DESC, c.post_title ASC";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$courses = [];
		foreach ( $rows as $row ) {
			$courses[] = [
				'id'    => self::to_int( $row['course_id'] ?? null ),
				'title' => isset( $row['course_title'] ) && \is_scalar( $row['course_title'] ) ? (string) $row['course_title'] : '',
				'count' => self::to_int( $row['submission_count'] ?? null ),
			];
		}

		return $courses;
	}

	/**
	 * 學員背景資料（基本資料、本課程作業統計、學習進度）
	 *
	 * 課程優先取 course_id，沒有時由 assignment_id 反推；
	 * 學員或課程不存在時對應欄位為 null，不報錯。
	 *
	 * @param array<string, mixed> $params 查詢參數（user_id 必填、course_id / assignment_id 選填）
	 * @return array{user: array{id: int, name: string|null, email: string|null, registered_at: string|null}, course: array{id: int|null, title: string|null}, stats: array{submitted: int, passed: int, pending: int, failed: int}, progress: array{completed_chapters: int, total_chapters: int, percent: float}}
	 * @throws AssignmentException 未提供 user_id 時（400）。
	 */
	public static function student_overview( array $params ): array {
		$user_id = self::to_id( $params['user_id'] ?? null );
		if ( $user_id <= 0 ) {
			throw AssignmentException::invalid_param( \__( 'user_id is required', 'power-course' ) );
		}

		$course_id = self::resolve_course_id( $params );

		return [
			'user'     => self::build_user_summary( $user_id ),
			'course'   => self::build_course_summary( $course_id ),
			'stats'    => self::build_student_stats( $user_id, $course_id ),
			'progress' => self::build_student_progress( $user_id, $course_id ),
		];
	}

	// ========== 內部組裝 ==========

	/**
	 * 解析要彙整的課程：course_id 優先，否則由 assignment_id 反推
	 *
	 * @param array<string, mixed> $params 查詢參數
	 * @return int 無法解析時回傳 0
	 */
	private static function resolve_course_id( array $params ): int {
		$course_id = self::to_id( $params['course_id'] ?? null );
		if ( $course_id > 0 ) {
			return $course_id;
		}

		$assignment_id = self::to_id( $params['assignment_id'] ?? null );
		if ( $assignment_id <= 0 ) {
			return 0;
		}

		$assignment = Query::find( $assignment_id );

		return null === $assignment ? 0 : $assignment->course_id;
	}

	/**
	 * 學員基本資料（不存在時各欄位為 null，但 id 照樣回傳查詢值）
	 *
	 * @param int $user_id 學員 user ID
	 * @return array{id: int, name: string|null, email: string|null, registered_at: string|null}
	 */
	private static function build_user_summary( int $user_id ): array {
		// 與列表同一條路徑：先預熱 user + user meta 快取，再讓 get_formatted_name() 走記憶體
		AssignmentUtils::prime_user_caches( [ $user_id ] );

		$user = \get_user_by( 'id', $user_id );

		if ( ! $user instanceof \WP_User ) {
			return [
				'id'            => $user_id,
				'name'          => null,
				'email'         => null,
				'registered_at' => null,
			];
		}

		$registered = (string) $user->user_registered;

		return [
			'id'            => $user_id,
			'name'          => AssignmentUtils::resolve_customer_name( $user_id ),
			'email'         => (string) $user->user_email,
			// user_registered 在 wp_users 以 GMT 儲存，回應前一律轉站台時區（Issue #233）
			'registered_at' => '' === $registered ? null : Datetime::to_site_timezone( $registered ),
		];
	}

	/**
	 * 課程摘要（無法解析課程時為 null）
	 *
	 * @param int $course_id 課程（商品）ID，0 = 無法解析
	 * @return array{id: int|null, title: string|null}
	 */
	private static function build_course_summary( int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [
				'id'    => null,
				'title' => null,
			];
		}

		$course = \get_post( $course_id );

		return [
			'id'    => $course_id,
			'title' => $course instanceof \WP_Post ? (string) $course->post_title : null,
		];
	}

	/**
	 * 學員在指定課程的作業狀態統計（無法解析課程時全部為 0）
	 *
	 * @param int $user_id   學員 user ID
	 * @param int $course_id 課程（商品）ID，0 = 無法解析
	 * @return array{submitted: int, passed: int, pending: int, failed: int}
	 */
	private static function build_student_stats( int $user_id, int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [
				'submitted' => 0,
				'passed'    => 0,
				'pending'   => 0,
				'failed'    => 0,
			];
		}

		global $wpdb;

		$filter          = SubmissionFilter::for_scope( $course_id, 0, $user_id );
		[ $where, $args ] = $filter->where();
		$from            = $filter->from_sql();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- FROM / WHERE 片段由 SubmissionFilter 產生，僅含佔位符與固定識別字
		$sql = "SELECT COUNT(*) AS submitted,
					SUM( CASE WHEN s.status = 'pass' THEN 1 ELSE 0 END ) AS passed,
					SUM( CASE WHEN s.status = 'pending' THEN 1 ELSE 0 END ) AS pending_count,
					SUM( CASE WHEN s.status = 'fail' THEN 1 ELSE 0 END ) AS failed
				FROM {$from}
				WHERE {$where}";
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/** @var array<string, mixed>|null $row */
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( $sql, $args ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		$row = \is_array( $row ) ? $row : [];

		return [
			'submitted' => self::to_int( $row['submitted'] ?? null ),
			'passed'    => self::to_int( $row['passed'] ?? null ),
			'pending'   => self::to_int( $row['pending_count'] ?? null ),
			'failed'    => self::to_int( $row['failed'] ?? null ),
		];
	}

	/**
	 * 學員在指定課程的學習進度（章節攤平計算，百分比取小數一位）
	 *
	 * @param int $user_id   學員 user ID
	 * @param int $course_id 課程（商品）ID，0 = 無法解析
	 * @return array{completed_chapters: int, total_chapters: int, percent: float}
	 */
	private static function build_student_progress( int $user_id, int $course_id ): array {
		if ( $course_id <= 0 ) {
			return [
				'completed_chapters' => 0,
				'total_chapters'     => 0,
				'percent'            => 0.0,
			];
		}

		$total     = \count( self::unique_chapter_ids( ChapterUtils::get_flatten_post_ids( $course_id ) ) );
		$completed = \count( self::unique_chapter_ids( CourseUtils::get_finished_sub_chapters( $course_id, $user_id, true ) ) );

		return [
			'completed_chapters' => $completed,
			'total_chapters'     => $total,
			'percent'            => $total > 0 ? \round( $completed / $total * 100, 1 ) : 0.0,
		];
	}

	/**
	 * 章節清單去重成 ID 集合
	 *
	 * ChapterUtils::get_flatten_post_ids() 會把「自己也帶 parent_course_id meta 的子章節」
	 * 同時算進頂層與子孫兩次，章節數必須去重後再計算，否則進度的分母與分子都會被灌水。
	 *
	 * @param array<int, mixed> $chapters 章節 ID 清單（可能含重複）
	 * @return array<int, int> 去重後的章節 ID
	 */
	private static function unique_chapter_ids( array $chapters ): array {
		$ids = [];
		foreach ( $chapters as $chapter ) {
			if ( \is_numeric( $chapter ) ) {
				$ids[ (int) $chapter ] = true;
			}
		}

		return \array_keys( $ids );
	}

	/**
	 * 把查詢結果列補上學員姓名、附件大類與討論串統計，組成列表回應
	 *
	 * 三種 enrich 都是「先收集本頁的 ID，再一次查完」：使用者與其 meta 走
	 * AssignmentUtils::prime_user_caches()（每批各 1 次查詢），附件檔名 / MIME 與
	 * 討論串統計各 1 次查詢；迴圈內的 get_formatted_name() 全部是物件快取命中，不再打 DB。
	 * customer_user_id 取自 LEFT JOIN 的 u.ID：為 null 就代表學員已被刪除。
	 *
	 * @param array<int, array<string, mixed>> $rows 查詢結果列
	 * @return array<int, array<string, mixed>>
	 */
	private static function enrich_rows( array $rows ): array {
		if ( ! $rows ) {
			return [];
		}

		$submissions    = [];
		$submission_ids = [];
		$attachment_ids = [];
		$user_ids       = [];

		foreach ( $rows as $row ) {
			$submission       = SubmissionModel::from_row( $row );
			$submissions[]    = [ $submission, $row ];
			$submission_ids[] = $submission->id;
			$attachment_ids   = \array_merge( $attachment_ids, $submission->get_attachment_ids() );

			$joined_user_id = self::to_joined_user_id( $row );
			if ( $joined_user_id > 0 ) {
				$user_ids[] = $joined_user_id;
			}
		}

		// 迴圈外一次灌滿使用者與 user meta 的物件快取，避免逐列查（R-15）
		AssignmentUtils::prime_user_caches( $user_ids );

		$refs    = AttachmentRepository::get_refs( $attachment_ids );
		$threads = MessageRepository::thread_summary( $submission_ids );

		$items = [];
		foreach ( $submissions as [ $submission, $row ] ) {
			$thread = $threads[ $submission->id ] ?? [
				'unread_count'    => 0,
				'message_count'   => 0,
				'last_message_at' => null,
			];

			$items[] = [
				'id'               => $submission->id,
				'assignment_id'    => $submission->assignment_id,
				'user_id'          => $submission->user_id,
				'customer_name'    => AssignmentUtils::resolve_customer_name( self::to_joined_user_id( $row ) ),
				'customer_email'   => self::to_nullable_string( $row['customer_email'] ?? null ),
				'assignment_title' => self::to_nullable_string( $row['assignment_title'] ?? null ),
				'course_id'        => isset( $row['course_id'] ) && \is_numeric( $row['course_id'] ) ? (int) $row['course_id'] : null,
				'course_title'     => self::to_nullable_string( $row['course_title'] ?? null ),
				'text_body'        => $submission->text_body,
				'submitted_at'     => $submission->submitted_at,
				'status'           => $submission->status,
				'feedback'         => $submission->feedback,
				'graded_by'        => $submission->graded_by,
				'graded_at'        => $submission->graded_at,
				'attachments'      => self::build_attachments( $submission, $refs ),
				'unread_count'     => $thread['unread_count'],
				'message_count'    => $thread['message_count'],
				'last_message_at'  => $thread['last_message_at'],
			];
		}

		return $items;
	}

	/**
	 * 取出 LEFT JOIN wp_users 得到的 u.ID（學員已被刪除時為 0）
	 *
	 * 用 u.ID 而不是 s.user_id 判斷「學員是否仍存在」：繳交列在學員刪除後仍保留（D16），
	 * s.user_id 會指向一個不存在的使用者，拿它去 get_user_by() 只會多打一次查空的查詢。
	 *
	 * @param array<string, mixed> $row 查詢結果列
	 * @return int
	 */
	private static function to_joined_user_id( array $row ): int {
		return isset( $row['customer_user_id'] ) && \is_numeric( $row['customer_user_id'] ) ? (int) $row['customer_user_id'] : 0;
	}

	/**
	 * 組出附件摘要（補檔名、推導附件大類；不輸出 stored_path / URL）
	 *
	 * @param SubmissionModel                                         $submission 繳交
	 * @param array<int, array{file_name: string, mime_type: string}> $refs       附件 ID => 檔名與 MIME
	 * @return array<int, array{attachment_id: int, file_name: string|null, kind: string}>
	 */
	private static function build_attachments( SubmissionModel $submission, array $refs ): array {
		$attachments = [];

		foreach ( $submission->attachments as $ref ) {
			$attachment_id = $ref['attachment_id'];
			$meta          = $refs[ $attachment_id ] ?? null;

			$file_name = $ref['file_name'];
			if ( ( null === $file_name || '' === $file_name ) && null !== $meta ) {
				$file_name = '' === $meta['file_name'] ? null : $meta['file_name'];
			}

			$attachments[] = [
				'attachment_id' => $attachment_id,
				'file_name'     => $file_name,
				'kind'          => AssignmentUtils::resolve_attachment_kind( null === $meta ? null : $meta['mime_type'], $file_name ),
			];
		}

		return $attachments;
	}

	// ========== 型別轉換 ==========

	/**
	 * 轉為非負 ID（空字串 / 非數字 / 負數一律視為 0）
	 *
	 * @param mixed $value 原始參數
	 * @return int
	 */
	private static function to_id( mixed $value ): int {
		if ( \is_int( $value ) ) {
			return \max( 0, $value );
		}
		if ( \is_string( $value ) && 1 === \preg_match( '/^\d+$/', \trim( $value ) ) ) {
			return (int) \trim( $value );
		}

		return 0;
	}

	/**
	 * 資料庫聚合值轉 int
	 *
	 * @param mixed $value 資料庫值
	 * @return int
	 */
	private static function to_int( mixed $value ): int {
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * 資料庫值轉 string|null（null 與空字串維持 null）
	 *
	 * @param mixed $value 資料庫值
	 * @return string|null
	 */
	private static function to_nullable_string( mixed $value ): ?string {
		if ( null === $value || ! \is_scalar( $value ) ) {
			return null;
		}

		$string = (string) $value;

		return '' === $string ? null : $string;
	}
}
