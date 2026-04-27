<?php
/**
 * Teacher Query Service — 講師查詢服務
 *
 * 供 REST callback 與 MCP tool 共用的講師讀取邏輯。
 * 講師判定方式：WP user meta `is_teacher = 'yes'`。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Teacher\Service;

/**
 * Class Query
 * 講師讀取（list / get）相關服務
 */
final class Query {

	/**
	 * 列出所有講師（WP users with meta is_teacher = yes）
	 *
	 * @param array<string, mixed> $args 查詢參數
	 *                                   可包含：
	 *                                   - paged: int 頁碼（從 1 開始）
	 *                                   - number: int 每頁筆數，最大 100
	 *                                   - search: string 搜尋關鍵字（比對 user_login / user_email / display_name）
	 *                                   - orderby: string 排序欄位（預設 ID）
	 *                                   - order: 'ASC'|'DESC'
	 * @return array{items: array<int, array<string, mixed>>, total: int, total_pages: int}
	 *         回傳格式化後的講師資料與分頁資訊
	 */
	public static function list( array $args = [] ): array {
		$paged  = isset( $args['paged'] ) ? max( 1, (int) $args['paged'] ) : 1;
		$number = isset( $args['number'] ) ? min( 100, max( 1, (int) $args['number'] ) ) : 10;

		$query_args = [
			'meta_key'   => 'is_teacher',
			'meta_value' => 'yes',
			'number'     => $number,
			'paged'      => $paged,
			'offset'     => ( $paged - 1 ) * $number,
			'orderby'    => isset( $args['orderby'] ) && is_string( $args['orderby'] )
				? \sanitize_key( $args['orderby'] )
				: 'ID',
			'order'      => isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] )
				? 'ASC'
				: 'DESC',
			'count_total' => true,
		];

		if ( isset( $args['search'] ) && is_string( $args['search'] ) && '' !== $args['search'] ) {
			$search                    = \sanitize_text_field( $args['search'] );
			$query_args['search']         = '*' . $search . '*';
			$query_args['search_columns'] = [ 'user_login', 'user_email', 'user_nicename', 'display_name' ];
		}

		$user_query = new \WP_User_Query( $query_args );
		/** @var array<\WP_User> $users */
		$users       = $user_query->get_results();
		$total       = (int) $user_query->get_total();
		$total_pages = (int) ceil( $total / $number );

		$items = array_values(
			array_map(
				/**
				 * @param \WP_User $user
				 * @return array<string, mixed>
				 */
				static fn( \WP_User $user ): array => self::format_teacher( $user ),
				$users
			)
		);

		return [
			'items'       => $items,
			'total'       => $total,
			'total_pages' => $total_pages,
		];
	}

	/**
	 * 取得單一講師詳情（含授課清單）
	 *
	 * @param int $user_id 講師的 WP user ID
	 * @return array<string, mixed>|\WP_Error 找不到或非講師時回傳 WP_Error
	 */
	public static function get( int $user_id ): array|\WP_Error {
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'teacher_invalid_id',
				\__( 'user_id 為必填且需為正整數', 'power-course' ),
				[ 'status' => 400 ]
			);
		}

		$user = \get_user_by( 'id', $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error(
				'teacher_not_found',
				\__( '找不到指定的使用者', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'yes' !== \get_user_meta( $user_id, 'is_teacher', true ) ) {
			return new \WP_Error(
				'teacher_not_a_teacher',
				\__( '指定的使用者不是講師', 'power-course' ),
				[ 'status' => 404 ]
			);
		}

		$data                   = self::format_teacher( $user );
		$data['authored_courses'] = self::get_authored_courses( $user_id );

		return $data;
	}

	/**
	 * 取得某講師授課的課程清單（以 post_meta teacher_ids 反查）
	 *
	 * @param int $user_id 講師 user ID
	 * @return array<int, array<string, mixed>> 課程簡要資訊陣列
	 */
	public static function get_authored_courses( int $user_id ): array {
		$query = new \WP_Query(
			[
				'post_type'      => 'product',
				'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => [
					[
						'key'     => '_is_course',
						'value'   => 'yes',
						'compare' => '=',
					],
					[
						'key'     => 'teacher_ids',
						'value'   => (string) $user_id,
						'compare' => '=',
					],
				],
			]
		);

		/** @var array<int> $course_ids */
		$course_ids = $query->posts;

		$items = [];
		foreach ( $course_ids as $course_id ) {
			$post = \get_post( (int) $course_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$items[] = [
				'id'     => (int) $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
			];
		}

		return $items;
	}

	/**
	 * 將 WP_User 格式化為講師回傳資料
	 *
	 * @param \WP_User $user 使用者物件
	 * @return array<string, mixed>
	 */
	private static function format_teacher( \WP_User $user ): array {
		return [
			'id'           => (int) $user->ID,
			'user_login'   => (string) $user->user_login,
			'user_email'   => (string) $user->user_email,
			'display_name' => (string) $user->display_name,
			'is_teacher'   => 'yes' === \get_user_meta( (int) $user->ID, 'is_teacher', true ),
			'roles'        => array_values( (array) $user->roles ),
		];
	}
}
