<?php

declare ( strict_types=1 );

namespace J7\PowerCourse\Utils;

use J7\PowerCourse\Admin\Product as AdminProduct;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Resources\Course\MetaCRUD as AVLCourseMeta;
use J7\PowerCourse\Resources\Chapter\Model\Chapter;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;


/**
 * Class Course
 * TODO 移動到 Resources 底下
 */
abstract class Course {

	/**
	 * 檢查商品是否為外部課程
	 *
	 * 外部課程的判斷條件：
	 * 1. product type 為 external（WC_Product_External 實例）
	 * 2. _is_course meta 為 yes
	 *
	 * @param \WC_Product|int $product Product.
	 *
	 * @return bool
	 */
	public static function is_external_course( \WC_Product|int $product ): bool {
		if ( \is_numeric( $product ) ) {
			$product = \wc_get_product( $product );
			if ( ! $product ) {
				return false;
			}
		}

		// 必須同時是 external type 且有 _is_course = yes
		$is_external_type = $product->get_type() === 'external';
		$is_course        = \in_array( $product->get_meta( '_is_course' ), [ 'yes', 'on' ], true );

		return $is_external_type && $is_course;
	}

	/**
	 * 檢查商品是否為課程商品
	 *
	 * @param \WC_Product|int $product Product.
	 *
	 * @return bool
	 */
	public static function is_course_product( \WC_Product|int $product ): bool {
		if ( \is_numeric( $product ) ) {
			$product = \wc_get_product( $product );
			if (!$product) {
				return false;
			}
		}

		$option_value = $product->get_meta( '_' . AdminProduct::PRODUCT_OPTION_NAME );

		return \in_array( $option_value, [ 'yes', 'on' ], true);
	}

	/**
	 * 檢查課程開課了沒
	 *
	 * @param \WC_Product|int $product Product.
	 *
	 * @return bool
	 */
	public static function is_course_ready( \WC_Product|int $product ): bool {
		if ( is_numeric( $product ) ) {
			$product = \wc_get_product( $product );
		}

		if (!$product instanceof \WC_Product) {
			return false;
		}

		return $product->get_meta( 'course_schedule' ) < time();
	}

	/**
	 * 取得課程章節+單元 (flat)
	 *
	 * @param \WC_Product|int           $product 商品
	 * @param bool|null                 $return_ids 是否只回傳 id
	 * @param array<string>|null|string $post_status 文章狀態
	 *
	 * @return array<int|\WP_Post>
	 */
	public static function get_all_chapters( \WC_Product|int $product, ?bool $return_ids = false, $post_status = 'publish' ): array {
		if (!is_numeric($product)) {
			$product = $product->get_id();
		}

		$args = [
			'posts_per_page' => - 1,
			'order'          => 'ASC',
			'orderby'        => 'menu_order',
			'post_parent'    => $product,
			'post_status'    => $post_status,
			'post_type'      => ChapterCPT::POST_TYPE,
		];

		if ( $return_ids ) {
			$args['fields'] = 'ids';
		}

		$chapter_posts = \get_children( $args );

		$sub_chapter_posts = [];
		/** @var \WP_Post $chapter_post */
		foreach ( $chapter_posts as $chapter_post ) :
			$chapter_id = $return_ids ? $chapter_post : $chapter_post->ID;
			$sub_args   = [
				'posts_per_page' => - 1,
				'order'          => 'ASC',
				'orderby'        => 'menu_order',
				'post_parent'    => $chapter_id,
				'post_status'    => $post_status,
				'post_type'      => ChapterCPT::POST_TYPE,
			];

			if ( $return_ids ) {
				$sub_args['fields'] = 'ids';
			}

			$sub_chapter_posts = array_merge( $sub_chapter_posts, [ $chapter_id ], \get_children( $sub_args ) );
		endforeach;

		/** @var array<int|\WP_Post> $sub_chapter_posts */
		return $sub_chapter_posts;
	}

	/**
	 * 取得課程長度
	 *
	 * @param \WC_Product $product 商品
	 * @param string|null $type 類型 'second' | 'minute' | 'hour' | 'video_length'
	 *
	 * @return string
	 */
	public static function get_course_length( \WC_Product $product, ?string $type = 'second' ): string {
		$chapter_ids = ChapterUtils::get_flatten_post_ids( $product->get_id() );

		$length = 0;
		foreach ( $chapter_ids as $chapter_id ) {
			$chapter_length = (int) \get_post_meta( $chapter_id, 'chapter_length', true );
			$length        += $chapter_length;
		}

		if ( 'minute' === $type ) {
			return (string) floor( $length / 60 );
		}

		if ( 'hour' === $type ) {
			return (string) floor( $length / 3600 );
		}

		if ('video_length' === $type) {
			return Base::get_video_length_by_seconds( $length, '');
		}
		return (string) $length;
	}

