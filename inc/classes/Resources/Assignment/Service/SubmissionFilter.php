<?php
/**
 * 作業繳交查詢的篩選條件建構器（Issue #131 P4）
 *
 * 繳交列表、批改統計、有繳交紀錄的課程、學員背景資料與兩種 CSV 匯出
 * 一律共用本類別產生的 FROM / WHERE / ORDER BY，避免多份篩選 SQL 各自漂移
 * （計劃 §9 P4 Refactor：「列表 / CSV 共用同一個 filter builder」）。
 *
 * 共同語意：
 *   - 只看 publish 的作業（垃圾桶中作業的繳交視為不存在）
 *   - status 非 all / pending / pass / fail 時視同 all
 *   - course_id 透過作業的 parent_course_id meta 對應，與 assignment_id 取交集
 *   - limit 夾限 1～200（預設 50）、offset 不得小於 0
 *   - 排序一律 submitted_at 由新到舊，同時間以 id 由大到小（列表與 CSV 順序一致）
 *
 * 所有 WHERE 片段皆回傳 $wpdb->prepare() 用的佔位符與參數，呼叫端不得自行插值。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Service;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Assignment\Core\CPT;

/**
 * Class SubmissionFilter
 */
final class SubmissionFilter {

	/** 不限批改狀態 */
	public const STATUS_ALL = 'all';

	/** 每頁筆數預設值 */
	public const DEFAULT_LIMIT = 50;

	/** 每頁筆數下限 */
	public const MIN_LIMIT = 1;

	/** 每頁筆數上限 */
	public const MAX_LIMIT = 200;

	/** @var array<int, string> 允許的 status 參數值（其餘一律視同 all） */
	private const ALLOWED_STATUSES = [ self::STATUS_ALL, 'pending', 'pass', 'fail' ];

	/**
	 * Constructor（一律透過 from_params() / for_scope() 建立）
	 *
	 * @param string $status        all | pending | pass | fail
	 * @param int    $course_id     課程（商品）ID，0 = 不限
	 * @param int    $assignment_id 作業 ID，0 = 不限
	 * @param int    $user_id       學員 user ID，0 = 不限
	 * @param int    $limit         每頁筆數（已夾限）
	 * @param int    $offset        位移（已夾限）
	 */
	private function __construct(
		private string $status,
		private int $course_id,
		private int $assignment_id,
		private int $user_id,
		private int $limit,
		private int $offset
	) {}

	/**
	 * 由 REST 查詢參數建立（繳交列表、批改統計、CSV 匯出共用）
	 *
	 * @param array<string, mixed> $params 查詢參數（status / course_id / assignment_id / limit / offset）
	 * @return self
	 */
	public static function from_params( array $params ): self {
		return new self(
			self::normalize_status( $params['status'] ?? null ),
			self::to_id( $params['course_id'] ?? null ),
			self::to_id( $params['assignment_id'] ?? null ),
			0,
			self::normalize_limit( $params['limit'] ?? null ),
			self::normalize_offset( $params['offset'] ?? null )
		);
	}

	/**
	 * 由明確的範圍建立（學員背景資料、單一作業 CSV 等不吃 REST 分頁參數的場景）
	 *
	 * @param int    $course_id     課程（商品）ID，0 = 不限
	 * @param int    $assignment_id 作業 ID，0 = 不限
	 * @param int    $user_id       學員 user ID，0 = 不限
	 * @param string $status        批改狀態，預設不限
	 * @param int    $limit         每頁筆數（未給時用預設值，仍會夾限）
	 * @return self
	 */
	public static function for_scope( int $course_id = 0, int $assignment_id = 0, int $user_id = 0, string $status = self::STATUS_ALL, int $limit = self::DEFAULT_LIMIT ): self {
		return new self(
			self::normalize_status( $status ),
			\max( 0, $course_id ),
			\max( 0, $assignment_id ),
			\max( 0, $user_id ),
			self::normalize_limit( $limit ),
			0
		);
	}

	/**
	 * 取得夾限後的 limit
	 *
	 * @return int
	 */
	public function get_limit(): int {
		return $this->limit;
	}

	/**
	 * 取得夾限後的 offset
	 *
	 * @return int
	 */
	public function get_offset(): int {
		return $this->offset;
	}

	/**
	 * 取得篩選後的課程 ID（0 = 不限）
	 *
	 * @return int
	 */
	public function get_course_id(): int {
		return $this->course_id;
	}

