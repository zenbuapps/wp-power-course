<?php

declare(strict_types=1);

namespace J7\PowerCourse\Resources\Chapter\Utils;

use J7\WpUtils\Classes\WP;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\WpUtils\Classes\General;
use J7\PowerCourse\Resources\Chapter\Core\CPT;
use J7\PowerCourse\Resources\Chapter\Model\Chapter;
use J7\PowerCourse\Plugin;
use J7\Powerhouse\Domains\Post\Utils as PostUtils;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\Utils\LinearViewing;

/**
 * Chapter Utils
 */
abstract class Utils {

	const TEMPLATE = '';

	/**
	 * Create a new chapter
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_insert_post/
	 *
	 * 簡單的新增，沒有太多參數，所以不使用 Converter
	 *
	 * @param array<string, mixed> $args Arguments.
	 *
	 * @return int|\WP_Error
	 */
	public static function create_chapter( array $args = [] ): int|\WP_Error {
		$args['post_title']           = $args['post_title'] ?? esc_html__( 'New chapter', 'power-course' );
		$args['post_status']          = 'publish';
		$args['post_author']          = \get_current_user_id();
		$args['post_type']            = CPT::POST_TYPE;
		$args['page_template']        = self::TEMPLATE;
		$args['meta_input']           = $args['meta_input'] ?? [];
		/** @var array<string, mixed> $meta_input */
		$meta_input                   = $args['meta_input'];
		$meta_input['editor']         = $meta_input['editor'] ?? 'power-editor';
		$args['meta_input']           = $meta_input;
		$args['menu_order']           = $args['menu_order'] ?? PHP_INT_MAX; // 預設排在最底下

		/** @var array{post_title: string, post_status: string, post_author: int, post_type: string, page_template: string, meta_input: array<string, mixed>, menu_order: int} $args */
		return \wp_insert_post($args);
	}

	/**
	 * Format Chapter details
	 * WP_Post 轉 array
	 *
	 * @param \WP_Post $post             Chapter.
	 * @param bool     $with_description With description.
	 * @param int      $depth            Depth.
	 *
	 * @return array<string, mixed>
	 */
	public static function format_chapter_details(
		\WP_Post $post,
		?bool $with_description = true,
		?int $depth = 0
	) {
		$date_created  = $post->post_date;
		$date_modified = $post->post_modified;

		$image_id  = \get_post_thumbnail_id($post->ID);
		$image_ids = [ (int) $image_id ];
		$images    = array_map([ WP::class, 'get_image_info' ], $image_ids);

		$description_array = $with_description ? [
			'description'       => $post->post_content,
			'short_description' => $post->post_excerpt,
		] : [];

		/** @var array<int, \WP_Post> $chapters_posts */
		$chapters_posts = array_values(
			\get_children(
				[
					'post_parent' => $post->ID,
					'post_type'   => CPT::POST_TYPE,
					'numberposts' => -1,
					'post_status' => 'any',
					'orderby'     => [
						'menu_order' => 'ASC',
						'ID'         => 'ASC',
						'date'       => 'ASC',
					],
				]
			)
		);
		/** @var \WP_Post[] $chapters_posts */
		$chapters = array_map(
			static function ( \WP_Post $ch, bool $with_desc, int $d ): array {
				return self::format_chapter_details($ch, $with_desc, $d);
			},
			$chapters_posts,
			array_fill(0, count($chapters_posts), false),
			array_fill(0, count($chapters_posts), $depth + 1)
		);

		$children = $chapters ? [
			'chapters' => $chapters,
		] : [];

		$base_array = [
			// Get Product General Info
			'id'                 => (string) $post->ID,
			'type'               => 'chapter',
			'depth'              => $depth,
			'name'               => $post->post_title,
			'slug'               => $post->post_name,
			'date_created'       => $date_created,
			'date_modified'      => $date_modified,
			'status'             => $post->post_status,
			// 'featured'           => false,
			'catalog_visibility' => '',
			// 'sku'                => '',
			'menu_order'         => (int) $post->menu_order,
			// 'virtual'            => false,
			// 'downloadable'       => false,
			'permalink'          => \get_permalink($post->ID),
			'chapter_length'     => (int) \get_post_meta($post->ID, 'chapter_length', true),
			'description'        => $post->post_content,

			// Get Product Prices
			// 'price_html'         => '',
			// 'regular_price'      => '',
			// 'sale_price'         => '',
			// 'on_sale'            => '',
			// 'date_on_sale_from'  => '',
			// 'date_on_sale_to'    => '',
			// 'total_sales'        => '',

			// Get Product Stock
			// 'stock'              => '',
			// 'stock_status'       => '',
			// 'manage_stock'       => '',
			// 'stock_quantity'     => '',
			// 'backorders'         => '',
			// 'backorders_allowed' => '',
			// 'backordered'        => '',
			// 'low_stock_amount'   => '',

			// Get Linked Products
			// 'upsell_ids'         => array(),
			// 'cross_sell_ids'     => array(),

			// Get Product Variations and Attributes
			// 'attributes'         => array(),

			// Get Product Taxonomies
			'category_ids'       => [],
			'tag_ids'            => [],

			// Get Product Images
			'images'             => $images,

			'parent_id'          => (string) $post->post_parent,
			'chapter_video'      => \get_post_meta($post->ID, 'chapter_video', true),
			'enable_comment'     => \get_post_meta($post->ID, 'enable_comment', true) ?: 'no',
			'editor'             => \get_post_meta($post->ID, 'editor', true) ?: 'power-editor',
			'chapter_subtitles'  => \get_post_meta($post->ID, 'pc_subtitles_chapter_video', true) ?: [],
		] + $children;

		return array_merge(
			$description_array,
			$base_array
		);
	}