	/**
	 * 取得課程進度
	 * ENHANCE 可以把能上的課程抽離成一個類
	 * 取得上課進度、完成的章節等資訊
	 *
	 * @param \WC_Product|int $product 課程商品
	 * @param int|null        $user_id 用户 ID
	 *
	 * @return float
	 */
	public static function get_course_progress( \WC_Product|int $product, ?int $user_id = 0 ): float {
		if (!$user_id) {
			$user_id = \get_current_user_id();
		}
		$product_id = $product instanceof \WC_Product ? $product->get_id() : $product;
		$product    = $product instanceof \WC_Product ? $product : \wc_get_product($product_id);

		if (!$product) {
			return 0;
		}

		$cache_key = "pid_{$product_id}_uid_{$user_id}";
		$progress  = \wp_cache_get( $cache_key, 'pc_course_progress' );
		if (false !== $progress) {
			return (float) $progress;
		}

		$sub_chapters_count          = count(ChapterUtils::get_flatten_post_ids($product->get_id()));
		$finished_sub_chapters_count = count(self::get_finished_sub_chapters($product_id, $user_id, true));

		$progress = $sub_chapters_count ? round(( $finished_sub_chapters_count / $sub_chapters_count * 100 ), 1) : 0;
		$progress = min( 100, $progress );

		\wp_cache_set( $cache_key, $progress, 'pc_course_progress' );

		return $progress;
	}

	/**
	 * 取得課程進度詳細資訊（含 last_chapter_id / last_position_seconds）
	 * 擴充版本，供前端 CTA 顯示「繼續觀看 / 重看」使用
	 *
	 * @param \WC_Product|int $product 課程商品
	 * @param int|null        $user_id 用戶 ID
	 * @return array{progress:float,last_chapter_id:int|null,last_position_seconds:int}
	 */
	public static function get_course_progress_info( \WC_Product|int $product, ?int $user_id = 0 ): array {
		if ( ! $user_id ) {
			$user_id = \get_current_user_id();
		}

		$product_id = $product instanceof \WC_Product ? $product->get_id() : $product;

		// 取得完成百分比（沿用既有方法）
		$progress = self::get_course_progress( $product, $user_id );

		// 從 last_visit_info 取得最後觀看章節 ID
		// 必須守門：只有當章節存在且狀態為 publish 時才採用，
		// 避免 trashed / draft / 已刪除章節導致 get_permalink() 回傳 ugly URL
		$last_visit_info = AVLCourseMeta::get( $product_id, $user_id, 'last_visit_info', true );
		$last_chapter_id = null;

		if ( is_array( $last_visit_info ) ) {
			$candidate_id = isset( $last_visit_info['chapter_id'] ) ? (int) $last_visit_info['chapter_id'] : null;
			if ( $candidate_id ) {
				$candidate_post = \get_post( $candidate_id );
				if ( $candidate_post instanceof \WP_Post && 'publish' === $candidate_post->post_status ) {
					$last_chapter_id = $candidate_id;
				}
			}
		}

		// 取得最後章節的播放秒數
		$last_position_seconds = 0;
		if ( $last_chapter_id ) {
			$record = \J7\PowerCourse\Resources\ChapterProgress\Service\Repository::find( (int) $user_id, $last_chapter_id );
			if ( $record ) {
				$last_position_seconds = $record->last_position_seconds;
			}
		}

		return [
			'progress'              => $progress,
			'last_chapter_id'       => $last_chapter_id,
			'last_position_seconds' => $last_position_seconds,
		];
	}

	/**
	 * 取得用戶已經上完的課程 ids
	 *
	 * @param int $user_id 用戶 id
	 * @return array<int|numeric-string> 課程 ids
	 */
	public static function get_finished_course_ids( int $user_id ): array {
		$avl_course_ids = \get_user_meta($user_id, 'avl_course_ids');
		$avl_course_ids = \is_array($avl_course_ids) ? $avl_course_ids : [];

		$avl_course_ids = array_filter($avl_course_ids, fn( $course_id ) => self::get_course_progress( (int) $course_id, $user_id) === (float) 100 );
		/** @var array<int|numeric-string> $avl_course_ids */
		return $avl_course_ids;
	}