	/**
	 * FROM 子句（繳交 JOIN 作業 → 課程 → 使用者）
	 *
	 * 別名固定為 s（繳交）、p（作業 post）、pm（parent_course_id meta）、
	 * c（課程 post）、u（使用者），呼叫端的 SELECT / ORDER BY 依此取欄位。
	 * 使用者一律 LEFT JOIN：學員被刪除時繳交仍要出現在列表與 CSV 中。
	 *
	 * @return string
	 */
	public function from_sql(): string {
		global $wpdb;

		$submissions = $wpdb->prefix . Plugin::ASSIGNMENT_SUBMISSIONS_TABLE_NAME;

		return "{$submissions} AS s
			INNER JOIN {$wpdb->posts} AS p ON p.ID = s.assignment_id
			LEFT JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID AND pm.meta_key = 'parent_course_id'
			LEFT JOIN {$wpdb->posts} AS c ON c.ID = CAST(pm.meta_value AS UNSIGNED)
			LEFT JOIN {$wpdb->users} AS u ON u.ID = s.user_id";
	}

	/**
	 * WHERE 子句與對應參數
	 *
	 * 一定至少有 post_type / post_status 兩個佔位符，呼叫端可無條件使用
	 * $wpdb->prepare()（沒有佔位符時 prepare() 會觸發 _doing_it_wrong）。
	 *
	 * @return array{0: string, 1: array<int, string|int>} [WHERE 片段, 參數]
	 */
	public function where(): array {
		$clauses = [
			'p.post_type = %s',
			'p.post_status = %s',
		];
		$args    = [ CPT::POST_TYPE, 'publish' ];

		if ( self::STATUS_ALL !== $this->status ) {
			$clauses[] = 's.status = %s';
			$args[]    = $this->status;
		}

		if ( $this->assignment_id > 0 ) {
			$clauses[] = 's.assignment_id = %d';
			$args[]    = $this->assignment_id;
		}

		if ( $this->course_id > 0 ) {
			$clauses[] = 'pm.meta_value = %s';
			$args[]    = (string) $this->course_id;
		}

		if ( $this->user_id > 0 ) {
			$clauses[] = 's.user_id = %d';
			$args[]    = $this->user_id;
		}

		return [ \implode( ' AND ', $clauses ), $args ];
	}

	/**
	 * 排序子句（列表與 CSV 必須一致）
	 *
	 * @return string
	 */
	public function order_by(): string {
		return 'ORDER BY s.submitted_at DESC, s.id DESC';
	}

	/**
	 * 批改狀態正規化：非法值一律視同 all
	 *
	 * @param mixed $value 原始參數
	 * @return string
	 */
	private static function normalize_status( mixed $value ): string {
		$status = \is_string( $value ) ? \strtolower( \trim( $value ) ) : '';

		return \in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : self::STATUS_ALL;
	}

	/**
	 * 每頁筆數正規化：未提供用預設 50，其餘夾限在 1～200
	 *
	 * @param mixed $value 原始參數
	 * @return int
	 */
	private static function normalize_limit( mixed $value ): int {
		$limit = self::to_int( $value );
		if ( null === $limit ) {
			return self::DEFAULT_LIMIT;
		}

		return \min( self::MAX_LIMIT, \max( self::MIN_LIMIT, $limit ) );
	}

	/**
	 * 位移正規化：未提供或小於 0 一律視為 0
	 *
	 * @param mixed $value 原始參數
	 * @return int
	 */
	private static function normalize_offset( mixed $value ): int {
		$offset = self::to_int( $value );

		return ( null === $offset || $offset < 0 ) ? 0 : $offset;
	}

	/**
	 * 轉為非負 ID（空字串 / 非數字 / 負數一律視為 0 = 不限）
	 *
	 * @param mixed $value 原始參數
	 * @return int
	 */
	private static function to_id( mixed $value ): int {
		$int = self::to_int( $value );

		return ( null === $int || $int < 0 ) ? 0 : $int;
	}

	/**
	 * 轉為整數，無法解析（null、空字串、非數字）時回傳 null
	 *
	 * @param mixed $value 原始參數
	 * @return int|null
	 */
	private static function to_int( mixed $value ): ?int {
		if ( \is_int( $value ) ) {
			return $value;
		}
		if ( \is_string( $value ) && 1 === \preg_match( '/^-?\d+$/', \trim( $value ) ) ) {
			return (int) \trim( $value );
		}

		return null;
	}
}