	/**
	 * Sort chapters
	 * 改變章節順序
	 *
	 * @param array{
	 * from_tree: array{
	 *  id: string,
	 *  depth: string,
	 *  menu_order: string,
	 *  name: string,
	 *  slug: string,
	 *  parent_id: string,
	 * }[],
	 * to_tree: array{
	 *  id: string,
	 *  depth: string,
	 *  menu_order: string,
	 *  name: string,
	 *  slug: string,
	 *  parent_id: string,
	 * }[],
	 * } $params Parameters.
	 *
	 * @return true|\WP_Error
	 * @throws \Exception 更新錯誤
	 */
	public static function sort_chapters( array $params ): bool|\WP_Error {
		$from_tree = $params['from_tree'];
		$to_tree   = $params['to_tree'];

		$delete_ids = [];
		foreach ($from_tree as $from_node) {
			$id      = $from_node['id'];
			$to_node = array_filter($to_tree, fn ( $node ) => $node['id'] === $id);
			if (empty($to_node)) {
				// 找出那些在 $to_node 裡面不存在的 id，要刪除這些 post
				$delete_ids[] = $id;
			}
		}

		$new_to_tree = [];

		// 重新整理 to_tree 的資料
		foreach ($to_tree as $node) {
			$new_node = [
				'id'               => $node['id'],
				// 為了做更細粒度的排序，但 menu_order WP 強制轉 int，所以需要 * 10
				'menu_order'       => ( (int) $node['menu_order'] ) * 10,
				// 如果 depth 是 0，代表是頂層，不使用 post_parent ，而是用 meta_key parent_course_id
				// post_parent 要清空
				'parent_id'        => $node['depth'] ? $node['parent_id'] : 0,
				'parent_course_id' => $node['depth'] ? 0 : $node['parent_id'],
			];

			$new_to_tree[] = $new_node;
		}

		// 使用 wpdb 一次更新
		global $wpdb;

		// 批量更新的大小
		$batch_size = 50; // 每次處理50筆資料

		// 分批處理資料
		$batches = array_chunk($new_to_tree, $batch_size);

		// 開始事務處理
		$wpdb->query('START TRANSACTION');

		try {
			// 準備兩個不同的更新集合：一個用於只更新 menu_order，另一個用於同時更新 menu_order 和 post_parent
			foreach ($batches as $batch) {
				// 構建 CASE WHEN 語句
				$ids                    = [];
				$menu_order_cases       = [];
				$parent_cases           = [];
				$parent_course_id_cases = [];

				foreach ($batch as $item) {
					$id               = intval($item['id']);
					$ids[]            = $id;
					$menu_order       = intval($item['menu_order']);
					$parent_id        = $item['parent_id'];
					$parent_course_id = $item['parent_course_id'];

					// 為每個ID準備menu_order的CASE語句
					$menu_order_cases[] = $wpdb->prepare('WHEN ID = %d THEN %d', $id, $menu_order);

					// 則準備post_parent的CASE語句
					$parent_cases[] = $wpdb->prepare('WHEN ID = %d THEN %d', $id, $parent_id);

					// 如果 parent_course_id 不為 0，則準備 parent_course_id 的 CASE 語句
					$parent_course_id_cases[] = $wpdb->prepare('WHEN ID = %d THEN %d', $id, $parent_course_id);
				}

				// 構建ID列表
				$id_list = implode(',', $ids);

				// 構建批量更新SQL
				$sql  = "UPDATE {$wpdb->posts} SET menu_order = CASE ";
				$sql .= implode(' ', $menu_order_cases);
				$sql .= ' ELSE menu_order END ';

				// 加入post_parent的更新語句
				$sql .= ', post_parent = CASE ';
				$sql .= implode(' ', $parent_cases);
				$sql .= ' ELSE post_parent END ';

				// 加入WHERE條件，限制只更新需要的記錄
				$sql .= " WHERE ID IN ($id_list)";

				// 執行批量更新 wp_posts
				$result = $wpdb->query($sql);  // phpcs:ignore

				if ($result === false) {
					throw new \Exception( esc_html__( 'Batch update failed', 'power-course' ) . ': ' . esc_html( (string) $wpdb->last_error ) );
				}

				// ----- 處理 parent_course_id ----- //
				// 1. 先收集需要處理的資料
				$delete_meta_ids       = []; // 需要刪除的 post_id (parent_course_id = 0)
				$insert_or_update_data = []; // 需要插入或更新的資料 (parent_course_id != 0)

				foreach ($batch as $item) {
					$id               = intval($item['id']);
					$parent_course_id = intval($item['parent_course_id']);

					if ($parent_course_id === 0) {
						$delete_meta_ids[] = $id;
					} else {
						$insert_or_update_data[] = [
							'post_id' => $id,
							'value'   => $parent_course_id,
						];
					}
				}

				// 2. 刪除 parent_course_id = 0 的記錄
				if (!empty($delete_meta_ids)) {
					$delete_id_placeholders = implode(',', array_fill(0, count($delete_meta_ids), '%d'));
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$delete_sql = "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($delete_id_placeholders) AND meta_key = 'parent_course_id'";
					$wpdb->query(
						$wpdb->prepare(
							$delete_sql, // phpcs:ignore
							$delete_meta_ids
						)
					);
				}

				// 3. 處理需要插入或更新的數據
				if (!empty($insert_or_update_data)) {
					// 先用一個查詢找出哪些記錄已存在
					$check_ids          = array_column($insert_or_update_data, 'post_id');
					$check_placeholders = implode(',', array_fill(0, count($check_ids), '%d'));
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					$check_sql    = "SELECT post_id FROM {$wpdb->postmeta} WHERE post_id IN ($check_placeholders) AND meta_key = 'parent_course_id'";
					$existing_ids = $wpdb->get_col(
						$wpdb->prepare(
							$check_sql, // phpcs:ignore
							$check_ids
						)
					);
					$existing_ids = array_map('intval', $existing_ids);

					// 構建更新語句
					$update_data   = [];
					$update_values = [];
					foreach ($insert_or_update_data as $data) {
						if (in_array($data['post_id'], $existing_ids)) {
							$update_data[]   = $data;
							$update_values[] = $data['post_id'];
							$update_values[] = $data['value'];
						}
					}

					if (!empty($update_data)) {
						$update_cases       = [];
						$update_values_flat = [];

						foreach ($update_data as $data) {
							$update_cases[]       = 'WHEN post_id = %d THEN %d';
							$update_values_flat[] = $data['post_id'];
							$update_values_flat[] = $data['value'];
						}

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$update_sql  = "UPDATE {$wpdb->postmeta} SET meta_value = CASE ";
						$update_sql .= implode(' ', $update_cases);
						$update_sql .= ' ELSE meta_value END WHERE post_id IN (';
						$update_sql .= implode(',', array_fill(0, count($update_data), '%d'));
						$update_sql .= ") AND meta_key = 'parent_course_id'";

						$wpdb->query(
							$wpdb->prepare(
								$update_sql, // phpcs:ignore
								array_merge($update_values_flat, array_column($update_data, 'post_id'))
							)
						);
					}

					// 構建插入語句 - 插入不存在的記錄
					$insert_data = [];
					foreach ($insert_or_update_data as $data) {
						if (!in_array($data['post_id'], $existing_ids)) {
							$insert_data[] = $data;
						}
					}

					if (!empty($insert_data)) {
						$insert_values       = [];
						$insert_placeholders = [];

						foreach ($insert_data as $data) {
							$insert_placeholders[] = '(%d, %s, %d)';
							$insert_values[]       = $data['post_id'];
							$insert_values[]       = 'parent_course_id';
							$insert_values[]       = $data['value'];
						}

						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
						$insert_sql  = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES ";
						$insert_sql .= implode(', ', $insert_placeholders);

						$wpdb->query(
							$wpdb->prepare(
								$insert_sql, // phpcs:ignore
								$insert_values
							)
						);
					}
				}
			}

			// 提交事務
			$wpdb->query('COMMIT');

			// 【Issue #216 Bug #2 修復】逐筆清除 post object cache。
			// 原因：raw $wpdb->query() 直接寫入 post_parent，未透過 wp_update_post，
			// 因此 WordPress object cache 仍持有 stale 值。後續 wp_get_post_parent_id()
			// 會從 cache 讀到舊值（0），導致 wp_insert_post_data filter 在編輯子章節時
			// 誤判 parent 不存在而清空 post_parent。
			// 解法：對所有被異動的 post_id 逐筆呼叫 clean_post_cache，
			// 這會同時觸發 'clean_post_cache' action 讓下游外掛同步。
			$all_updated_ids = array_map(
				static fn ( array $node ): int => (int) $node['id'],
				$new_to_tree
			);
			foreach ( $all_updated_ids as $updated_id ) {
				\clean_post_cache( $updated_id );
			}

			// 保留 group flush 作為部分 object cache 實作（如 Memcached）
			// 不支援個別 cache key 失效時的 fallback
			\wp_cache_flush_group('posts');
			\wp_cache_flush_group('post_meta');

		} catch (\Exception $e) {
			// 回滾事務
			$wpdb->query('ROLLBACK');
			throw new \Exception( esc_html__( 'Failed to sort chapters', 'power-course' ) . ': ' . esc_html( $e->getMessage() ) );
		}

		foreach ($delete_ids as $id) {
			\wp_trash_post( (int) $id );
		}

		return true;
	}