	/**
	 * 取得已完成章節
	 *
	 * @param int       $course_id 課程 ID
	 * @param int|null  $user_id 用户 ID
	 * @param bool|null $return_ids 是否只回傳 id
	 *
	 * @return array<int|string|array<int|string, mixed>|\WP_Post|null>
	 */
	public static function get_finished_sub_chapters( int $course_id, ?int $user_id = 0, ?bool $return_ids = false ): array {
		if (!$user_id) {
			$user_id = \get_current_user_id();
		}

		$all_sub_chapter_ids      = ChapterUtils::get_flatten_post_ids($course_id);
		$finished_sub_chapter_ids = array_filter(
			$all_sub_chapter_ids,
			function ( $chapter_id ) use ( $user_id ) {
				$chapter = new Chapter( (int) $chapter_id, (int) $user_id);
				return (bool) $chapter->finished_at;
			}
			);

		if ($return_ids) {
			return $finished_sub_chapter_ids;
		}

		$chapter_posts = [];
		foreach ($finished_sub_chapter_ids as $chapter_id) {
			$chapter_posts[] = \get_post($chapter_id);
		}

		return $chapter_posts;
	}


	/**
	 * 查詢用戶可以上那些課程 ids
	 *
	 * @param int|null $user_id 用户 ID
	 * @param bool     $return_ids 是否只回傳 id
	 *
	 * @return array<\WC_Product|string>  課程 ids
	 */
	public static function get_avl_courses_by_user( ?int $user_id = null, ?bool $return_ids = false ): array {

		$user_id        = $user_id ?? get_current_user_id();
		$avl_course_ids = \get_user_meta($user_id, 'avl_course_ids');

		/**
		 * @var array<string> $avl_course_ids
		 */
		$avl_course_ids = \is_array($avl_course_ids) ? $avl_course_ids : [];
		if ($return_ids) {
			return $avl_course_ids;
		}

		$avl_courses = [];
		foreach ($avl_course_ids as $avl_course_id) {
			$course = \wc_get_product($avl_course_id);
			if ( (bool) $course) {
				$avl_courses[] = $course;
			}
		}

		return $avl_courses;
	}

	/**
	 * 從用戶訂單中取得用戶已購買的課程商品 \WC_Product[]
	 * 也會查找用戶買的 Bundle Products 裡面有沒有包含課程商品
	 * 如果你要取得用戶能上的課程，請使用 get_avl_courses_by_user
	 *
	 * @param array<string, mixed>|null $args 參數
	 *                         - numberposts int 每頁数量
	 *                         - offset int 跳過的数量
	 *                         - order string 排序
	 *                         - user_id int 用户 ID 查询
	 *
	 * @return array<\WC_Product>
	 */
	public static function get_courses_by_user_orders( ?array $args = [] ): array {
		$order_item_ids = self::get_course_order_item_ids_by_user( $args );

		$courses = [];

		foreach ( $order_item_ids as $order_item_id ) {
			$order_item = new \WC_Order_Item_Product( (int) $order_item_id );
			$product    = $order_item->get_product();
			if ( $product instanceof \WC_Product ) {
				$courses[] = $product;
			}
		}

		return $courses;
	}

