<?php
/**
 * 作業模組時鐘接縫（Issue #131，DEC-02）
 *
 * 作業模組內所有「現在時間」一律走 Clock::now()，
 * 測試可透過 power_course_assignment_now filter 注入固定時間（PHP 無法 mock time()）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Utils;

/**
 * Class Clock
 */
final class Clock {

	/** 取得現在時間的 filter 名稱 */
	public const NOW_FILTER = 'power_course_assignment_now';

	/**
	 * 取得現在時間（Unix timestamp）
	 *
	 * @return int
	 */
	public static function now(): int {
		return (int) \apply_filters( self::NOW_FILTER, \time() );
	}
}