	/**
	 * Converter 轉換器
	 * 把 key 轉換/重新命名，將 前端傳過來的欄位轉換成 wp_update_post 能吃的參數
	 *
	 * 前端圖片欄位就傳 'image_ids' string[] 就好
	 *
	 * @param array<string, mixed> $args    Arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function converter( array $args ): array {
		$fields_mapper = [
			'id'                => 'unset', // 不要把 id 回傳
			'name'              => 'post_title',
			'slug'              => 'post_name',
			'description'       => 'post_content',
			'short_description' => 'post_excerpt',
			'status'            => 'post_status',
			'category_ids'      => 'post_category',
			'tag_ids'           => 'tags_input',
			'parent_id'         => 'post_parent',
			'depth'             => 'unset',
		];

		$formatted_args = [];
		foreach ($args as $key => $value) {
			if (in_array($key, array_keys($fields_mapper), true)) {
				// 標註為 unset 的，不要回傳
				if ('unset' === $fields_mapper[ $key ]) {
					continue;
				}
				$formatted_args[ $fields_mapper[ $key ] ] = $value;
			} else {
				$formatted_args[ $key ] = $value;
			}
		}

		return $formatted_args;
	}

	/**
	 * Update a chapter
	 *
	 * @param string               $id   chapter id.
	 * @param array<string, mixed> $args Arguments.
	 *
	 * @return integer|\WP_Error
	 */
	public static function update_chapter( string $id, array $args ): int|\WP_Error {

		$args['ID']            = (int) $id;
		$args['post_title']    = $args['post_title'] ?? esc_html__( 'New chapter', 'power-course' );
		$args['post_status']   = $args['status'] ?? 'publish';
		$args['post_author']   = \get_current_user_id();
		$args['post_type']     = CPT::POST_TYPE;
		$args['page_template'] = self::TEMPLATE;

		/** @var array{ID: int, post_title: string, post_status: string, post_author: int, post_type: string, page_template: string} $args */
		$update_result = \wp_update_post($args);

		return $update_result;
	}

