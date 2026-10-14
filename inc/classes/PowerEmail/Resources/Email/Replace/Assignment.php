<?php
/**
 * Email Assignment Replace（Issue #131 P5）
 *
 * 作業相關信件（assignment_graded / assignment_due_soon）專用的變數替換。
 * 掛在 power_course_assignment_email_* filter 上，與課程 / 章節的 filter 分開——
 * 作業信件的第 3 / 4 參數是 assignment_id / $ctx（批改快照），不是 course_id / chapter_id。
 *
 * ⚠️ 本檔的 Assignment 指「章節作業」，與 Resources\Teacher\Service\Assignment（講師指派）無關（R-01）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\PowerEmail\Resources\Email\Replace;

use J7\PowerCourse\Resources\Assignment\Model\Assignment as AssignmentModel;
use J7\PowerCourse\Resources\Assignment\Utils\Utils as AssignmentUtils;

/**
 * Class Assignment
 */
abstract class Assignment extends ReplaceBase {

	/**
	 * 前綴
	 *
	 * @var string
	 */
	public static $prefix = 'assignment_';

	/**
	 * @var array<string, string> 取代字串的 Schema（value 為英文 label，實際顯示請透過 get_localized_schemas()）
	 */
	public static $schema = [
		'title'    => 'Assignment title',
		'status'   => 'Assignment grading result',
		'feedback' => 'Assignment feedback',
		'due_at'   => 'Assignment due date',
	];

	/**
	 * 取得已翻譯的 Schema（含前綴 key + 翻譯後的 label）
	 *
	 * @return array<string, string>
	 */
	public static function get_localized_schemas(): array {
		return [
			self::$prefix . 'title'    => \__( 'Assignment title', 'power-course' ),
			self::$prefix . 'status'   => \__( 'Assignment grading result', 'power-course' ),
			self::$prefix . 'feedback' => \__( 'Assignment feedback', 'power-course' ),
			self::$prefix . 'due_at'   => \__( 'Assignment due date', 'power-course' ),
		];
	}

	/**
	 * 取得取代字串後的 HTML
	 *
	 * {assignment_status} / {assignment_feedback} 取自 $ctx（排程當下的批改快照），
	 * 不是重新查 DB —— identifier 已把「這一次批改」釘住（DEC-10），
	 * 信件內容也必須是同一次批改的結果，才不會在改判後寄出前被後來的評語覆蓋。
	 *
	 * @param string $html          HTML
	 * @param int    $user_id       用戶 ID（本類別未使用，維持與其他 Replace 一致的簽名）
	 * @param int    $assignment_id 作業 post ID
	 * @param mixed  $ctx           排程時帶入的作業信件情境（status / feedback 等）；
	 *                              經 Action Scheduler 反序列化後型別不保證，故在方法內守門
	 *
	 * @return string 格式化後的 HTML
	 */
	public static function replace_string( $html, $user_id, $assignment_id, $ctx ): string {
		$assignment_id = (int) $assignment_id;
		if ( ! $assignment_id ) {
			return $html;
		}

		$assignment = AssignmentModel::instance( $assignment_id );
		if ( ! $assignment instanceof AssignmentModel ) {
			return $html;
		}

		$ctx      = \is_array( $ctx ) ? $ctx : [];
		$status   = \array_key_exists( 'status', $ctx ) && \is_scalar( $ctx['status'] ) ? (string) $ctx['status'] : '';
		$feedback = \array_key_exists( 'feedback', $ctx ) && \is_scalar( $ctx['feedback'] ) ? (string) $ctx['feedback'] : '';

		$values = [
			'title'    => $assignment->title,
			'status'   => '' === $status ? '' : AssignmentUtils::status_label( $status ),
			'feedback' => $feedback,
			'due_at'   => $assignment->due_at > 0 ? (string) \wp_date( 'Y-m-d H:i', $assignment->due_at ) : '',
		];

		$schema_keys   = [];
		$schema_values = [];
		foreach ( self::$schema as $key => $label ) {
			$schema_keys[]   = '{' . self::$prefix . $key . '}';
			$schema_values[] = $values[ $key ] ?? '';
		}

		return \str_replace( $schema_keys, $schema_values, $html );
	}
}