	/**
	 * 取得用戶已購買的課程 order_item_id[]
	 * 如果你要取得用戶能上的課程，請使用 get_avl_courses_by_user
	 *
	 * @param array<string, mixed>|null $args 參數
	 *                         - numberposts int 每頁数量
	 *                         - offset int 跳過的数量
	 *                         - order string 排序
	 *                         - user_id int 用户 ID 查询
	 *                         - status string[]|string 訂單狀態，預設找已完成 'any' | 'wc-completed' | 'wc-processing' | 'wc-on-hold' | 'wc-pending' | 'wc-cancelled' | 'wc-refunded' | 'wc-failed'
	 *
	 * @return array<string> order_item_ids
	 */
	public static function get_course_order_item_ids_by_user( ?array $args = [] ): array {
		$defaults = [
			'numberposts' => 10,                                  // 每頁数量
			'offset'      => 0,                                   // 跳過的数量
			// 'orderby' => 'date', // TODO 排序字段
			'order'       => 'DESC',                              // 排序
			'user_id'     => \get_current_user_id(),               // 用户 ID 查询
			'status'      => [ 'wc-completed' ],                  // 訂單狀態
		];

		$args = \wp_parse_args( $args ?? [], $defaults );
		[
			'numberposts' => $numberposts,
			'offset'      => $offset,
			'order'       => $order,
			'user_id'     => $user_id,
			'status'      => $statuses,
		]     = $args;

		if ( \is_array( $statuses ) ) {
			$statuses_string  = implode(
				',',
				array_map(
					function ( $status ) {
						return '"' . (string) $status . '"';
					},
					$statuses
				)
			);
			$status_condition = sprintf(
				'AND posts.post_status IN ( %1$s )',
				$statuses_string
			);
		} else {
			$status_condition = ( $statuses === 'any' ) ? '' : sprintf(
				'AND posts.post_status = %1$s',
				(string) $statuses
			);
		}

		global $wpdb;
		$prepare = $wpdb->prepare(
			"
	WITH ranked_items AS (
        SELECT
            order_items.order_item_id,
            order_items.order_id,
            order_items.order_item_name,
            MAX(CASE WHEN product_id.meta_key = '%1\$s' THEN product_id.meta_value END) AS product_id,
            posts.post_date,
            ROW_NUMBER() OVER (PARTITION BY MAX(CASE WHEN product_id.meta_key = '%2\$s' THEN product_id.meta_value END) ORDER BY posts.post_date DESC, order_items.order_item_id) AS rn
        FROM {$wpdb->prefix}woocommerce_order_items AS order_items
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS qty_meta
            ON order_items.order_item_id = qty_meta.order_item_id AND qty_meta.meta_key = '%3\$s'
        LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS product_id
            ON order_items.order_item_id = product_id.order_item_id
        LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
        WHERE qty_meta.meta_value = '%4\$s'
        AND posts.post_author = %5\$d
        %6\$s
        GROUP BY order_items.order_item_id, order_items.order_id, order_items.order_item_name, posts.post_date
    )
    SELECT order_item_id, order_id, order_item_name, product_id, post_date
    FROM ranked_items
    WHERE rn = 1
    ORDER BY post_date %7\$s
    LIMIT %8\$d, %9\$d",
			'_product_id',                 // %1$s
			'_product_id',                 // %2$s
			'_is_course',                  // %3$s - meta_key
			'yes',                         // %4$s - meta_value
			$user_id,                      // %5$d - post_author
			$status_condition,             // %6$s - status condition
			$order,                        // %7$s - order
			$offset,                       // %8$d - offset
			$numberposts                   // %9$d - limit
		);

		return $wpdb->get_col( str_replace( '\"', '"', $prepare ) ); // phpcs:ignore
	}

	/**
	 * 取得用戶已購買的課程 order_item_product[]
	 *
	 * @hint 如果想知道當時購買的限制條件可以用這個
	 *
	 * @param array<string, mixed>|null $args 參數
	 *                         - numberposts int 每頁数量
	 *                         - offset int 跳過的数量
	 *                         - order string 排序
	 *                         - user_id int 用户 ID 查询
	 *                         - status string[]|string 訂單狀態，預設找已完成 'any' | 'wc-completed' | 'wc-processing' | 'wc-on-hold' | 'wc-pending' | 'wc-cancelled' | 'wc-refunded' | 'wc-failed'
	 *
	 * @return array<\WC_Order_Item_Product> order_items
	 */
	public static function get_course_order_items_by_user( ?array $args = [] ): array {
		$order_item_ids = self::get_course_order_item_ids_by_user( $args );

		$course_order_items = [];

		foreach ( $order_item_ids as $order_item_id ) {
			$order_item_product   = new \WC_Order_Item_Product( (int) $order_item_id );
			$course_order_items[] = $order_item_product;
		}

		return $course_order_items;
	}

	/**
	 * Checks if a course is available for a given product and user.
	 *
	 * @param int|null $product_id The product to check availability for.
	 * @param int|null $user_id The user ID to check availability for.
	 * @return bool Returns true if the course is available, false otherwise.
	 */
	public static function is_avl( ?int $product_id = 0, ?int $user_id = null ): bool {
		$user_id = $user_id ?? \get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if (!$product_id) {
			global $product, $course;
			$product = $course ?? $product;
			if (!( $product instanceof \WC_Product )) {
				return false;
			}
			$product_id = $product->get_id();
		}

		$avl_course_ids = self::get_avl_courses_by_user($user_id, true);

		return in_array( (string) $product_id, $avl_course_ids, true);
	}

