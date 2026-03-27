<?php
/**
 * 商品如果綁課程權限
 * 相關資料都存在 bind_courses_data 這個 post meta 中
 * 此類為 bind_courses_data 的單一課程資料
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

/**
 * Class BindCourseData
 */
final class BindCourseData extends Limit {

	/**
	 * 課程 id
	 *
	 * @var int
	 */
	public int $id;

	/**
	 * 課程名稱
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Constructor
	 *
	 * @param int         $course_id 課程 id
	 * @param string      $limit_type 限制類型 'unlimited' | 'fixed' | 'assigned' | 'follow_subscription'
	 * @param int|null    $limit_value 限制值
	 * @param string|null $limit_unit 限制單位 'timestamp' | 'day' | 'month' | 'year'
	 * @throws \Exception 如果 course_id 為空
	 */
	public function __construct( public int $course_id, string $limit_type, int|null $limit_value, string|null $limit_unit ) {
		parent::__construct( $limit_type, $limit_value, $limit_unit );

		if (!$course_id) {
			throw new \Exception('course_id is required');
		}

		$this->id   = $course_id;
		$this->name = \get_the_title($course_id);
	}

	/**
	 * 轉換為陣列
	 *
	 * @return array{
	 *     id: int,
	 *     name: string,
	 *     limit_type: string,
	 *     limit_value: int|null,
	 *     limit_unit: string|null,
	 * }
	 */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'name'        => $this->name,
			'limit_type'  => $this->limit_type,
			'limit_value' => $this->limit_value,
			'limit_unit'  => $this->limit_unit,
		];
	}
}
