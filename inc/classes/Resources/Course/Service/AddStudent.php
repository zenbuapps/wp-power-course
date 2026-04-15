<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Resources\Course\Service;

use J7\PowerCourse\Resources\Course\LifeCycle;

/**
 * AddStudent 新增學員到課程
 * 為什麼需要這個類
 * 因為在處理單一課程 & 課程權限綁定時
 * 會發生新增學員到課程 2 次 的問題
 * 使用此類來避免這個問題
 * */
final class AddStudent {

	/** @var array<object{customer_id: int, course_id: int, expire_date: int|string, order: \WC_Order|null}> 學員以及課程資料 */
	private array $items = [];


	/**
	 * 新增學員到課程
	 * 後面新增的 item 會蓋掉前面的 item
	 *
	 * @param int            $customer_id 學員 ID
	 * @param int            $course_id 課程 ID
	 * @param int|string     $expire_date 到期日
	 * @param \WC_Order|null $order 訂單
	 * @return void
	 */
	public function add_item( int $customer_id, int $course_id, int|string $expire_date, \WC_Order|null $order ): void {
		// 檢查原本的 $items 跟要添加的 課程 ID 與 學員 ID 是否相同
		// 如果相同，則不添加
		// 如果不同，則添加
		$filtered_items = [];
		foreach ($this->items as $item) {
			if ($item->course_id === $course_id && $item->customer_id === $customer_id) {
				continue;
			}
			$filtered_items[] = $item;
		}
		// 如果沒有相同，則添加
		$filtered_items[] = (object) [
			'customer_id' => $customer_id,
			'course_id'   => $course_id,
			'expire_date' => $expire_date,
			'order'       => $order,
		];

		$this->items = $filtered_items;
	}

	/** @return void 執行新增學員到課程 */
	public function do_action() {
		foreach ($this->items as $item) {
			\do_action( LifeCycle::ADD_STUDENT_TO_COURSE_ACTION, $item->customer_id, $item->course_id, $item->expire_date, $item->order );
		}
	}
}