	/**
	 * 檢查是否為管理員預覽模式
	 *
	 * @param int $product_id 商品 ID
	 * @return bool 如果為管理員預覽模式，返回 true；否則返回 false
	 */
	public static function is_admin_preview( int $product_id ): bool {
		return \current_user_can('manage_woocommerce') && !self::is_avl($product_id);
	}

	/**
	 * 檢查課程是否已過期。
	 *
	 * 根據產品ID和用戶ID，從AVLCourseMeta中獲取課程的過期日期，
	 * 然後判斷當前時間是否超過該過期日期。
	 *
	 * @param \WC_Product|null $the_product 產品實例，預設為null。
	 * @param int|null         $user_id 用戶ID，預設為null。
	 * @return bool 如果課程已過期，返回 true；否則返回 false 預設為 true
	 */
	public static function is_expired( ?\WC_Product $the_product = null, ?int $user_id = null ): bool {
		global $product, $course;
		$product        = $course ?? $product;
		$the_product    = $the_product ?? $product;
		$the_product_id = $the_product?->get_id();
		if (!$the_product_id) {
			return true;
		}
		$user_id     = $user_id ?? \get_current_user_id();
		$expire_date = AVLCourseMeta::get( (int) $the_product_id, $user_id, 'expire_date', true);

		// 如果 $expire_date 不是 subscription_ 開頭，就以 timestamp 判斷
		if (!str_starts_with( (string) $expire_date, 'subscription_')) {
			return empty($expire_date) ? false : $expire_date < time();
		}

		// subscription_ 開頭，當作 "跟隨訂閱" 處理
		$subscription_id = str_replace('subscription_', '', (string) $expire_date);
		$subscription    = \wcs_get_subscription($subscription_id);
		if (!$subscription) {
			return true;
		}
		// 已啟用或者待取消都還能看 = 還沒到期
		return !$subscription->has_status([ 'active', 'pending-cancel' ]);
	}

	/**
	 * 取得課程過期時的提示文字
	 *
	 * @param \WC_Product|null $the_product 產品實例，預設為null。
	 * @param int|null         $user_id 用戶ID，預設為null。
	 * @return string
	 */
	public static function get_expired_label( ?\WC_Product $the_product = null, ?int $user_id = null ): string {
		global $product, $course;
		$product = $course ?? $product;

		$the_product = $the_product ?? $product;
		$user_id     = $user_id ?? \get_current_user_id();
		$expire_date = AVLCourseMeta::get( $the_product->get_id(), get_current_user_id(), 'expire_date', true );
		$is_expired  = self::is_expired($the_product, $user_id);

		$follow_subscription = str_starts_with( (string) $expire_date, 'subscription_');

		if ($follow_subscription) {
			$subscription_id = str_replace('subscription_', '', (string) $expire_date);
			return $is_expired
			? sprintf(
					/* translators: %s: 訂閱編號 */
					esc_html__( 'Subscription #%s has expired', 'power-course' ),
					$subscription_id
				)
			: sprintf(
					/* translators: %s: 訂閱編號 */
					esc_html__( 'Follows subscription #%s', 'power-course' ),
					$subscription_id
				);
		}

		if ($is_expired) {
			return sprintf(
				/* translators: %s: 到期時間 */
				esc_html__( 'Your course access expired on %s', 'power-course' ),
				\wp_date( 'Y/m/d H:i', (int) $expire_date )
			);
		}

		if ( empty( $expire_date ) ) {
			return esc_html__( 'No expiration', 'power-course' );
		}

		return sprintf(
			/* translators: %s: 到期時間 */
			esc_html__( 'Until %s', 'power-course' ),
			\wp_date( 'Y/m/d H:i', (int) $expire_date )
		);
	}