	/**
	 * 取得章節的課程 ID
	 *
	 * @param int $chapter_id 章節 ID.
	 * @return int|null
	 * @since 0.8.0
	 * 原:最上層的 post_parent 就是課程 id
	 * 改為: 最上層的 post_parent 是頂層章節，還要從 post_meta 取得 parent_course_id
	 */
	public static function get_course_id( int $chapter_id ): int|null {
		$top_parent_id    = PostUtils::get_top_post_id($chapter_id);
		$parent_course_id = (int) \get_post_meta( $top_parent_id, 'parent_course_id', true );
		return $parent_course_id ? $parent_course_id : null;
	}

	/**
	 * 檢查章節是否可存取
	 *
	 * @param int|null $chapter_id 章節 ID.
	 * @param int|null $user_id 用戶 ID.
	 * @return bool
	 */
	public static function is_avl( ?int $chapter_id = 0, ?int $user_id = null ): bool {
		$user_id = $user_id ?? \get_current_user_id();
		if ( ! $user_id ) {
			return false;
		}

		if (!$chapter_id) {
			global $chapter;
			if (!( $chapter instanceof \WP_Post )) {
				return false;
			}
			$chapter_id = $chapter->ID;
		}

		$course_id = self::get_course_id( $chapter_id );
		if ( !$course_id ) {
			return false;
		}

		$can_access_course = CourseUtils::is_avl( (int) $course_id, (int) $user_id);
		if ( !$can_access_course ) {
			return false;
		}

		return true; // 可能可以 apply filters
	}


