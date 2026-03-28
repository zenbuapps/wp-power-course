<?php
/**
 * 對 wp_pc_student_logs 的 Record
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\StudentLog;

/**
 * Class StudentLog
 */
final class StudentLog {

	/**
	 * 紀錄 ID
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * 使用者 ID
	 *
	 * @var int
	 */
	public int $user_id;

	/**
	 * 課程 ID
	 *
	 * @var int
	 */
	public int $course_id;

	/**
	 * 章節 ID
	 *
	 * @var int
	 */
	public int $chapter_id;

	/**
	 * 標題
	 *
	 * @var string
	 */
	public string $title;

	/**
	 * 內容
	 *
	 * @var string
	 */
	public string $content;

	/**
	 * 紀錄類型
	 *
	 * @var string
	 */
	public string $log_type;

	/**
	 * 使用者 IP
	 *
	 * @var string
	 */
	public string $user_ip;

	/**
	 * 建立時間
	 *
	 * @var string
	 */
	public string $created_at;

	/**
	 * 建構子
	 *
	 * @return void
	 */
	public function __construct() {
	}


	/**
	 * 取得實例
	 *
	 * @param int|object{id: string, user_id: string, course_id: string, chapter_id: string, title: string, content: string, log_type: string, user_ip: string, created_at: string} $maybe_id_or_object 紀錄 ID 或 紀錄物件.
	 * @return StudentLog
	 */
	public static function instance( object|int $maybe_id_or_object ): StudentLog {
		$crud     = CRUD::instance();
		$schema   = $crud->schema;
		$object   = \is_numeric( $maybe_id_or_object ) ? $crud->get( (int) $maybe_id_or_object ) : $maybe_id_or_object;
		$instance = new self();
		$int_keys = [ 'id', 'user_id', 'course_id', 'chapter_id' ];
		foreach ( $schema as $key => $value ) {
			if ( isset( $object->$key ) ) {
				if ( \in_array( $key, $int_keys, true ) ) {
					$instance->$key = (int) $object->$key;
				} else {
					$instance->$key = (string) $object->$key;
				}
			}
		}

		return $instance;
	}
}
