<?php

declare(strict_types=1);

namespace J7\PowerCourse\Domain\Product\Events;

use J7\PowerCourse\Domain\Product\Helper\IsCourse;

/**
 * Edit event handler for product.
 */
class Edit {




	/** Constructor */
	public function __construct() {
		// 批量編輯功能
		\add_action( 'bulk_edit_custom_box', [ $this, 'add_bulk_edit_fields' ], 10, 2 );
		\add_action( 'save_post_product', [ $this, 'save_bulk_edit_fields' ], 10, 1 );
	}

	/**
	 * 新增批量編輯欄位
	 * Add fields to bulk edit form
	 *
	 * @param string $column_name - Column name
	 * @param string $post_type - Post type
	 * @return void
	 */
	public function add_bulk_edit_fields( $column_name, $post_type ): void {
		// 只在 product post type 且指定欄位顯示
		if ( 'product' !== $post_type || 'thumb' !== $column_name) {
			return;
		}
		?>
		<fieldset class="inline-edit-col-right">
			<div class="inline-edit-group wp-clearfix">
				<label class="alignleft">
					<span class="title"><?php \esc_html_e( 'Is course product', 'power_course' ); ?></span>
						<select name="<?php echo IsCourse::META_KEY; ?>">
							<option value=""><?php \esc_html_e( '— No change —', 'power_course' ); ?></option>
							<option value="yes"><?php \esc_html_e( 'Yes', 'power_course' ); ?></option>
							<option value="no"><?php \esc_html_e( 'No', 'power_course' ); ?></option>
						</select>
				</label>
			</div>
		</fieldset>
		<?php
	}


	/**
	 * 儲存批量編輯欄位
	 * Save bulk edit fields
	 *
	 * @param int $post_id - Post ID
	 * @return void
	 */
	public function save_bulk_edit_fields( $post_id ): void {
		// 檢查是否為批量編輯
		if ( ! isset( $_REQUEST['bulk_edit'] ) ) { // phpcs:ignore
			return;
		}

		// 檢查是否為產品
		if ( 'product' !== \get_post_type( $post_id ) ) {
			return;
		}

		// 檢查權限
		if ( ! \current_user_can( 'edit_product', $post_id ) ) { //phpcs:ignore
			return;
		}

		// 如果選擇了 "No change"，則不處理
		if (!isset($_REQUEST[ IsCourse::META_KEY ])) { //phpcs:ignore
			return;
		}

		if (empty($_REQUEST[ IsCourse::META_KEY ] )) {
			return;
		}

		$is_course = \wc_string_to_bool( (string) $_REQUEST[ IsCourse::META_KEY ] ); //phpcs:ignore

		\update_post_meta( $post_id, IsCourse::META_KEY, \wc_bool_to_string( $is_course) );
	}
}