	/**
	 * 獲取課程可用狀態。
	 *
	 * 根據產品和用戶ID判斷課程的可用狀態，返回狀態標籤和顏色。
	 *
	 * @param \WC_Product|null $the_product 產品實例，預設為當前產品。
	 * @param int|null         $user_id 用戶ID，預設為當前登入用戶。
	 * @return array{label:string,badge_color:string } 包含'label'和'badge_color'的狀態數組。
	 */
	public static function get_avl_status( ?\WC_Product $the_product = null, ?int $user_id = null ): array {
		global $product, $course;
		$product = $course ?? $product;

		$the_product = $the_product ?? $product;
		$user_id     = $user_id ?? \get_current_user_id();

		if ( ! self::is_avl($the_product->get_id(), $user_id) ) {
			return [
				'label'       => __( 'Not purchased', 'power-course' ),
				'badge_color' => 'ghost',
			];
		}

		if ( ! self::is_course_ready( $the_product ) ) {
			return [
				'label'       => __( 'Not started', 'power-course' ),
				'badge_color' => 'neutral',
			];
		}

		if ( self::is_expired( $the_product, $user_id ) ) {
			return [
				'label'       => __( 'Expired', 'power-course' ),
				'badge_color' => 'accent',
			];
		}

		return [
			'label'       => __( 'Available', 'power-course' ),
			'badge_color' => 'primary',
		];
	}

	/**
	 * 檢查用戶是否購買過指定商品
	 *
	 * @deprecated 使用 wc_customer_bought_product
	 *
	 * @param int|array<int>                                       $target_product_ids 目標商品 id
	 * @param array{'user_id':int, 'status':string[]|string }|null $args 參數
	 * - user_id int 使用者 ID，預設 current_user_id
	 * - status string[]|string 訂單狀態 'any' | 'wc-completed' | 'wc-processing' | 'wc-on-hold' | 'wc-pending' | 'wc-cancelled' | 'wc-refunded' | 'wc-failed' , 預設 [ 'wc-completed' ]
	 *
	 * @return bool
	 */
	public static function has_bought( int|array $target_product_ids, ?array $args = null ): bool {
		$defaults = [
			'user_id' => \get_current_user_id(),               // 用户 ID 查询
			'status'  => [ 'wc-completed' ],                  // 訂單狀態
		];

		$args = \wp_parse_args( $args ?? [], $defaults );
		[
			'user_id' => $user_id,
			'status'  => $statuses,
		]     = $args;

		// 構建 status 查詢條件
		if ( \is_array( $statuses ) ) {
			$statuses_string  = implode(
				',',
				array_map(
					function ( $status ) {
						return '"' . (string) $status . '"';
					},
					$statuses
				)
			);
			$status_condition = sprintf(
				'AND posts.post_status IN ( %1$s )',
				$statuses_string
			);
		} else {
			$status_condition = ( $statuses === 'any' ) ? '' : sprintf(
				'AND posts.post_status = %1$s',
				(string) $statuses
			);
		}
		// 構建 target_product_ids 查詢條件
		if ( \is_array( $target_product_ids ) ) {
			$target_product_ids_string = implode(
				',',
				array_map(
					function ( $target_product_id ) {
						return '"' . $target_product_id . '"';
					},
					$target_product_ids
				)
			);
		} else {
			$target_product_ids_string = $target_product_ids;
		}

		global $wpdb;
		// phpcs:disable
		$prepare = $wpdb->prepare(
			"
			WITH ranked_items AS (
						SELECT
								order_items.order_item_id,
								order_items.order_id,
								order_items.order_item_name,
								MAX(CASE WHEN product_id.meta_key = '%1\$s' THEN product_id.meta_value END) AS product_id,
								posts.post_date,
								ROW_NUMBER() OVER (PARTITION BY MAX(CASE WHEN product_id.meta_key = '%2\$s' THEN product_id.meta_value END) ORDER BY posts.post_date DESC, order_items.order_item_id) AS rn
						FROM {$wpdb->prefix}woocommerce_order_items AS order_items
						LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS qty_meta
								ON order_items.order_item_id = qty_meta.order_item_id AND qty_meta.meta_key = '%3\$s'
						LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS product_id
								ON order_items.order_item_id = product_id.order_item_id
						LEFT JOIN {$wpdb->posts} AS posts ON order_items.order_id = posts.ID
						WHERE qty_meta.meta_value = '%4\$s'
						AND posts.post_author = %5\$d
						%6\$s
						GROUP BY order_items.order_item_id, order_items.order_id, order_items.order_item_name, posts.post_date
				)
				SELECT order_item_id, order_id, order_item_name, product_id, post_date
				FROM ranked_items
				WHERE rn = 1
				AND product_id IN ( %7\$s )",
					'_product_id',                       // %1$s
					'_product_id',                       // %2$s
					'_is_course',                        // %3$s - meta_key
					'yes',                               // %4$s - meta_value
					$user_id,                            // %5$d - post_author
					$status_condition,                   // %6$s - status condition
					$target_product_ids_string           // %7$s - target_product_ids_condition
		);

		$results = $wpdb->get_results( str_replace( '\"', '"', $prepare ) );

		// phpcs:enable

		return (bool) $results;
	}