	/**
	 * 取得格式化後的水印文字
	 *
	 * @param string|null $type 類型.
	 * @return string
	 */
	public static function get_formatted_watermark_text( ?string $type = 'video' ): string {
		$settings = Settings::instance();
		/** @var string $watermark_text */
		$watermark_text = match ($type) {
			'pdf'   => $settings->pc_pdf_watermark_text,
			default => $settings->pc_watermark_text,
		};

		$wp_current_user = \wp_get_current_user();
		$email           = $wp_current_user->user_email ?: '';
		$display_name    = $wp_current_user->display_name ?: '';
		$username        = $wp_current_user->user_login ?: '';
		$ip              = General::get_client_ip() ?? '';

		global $chapter;
		$post_title = ( $chapter instanceof \WP_Post ) ? $chapter->post_title : '';

		$formatted_watermark_text = str_replace( [ '{email}', '{ip}', '{display_name}', '{username}', '{post_title}' ], [ $email, $ip, $display_name, $username, $post_title ], $watermark_text );

		return $formatted_watermark_text;
	}

	/**
	 * 取得快取 key
	 *
	 * @param int    $post_id 章節 ID.
	 * @param string $key 快取 key.
	 * @return string
	 */
	public static function get_cache_key( int $post_id, string $key = 'get_children_posts_html' ): string {
		return "power_course_{$key}_{$post_id}";
	}

