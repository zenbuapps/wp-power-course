<?php
/**
 * AccessPass Model（Issue #252）
 *
 * 課程權限包 CPT（pc_access_pass）的 DTO，封裝 wp_posts + wp_postmeta 的範圍 / 期限 / 狀態。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Model;

use J7\PowerCourse\Resources\AccessPass\Core\CPT;

/**
 * Class AccessPass
 * CPT-backed DTO：以 instance($id) 載入權限包的 CPT 與 postmeta。
 *
 * 範圍 / 期限 / 狀態 meta key（對照 erm.dbml access_passes）：
 *   - scope_type：all | category | specific
 *   - limit_mode：permanent | follow_subscription | limited
 *   - limit_value / limit_unit：限時模式（limit_mode=limited）的數值與單位
 *   - access_pass_status：active | disabled
 *   - scope_term_ids：多列 postmeta（category 範圍的 product_cat / product_tag 聯集）
 *   - scope_course_ids：多列 postmeta（specific 範圍的固定課程清單）
 */
final class AccessPass {

	/**
	 * Constructor
	 *
	 * @param int           $id          權限包 post ID
	 * @param string        $name        權限包名稱（wp_posts.post_title）
	 * @param string        $scope_type  範圍：all | category | specific
	 * @param string        $limit_mode  期限模式：permanent | follow_subscription | limited
	 * @param int|null      $limit_value 限時模式數值
	 * @param string|null   $limit_unit  限時模式單位：day | month | year
	 * @param string        $status      狀態：active | disabled
	 * @param array<int>    $term_ids    category 範圍的 term id 清單
	 * @param array<int>    $course_ids  specific 範圍的 course id 清單
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $scope_type,
		public readonly string $limit_mode,
		public readonly ?int $limit_value,
		public readonly ?string $limit_unit,
		public readonly string $status,
		public readonly array $term_ids,
		public readonly array $course_ids
	) {}

	/**
	 * 以權限包 post ID 載入 Model 實例
	 *
	 * @param int $id 權限包 post ID
	 * @return self|null 找不到（或非 pc_access_pass）時回傳 null
	 */
	public static function instance( int $id ): ?self {
		$post = \get_post( $id );
		if ( ! $post instanceof \WP_Post || CPT::POST_TYPE !== $post->post_type ) {
			return null;
		}

		$limit_value_meta = \get_post_meta( $id, 'limit_value', true );
		$limit_unit_meta  = \get_post_meta( $id, 'limit_unit', true );

		return new self(
			id: $id,
			name: (string) $post->post_title,
			scope_type: (string) \get_post_meta( $id, 'scope_type', true ),
			limit_mode: (string) ( \get_post_meta( $id, 'limit_mode', true ) ?: 'permanent' ),
			limit_value: ( '' === $limit_value_meta || null === $limit_value_meta ) ? null : (int) $limit_value_meta,
			limit_unit: ( '' === $limit_unit_meta || null === $limit_unit_meta ) ? null : (string) $limit_unit_meta,
			status: (string) ( \get_post_meta( $id, 'access_pass_status', true ) ?: 'active' ),
			term_ids: self::get_int_meta_list( $id, 'scope_term_ids' ),
			course_ids: self::get_int_meta_list( $id, 'scope_course_ids' )
		);
	}

	/**
	 * 讀取多列 postmeta 並轉為 int 陣列
	 *
	 * @param int    $id       權限包 post ID
	 * @param string $meta_key meta key（scope_term_ids | scope_course_ids）
	 * @return array<int>
	 */
	private static function get_int_meta_list( int $id, string $meta_key ): array {
		$values = \get_post_meta( $id, $meta_key, false );
		if ( ! \is_array( $values ) ) {
			return [];
		}
		return \array_values( \array_map( 'intval', $values ) );
	}

	/**
	 * 轉為陣列
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'id'          => $this->id,
			'name'        => $this->name,
			'scope_type'  => $this->scope_type,
			'limit_mode'  => $this->limit_mode,
			'limit_value' => $this->limit_value,
			'limit_unit'  => $this->limit_unit,
			'status'      => $this->status,
			'term_ids'    => $this->term_ids,
			'course_ids'  => $this->course_ids,
		];
	}
}