	/**
	 * 取得最暢銷的課程
	 *
	 * @param int $limit 限制數量
	 *
	 * @return array{id:string, name:string, total_sales: float}[]
	 */
	public static function get_top_sales_courses( int $limit = 10 ): array {
		global $wpdb;
		// 执行查询
		$top_selling_products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, CAST(pm.meta_value AS UNSIGNED) AS total_sales FROM  %1\$s pm JOIN  %1\$s pm2 ON pm.post_id = pm2.post_id WHERE pm.meta_key = 'total_sales' AND pm2.meta_key = '_is_course' AND pm2.meta_value = 'yes' ORDER BY total_sales DESC LIMIT %2\$d ",
		$wpdb->postmeta,
				$limit
			)
		);

		$formatted_top_selling_products = array_map(
			function ( $product ) {
				$product_id   = $product->post_id;
				$product_name = \get_the_title($product_id);
				$total_sales  = $product->total_sales;

				return [
					'id'          => (string) $product_id,
					'name'        => $product_name,
					'total_sales' => (float) $total_sales,
				];
			},
			$top_selling_products
		);

		return $formatted_top_selling_products;
	}

	/**
	 * 取得課程的永久連結結構
	 *
	 * @return string
	 */
	public static function get_course_permalink_structure(): string {
		$permalinks = \wp_unslash(
			\get_option(
			'woocommerce_permalinks',
			[
				'product_base' => 'product',
			]
			)
		);
		$course_permalink_structure = is_array($permalinks) ? ( $permalinks['product_base'] ?? 'product' ) : 'product';
		return (string) preg_replace('/^\//', '', (string) $course_permalink_structure);
	}

	/**
	 * 取得課程的永久連結結構
	 *
	 * 如果沒有找到，則返回 false
	 *
	 * @param int    $course_id 課程 ID
	 * @param string $status 文章狀態
	 *
	 * @return string|false
	 */
	public static function get_classroom_permalink( int $course_id, string $status = 'publish' ): string|false {
		/** @var array<int> $top_chapter_ids 只拿一個 */
		$top_chapter_ids = \get_posts(
			[
				'post_type'      => ChapterCPT::POST_TYPE,
				'meta_key'       => 'parent_course_id',
				'meta_value'     => (string) $course_id,
				'post_status'    => $status,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
					'date'       => 'ASC',
				],
			]
			);

		if (empty($top_chapter_ids)) {
			return false;
		}

		$top_chapter_id = reset($top_chapter_ids);

		return \get_permalink($top_chapter_id);
	}


	/**
	 * 取得用戶最後訪問的課程連結
	 *
	 * @param int $course_id 課程 ID
	 * @param int $current_user_id 用戶 ID
	 *
	 * @return string
	 */
	public static function get_last_visit_classroom_link( int $course_id, int $current_user_id = null ): string {
		$current_user_id = $current_user_id ?? \get_current_user_id();
		$last_visit_info = AVLCourseMeta::get( $course_id, $current_user_id, 'last_visit_info', true );

		if ( is_array($last_visit_info) ) {
			$last_chapter_id = $last_visit_info['chapter_id'] ?? null;
			$last_chapter    = \get_post( (int) $last_chapter_id );
			if ($last_chapter instanceof \WP_Post && 'publish' === $last_chapter->post_status) {
				$last_classroom_link = \get_permalink( (int) $last_chapter_id);
				if (is_string($last_classroom_link)) {
					return $last_classroom_link;
				}
			}
		}

		$chapter_ids = ChapterUtils::get_flatten_post_ids($course_id);

		$first_chapter_id = count($chapter_ids) > 0 ? reset($chapter_ids) : null;

		if ($first_chapter_id) {
			$first_classroom_link = \get_permalink($first_chapter_id);
			if (is_string($first_classroom_link) && $first_classroom_link !== '') {
				return $first_classroom_link;
			}
		}

		return \site_url( '404' );
	}
}