	/**
	 * 取得子章節的 HTML (判斷快取)
	 *
	 * @param int                       $post_id 章節 ID.
	 * @param array<int, \WP_Post>|null $children_posts 子章節.
	 * @param int                       $depth 深度.
	 * @return string
	 */
	public static function get_children_posts_html( int $post_id, array $children_posts = null, $depth = 0 ): string {
		$cache_key = self::get_cache_key( $post_id );
		$html      = \get_transient( $cache_key );

		if ( is_string($html) && $html !== '' ) {
			return $html;
		}

		$html = self::get_children_posts_html_uncached( $post_id, $children_posts, $depth );
		\set_transient( $cache_key, $html, 60 * 60 * 24 );

		return $html;
	}

	/**
	 * 取得課程章節的 HTML
	 *
	 * @param int                            $post_id 課程 id
	 * @param array<int, \WP_Post>|null      $children_posts 子章節.
	 * @param int                            $depth 深度，預設從 0 (課程) 開始
	 * @param 'classroom' | 'course-product' $context 上下文，預設為 'classroom'，表示課程頁面
	 * @return string
	 */
	public static function get_children_posts_html_uncached( int $post_id, array $children_posts = null, $depth = 0, $context = 'classroom', ?array $lock_status = null ): string {
		global $post; // 當前文章

		// 在 depth=0 且 context='classroom' 時，一次性計算整個課程的鎖定狀態
		if ( 0 === $depth && 'classroom' === $context && null === $lock_status ) {
			$course_id = $post_id;
			$user_id   = \get_current_user_id();
			if ( $user_id && LinearViewing::is_enabled( $course_id ) && ! LinearViewing::is_exempt( $user_id ) ) {
				$status      = LinearViewing::get_unlock_status( $course_id, $user_id );
				$lock_status = [
					'locked_ids'   => $status['locked_ids'],
					'unlocked_ids' => $status['unlocked_ids'],
					'course_id'    => $course_id,
					'user_id'      => $user_id,
				];
			}
		}

		$html = '';
		if ($children_posts === null) {
			if ($depth === 0) {
				// 因為課程 與章節關係不是 post_parent 而是 parent_course_id
				$children_posts = \get_posts(
					[
						'post_type'      => CPT::POST_TYPE,
						'meta_query'     => [
							[
								'key'   => 'parent_course_id',
								'value' => $post_id,
							],
						],
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'orderby'        => [
							'menu_order' => 'ASC',
							'ID'         => 'ASC',
							'date'       => 'ASC',
						],
					]
				);
			} else {
				// 如果是抓取子章節的子章節
				$children_posts = \get_posts(
					[
						'post_type'      => CPT::POST_TYPE,
						'post_parent'    => $post_id,
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'orderby'        => [
							'menu_order' => 'ASC',
							'ID'         => 'ASC',
							'date'       => 'ASC',
						],
					]
				);
			}
		}

		if (!$children_posts) {
			return '';
		}

		$html .= sprintf(
		/*html*/'<ul class="m-0 p-0 list-none" %1$s>',
			$depth > 0 ? 'style="display: none;"' : ''
		);
		foreach ($children_posts as $child_post) {

			// 取得子章節的子章節
			/** @var \WP_Post[] $child_children_posts */
			$child_children_posts = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'post_parent'    => $child_post->ID,
				'posts_per_page' => -1,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
					'date'       => 'ASC',
				],
			]
			);

			// 判斷此章節是否被鎖定
			$is_locked      = false;
			$lock_message   = '';
			$locked_class   = '';
			$locked_attrs   = '';
			if ( null !== $lock_status && in_array( $child_post->ID, $lock_status['locked_ids'], true ) ) {
				$is_locked    = true;
				$lock_message = LinearViewing::get_lock_message_from_unlocked( $lock_status['unlocked_ids'] );
				$locked_class = ' pc-chapter-locked';
				$locked_attrs = sprintf(
					' data-locked="true" data-lock-message="%s"',
					esc_attr( $lock_message )
				);
			}

			// 鎖定章節使用鎖頭圖示，未鎖定使用原始圖示
			$chapter_icon = '';
			if ( 'course-product' !== $context ) {
				if ( $is_locked ) {
					$lock_icon_svg = /*html*/'<svg class="size-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 14.5V16.5M7 10.0288C7.47142 10 8.05259 10 8.8 10H15.2C15.9474 10 16.5286 10 17 10.0288M7 10.0288C6.41168 10.0647 5.99429 10.1455 5.63803 10.327C5.07354 10.6146 4.6146 11.0735 4.32698 11.638C4 12.2798 4 13.1198 4 14.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V14.8C20 13.1198 20 12.2798 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C18.0057 10.1455 17.5883 10.0647 17 10.0288M7 10.0288V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10.0288" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
					$chapter_icon  = '<div class="pc-chapter-icon size-8 p-1"><div class="pc-tooltip pc-tooltip-right h-6" data-tip="' . esc_attr( $lock_message ) . '">' . $lock_icon_svg . '</div></div>';
				} else {
					$chapter_icon = '<div class="pc-chapter-icon size-8 p-1">' . self::get_chapter_icon_html( $child_post->ID ) . '</div>';
				}
			}

			$html .= sprintf(
			/*html*/'
			<li data-post-id="%6$s" data-href="%1$s" class="hover:bg-primary/10 pr-2 transition-all duration-300 rounded-btn cursor-pointer flex items-center justify-between text-sm mb-1 %7$s%8$s" style="padding-left: %5$s;"%9$s>
				<div class="py-2 flex items-center flex-1">
					%2$s
					<span class="ml-2%10$s">%3$s</span>
				</div>
				<div class="flex items-center justify-end gap-x-0 w-8">
					%4$s
				</div>
			</li>
			',
			\get_the_permalink($child_post->ID),
			$chapter_icon,
			\esc_html( $child_post->post_title ),
				// 如果有子章節，就顯示箭頭
			$child_children_posts ? /*html*/'
				<div class="p-2 icon-arrow flex items-center">
					<svg class="w-4 h-4 fill-base-content" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><g stroke-width="0"></g><g stroke-linecap="round" stroke-linejoin="round"></g><g> <path fill-rule="evenodd" clip-rule="evenodd" d="M8.29289 4.29289C8.68342 3.90237 9.31658 3.90237 9.70711 4.29289L16.7071 11.2929C17.0976 11.6834 17.0976 12.3166 16.7071 12.7071L9.70711 19.7071C9.31658 20.0976 8.68342 20.0976 8.29289 19.7071C7.90237 19.3166 7.90237 18.6834 8.29289 18.2929L14.5858 12L8.29289 5.70711C7.90237 5.31658 7.90237 4.68342 8.29289 4.29289Z"></path> </g></svg>
				</div>
			' : '',
			( ( $depth * 2 ) + 0.5 ) . 'rem',
			$child_post->ID,
			$child_post->ID === $post->ID ? 'bg-primary/10 font-bold [&_a]:text-primary' : 'font-normal [&_a]:text-base-content', // 如果是當前文章，就顯示 primary 顏色
			$locked_class,
			$locked_attrs,
			$is_locked ? ' opacity-50' : ''
			);

			// 沒有子章節就結束
			if (!$child_children_posts) {
				continue;
			}

			// 有子章節就遞迴取得子章節的子章節
			$html .= self::get_children_posts_html_uncached($child_post->ID, $child_children_posts, $depth + 1, $context, $lock_status);
		}
		$html .= /* html */'</ul>';

		return $html;
	}


	/**
	 * 取得章節的 icon html
	 *
	 * @param int $chapter_id 章節 ID.
	 * @return string
	 */
	public static function get_chapter_icon_html( int $chapter_id ): string {
		$avl_chapter    = new Chapter( (int) $chapter_id );
		$first_visit_at = $avl_chapter->first_visit_at;
		$finished_at    = $avl_chapter->finished_at;

		$icon_html = Plugin::load_template( 'icon/video', null, false );
		$tooltip   = esc_attr__( 'Click to watch', 'power-course' );
		if ( $first_visit_at ) {
			$icon_html = Plugin::load_template( 'icon/check', [ 'type' => 'outline' ], false );
			$tooltip   = sprintf(
				/* translators: %s: 首次觀看時間 */
				esc_attr__( 'Started watching at %s', 'power-course' ),
				$first_visit_at
			);
		}
		if ( $finished_at ) {
			$icon_html = Plugin::load_template( 'icon/check', null, false );
			$tooltip   = sprintf(
				/* translators: %s: 完成時間 */
				esc_attr__( 'Completed chapter at %s', 'power-course' ),
				$finished_at
			);
		}
		$icon_html_with_tooltip = sprintf(
			/*html*/'<div class="pc-tooltip pc-tooltip-right h-6" data-tip="%1$s">%2$s</div>',
			$tooltip,
			$icon_html
		);

		return $icon_html_with_tooltip;
	}

	/**
	 * 取得扁平的子孫 post ids，不包含頂層 id
	 * 階層子孫結構都打平
	 *
	 * @param int $course_id 課程 ID.
	 * @return array<int>
	 */
	public static function get_flatten_post_ids( int $course_id ): array {

		$flatten_post_ids = \wp_cache_get( 'flatten_post_ids_' . $course_id, 'prev_next' );
		if (is_array($flatten_post_ids)) {
			/** @var array<int> $flatten_post_ids */
			return $flatten_post_ids;
		}

		$flatten_post_ids = [];

		/** @var array<int> $top_chapter_ids */
		$top_chapter_ids = \get_posts(
			[
				'post_type'      => CPT::POST_TYPE,
				'meta_query'     => [
					[
						'key'   => 'parent_course_id',
						'value' => $course_id,
					],
				],
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => [
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
					'date'       => 'ASC',
				],
			]
		);

		foreach ($top_chapter_ids as $top_chapter_id) {
			$child_chapter_ids = PostUtils::get_flatten_post_ids(
				(int) $top_chapter_id,
				[
					'post_status' => 'publish',
				]
				);
			$flatten_post_ids  = [
				...$flatten_post_ids,
				(int) $top_chapter_id,
				...$child_chapter_ids,
			];
		}

		\wp_cache_set( 'flatten_post_ids_' . $course_id, $flatten_post_ids, 'prev_next' );

		return \array_values($flatten_post_ids);
	}

	/**
	 * 取得上一個章節的 id
	 *
	 * @param int $chapter_id 章節 ID.
	 * @return int|null
	 */
	public static function get_prev_post_id( int $chapter_id ): int|null {
		$prev_post_id = \wp_cache_get( 'prev_post_id_' . $chapter_id, 'prev_next' );

		if (false !== $prev_post_id) {
			return $prev_post_id ? (int) $prev_post_id : null;
		}

		$course_id = self::get_course_id( $chapter_id );
		if (null === $course_id) {
			return null;
		}
		$all_children_ids = self::get_flatten_post_ids( $course_id);

		// find index of current post id
		/** @var int|false $current_post_index */
		$current_post_index = array_search($chapter_id, $all_children_ids, true);

		if (false === $current_post_index) {
			// 此文章 id 不在列表中
			return null;
		}

		$prev_post_id = $all_children_ids[ $current_post_index - 1 ] ?? null;

		\wp_cache_set( 'prev_post_id_' . $chapter_id, $prev_post_id, 'prev_next' );

		return $prev_post_id ? (int) $prev_post_id : null;
	}


	/**
	 * 取得下一個章節的 id
	 *
	 * @param int $chapter_id 章節 ID.
	 * @return int|null
	 */
	public static function get_next_post_id( int $chapter_id ): int|null {
		$next_post_id = \wp_cache_get( 'next_post_id_' . $chapter_id, 'prev_next' );

		if (false !== $next_post_id) {
			return $next_post_id === null ? null : (int) $next_post_id;
		}

		$course_id = self::get_course_id( $chapter_id );
		if (null === $course_id) {
			return null;
		}
		$all_children_ids = self::get_flatten_post_ids( $course_id);

		// find index of current post id
		/** @var int|false $current_post_index */
		$current_post_index = array_search($chapter_id, $all_children_ids, true);

		if (false === $current_post_index) {
			// 此文章 id 不在列表中
			return null;
		}

		$next_post_id = $all_children_ids[ $current_post_index + 1 ] ?? null;

		\wp_cache_set( 'next_post_id_' . $chapter_id, $next_post_id, 'prev_next' );

		return $next_post_id === null ? null : (int) $next_post_id;
	}
}
