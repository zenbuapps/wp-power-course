<?php
/**
 * 商品如果綁課程權限
 * 相關資料都存在 bind_courses_data 這個 post meta 中
 */

declare ( strict_types=1 );

namespace J7\PowerCourse\Resources\Course;

/**
 * Class BindCoursesData
 */
final class BindCoursesData {

	/**
	 * 不一定會有商品 id 因為有時候是從訂單身上拿
	 *
	 * @var int|null $product_id 商品 id
	 */
	public int|null $product_id = null;

	/**
	 * @var BindCourseData[] $bind_courses_data 綁定的課程資料
	 */
	private array $bind_courses_data = [];


	/**
	 * Constructor
	 * 從有綁課程權限的商品身上拿 bind_courses_data 資料
	 *
	 * @param array<int, array{id: int, name: string, limit_type: string, limit_value: int|null, limit_unit: string|null}> $bind_courses_data 綁定的課程資料，存在 DB 的 array 資料
	 * @param int|null                                                                                                     $product_id 商品 id
	 */
	public function __construct( array $bind_courses_data, ?int $product_id = null ) {
		if ($product_id) {
			$this->product_id = $product_id;
		}

		foreach ($bind_courses_data as $bind_course_data) {
			$this->bind_courses_data[] = new BindCourseData( (int) $bind_course_data['id'], $bind_course_data['limit_type'], (int) $bind_course_data['limit_value'], $bind_course_data['limit_unit'] );
		}
	}


	/**
	 * Constructor
	 * 從有綁課程權限的商品身上拿 bind_courses_data 資料
	 *
	 * @param int $product_id 商品 id
	 * @return self
	 */
	public static function instance( int $product_id ): self {
		/**
		 * @var array<int, array{
		 *     id: int,
		 *     name: string,
		 *     limit_type: string,
		 *     limit_value: int|null,
		 *     limit_unit: string|null,
		 * }> $bind_courses_data
		 */
		$bind_courses_data = \get_post_meta( $product_id, 'bind_courses_data', true ) ?: [];
		return new self( (array) $bind_courses_data, $product_id );
	}

	/**
	 * 取得綁定的課程 ids
	 *
	 * @return array<string>
	 */
	public function get_course_ids(): array {
		return \wp_list_pluck( $this->get_data(), 'course_id' );
	}

	/**
	 * 檢查課程是否已經綁定
	 *
	 * @param int $course_id 課程 id
	 * @return bool
	 */
	public function included( int $course_id ): bool {
		return \in_array( $course_id, $this->get_course_ids() );
	}

	/**
	 * 新增課程資料
	 * 如果原本的資料裡面有這次新增的，那就跳過不動
	 *
	 * @param int   $course_id 課程 id
	 * @param Limit $limit 限制
	 * @return self
	 */
	public function add_course_data( int $course_id, Limit $limit ): self {
		if ($this->included( $course_id )) {
			// 如果原本的資料裡面有這次新增的，那就跳過不動
			return $this;
		}
		// 原本的資料沒有這次新增的，那就新增
		$this->bind_courses_data[] = new BindCourseData( $course_id, $limit->limit_type, $limit->limit_value, $limit->limit_unit );

		return $this;
	}

	/**
	 * 更新課程資料
	 *
	 * @param int   $course_id 課程 id
	 * @param Limit $limit 限制
	 * @return self
	 */
	public function update_course_data( int $course_id, Limit $limit ): self {
		$this->remove_course_data( $course_id );
		$this->bind_courses_data[] = new BindCourseData( $course_id, $limit->limit_type, $limit->limit_value, $limit->limit_unit );

		return $this;
	}

	/**
	 * 移除課程資料
	 *
	 * @param int $course_id 課程 id
	 * @return self
	 */
	public function remove_course_data( int $course_id ): self {
		/** @var BindCourseData[] $bind_courses_data */
		$bind_courses_data       = $this->get_data();
		$this->bind_courses_data = array_filter( $bind_courses_data, fn( $bind_course_data ) => $bind_course_data->course_id !== $course_id );
		return $this;
	}

	/**
	 * 取得綁定的課程資料
	 *
	 * @param string|null $output 輸出格式 OBJECT | ARRAY_N
	 * @return BindCourseData[]|array<int, array{
	 *     id: int,
	 *     name: string,
	 *     limit_type: string,
	 *     limit_value: int|null,
	 *     limit_unit: string|null,
	 * }>
	 */
	public function get_data( ?string $output = OBJECT ): array {
		if ($output === ARRAY_N) {
			return \array_values( \array_map( fn( $bind_course_data ) => $bind_course_data->to_array(), $this->bind_courses_data ) );
		}
		return $this->bind_courses_data;
	}

	/**
	 * 儲存
	 * TODO 改成 $product 操作方法!?
	 */
	public function save(): void {
		if (!$this->product_id) {
			return;
		}
		$bind_courses_data = $this->get_data( ARRAY_N );

		\update_post_meta( $this->product_id, 'bind_courses_data', $bind_courses_data );
	}
}
