<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\ApiBase;
use J7\PowerCourse\Admin\Product as AdminProduct;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Chapter\Core\CPT as ChapterCPT;
use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\WpUtils\Classes\WC;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\General;
use J7\PowerCourse\Resources\Course\LifeCycle;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\BundleProduct\Helper;
use J7\Powerhouse\Domains\Product\Utils\CRUD;
use J7\PowerCourse\Utils\Subscription as SubscriptionUtils;

/** Course API */
final class Course extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;
	use Course\UserTrait;

	/**
	 * Namespace
	 *
	 * @var string
	 */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * @var array
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 * @phpstan-ignore-next-line
	 */
	protected $apis = [
		[
			'endpoint' => 'courses',
			'method'   => 'get',
		],
		[
			'endpoint' => 'courses/(?P<id>\d+)',
			'method'   => 'get',
		],
		[
			'endpoint' => 'courses',
			'method'   => 'post',
		],
		[
			'endpoint' => 'courses/(?P<id>\d+)',
			'method'   => 'post',
		],
		[
			'endpoint' => 'courses',
			'method'   => 'delete',
		],
		[
			'endpoint' => 'courses/(?P<id>\d+)',
			'method'   => 'delete',
		],
		[
			'endpoint' => 'courses/terms',
			'method'   => 'get',
		],
		[
			'endpoint' => 'courses/options',
			'method'   => 'get',
		],
		// ▼ User 相關，放在 UserTrait
		[
			'endpoint' => 'courses/student-logs',
			'method'   => 'get',
		],
		[
			'endpoint' => 'courses/add-students',
			'method'   => 'post',
		],
		[
			'endpoint' => 'courses/remove-students',
			'method'   => 'post',
		],
		[
			'endpoint' => 'courses/update-students',
			'method'   => 'post',
		],
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		\add_filter( 'wp_kses_allowed_html', [ $this, 'extend_wpkses_post_tags' ], 10, 2 );
	}

	/**
	 * 擴展 WordPress 內容安全政策，允許在特定上下文中使用 iframe 標籤。
	 *
	 * 此方法修改了 WordPress 允許的 HTML 標籤列表，專門針對 'product' 上下文，允許在產品描述等地方使用 iframe 標籤。
	 * 這使得用戶可以嵌入例如視頻等外部內容。此方法應該與 'wp_kses_allowed_html' 過濾器一起使用。
	 *
	 * @param array  $tags    原始允許的 HTML 標籤和屬性的列表。
	 * @param string $context 使用場景的上下文標識符。
	 *
	 * @return array 修改後的標籤和屬性列表。
	 * @phpstan-ignore-next-line
	 */
	public function extend_wpkses_post_tags( $tags, $context ) {

		if ( 'post' === $context ) {
			$tags['iframe'] = [
				'src'             => true,
				'height'          => true,
				'width'           => true,
				'frameborder'     => true,
				'allowfullscreen' => true,
			];
		}
		return $tags;
	}

	/**
	 * Get courses callback
	 * 當商品是 "課程" 時，才會被抓出來
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_courses_callback( $request ) { // phpcs:ignore

		$params = $request->get_query_params();

		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'status'         => [ 'publish', 'draft' ],
			'paginate'       => true,
			'posts_per_page' => 10,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => '_is_course',
			'meta_value'     => 'yes',
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		// if ( isset( $args['price_range'] ) ) {
		// $args['meta_query'] = [
		// 'relation' => 'AND',
		// [
		// 'key'     => '_price',                                // 價格自定義欄位
		// 'value'   => $args['price_range'], // 設定價格範圍
		// 'compare' => 'BETWEEN',                               // 在此範圍之間
		// 'type'    => 'DECIMAL',                               // 處理為數值
		// ],
		// ];
		// unset( $args['price_range'] );
		// }

		/** @var object{total:int, max_num_pages:int, products:array<int, \WC_Product>} */
		$results     = \wc_get_products( $args );
		$total       = $results->total;
		$total_pages = $results->max_num_pages;

		$products = $results->products;

		$formatted_products = array_values(array_map( [ $this, 'format_course_base_records' ], $products ));

		$response = new \WP_REST_Response( $formatted_products );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}


	/**
	 * Get single course callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_courses_with_id_callback( $request ) { // phpcs:ignore
		$id = $request['id'] ?? null;

		if (!$id) {
			return new \WP_REST_Response(
				[
					'message' => 'id is required',
				],
				400
				);
		}

		$product = \wc_get_product( $id );

		if (!$product) {
			return new \WP_REST_Response(
				[
					'message' => 'product not found',
				],
				404
				);
		}

		$formatted_product = $this->format_course_records( $product );

		$response = new \WP_REST_Response( $formatted_product );

		return $response;
	}

	/**
	 * Format course records
	 *
	 * @see https://www.businessbloomer.com/woocommerce-easily-get-product-info-title-sku-desc-product-object/
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return array<string, mixed>
	 */
	public function format_course_records( $product ) { // phpcs:ignore

		$base_array = $this->format_course_base_records( $product );

		$chapters = array_values(
			\get_children(
				[
					'post_parent' => $product->get_id(),
					'post_type'   => ChapterCPT::POST_TYPE,
					'numberposts' => - 1,
					'post_status' => 'any',
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				]
			)
		);
		// @phpstan-ignore-next-line
		$chapters = array_values(array_map( [ ChapterUtils::class, 'format_chapter_details' ], $chapters ));

		$children = (bool) $chapters ? [
			'chapters' => $chapters,
		] : [];

		$bundle_ids = Helper::get_bundle_products( $product->get_id(), true);

		$limit = Limit::instance($product);

		$extra_array = [
			'purchase_note'                 => $product->get_purchase_note(),
			'description'                   => $product->get_description(),
			'short_description'             => $product->get_short_description(),
			'upsell_ids'                    => array_map( 'strval', $product->get_upsell_ids() ),
			'cross_sell_ids'                => array_map( 'strval', $product->get_cross_sell_ids() ),
			'attributes'                    => WC::get_product_attribute_array( $product ),

			'qa_list'                       => (array) $product->get_meta( 'qa_list' ),
			'limit_type'                    => $limit->limit_type,
			'limit_value'                   => $limit->limit_value,
			'limit_unit'                    => $limit->limit_unit,
			'is_popular'                    => (string) $product->get_meta( 'is_popular' ),
			'is_featured'                   => (string) $product->get_meta( 'is_featured' ),
			'show_join'                   => (string) $product->get_meta( 'show_join' ),
			'show_review'                   => (string) $product->get_meta( 'show_review' ),
			'reviews_allowed'               => (bool) $product->get_reviews_allowed(),
			'show_description_tab'          => (string) $product->get_meta( 'show_description_tab' ) ?: 'yes',
			'show_chapter_tab'              => (string) $product->get_meta( 'show_chapter_tab' ) ?: 'yes',
			'show_qa_tab'                   => (string) $product->get_meta( 'show_qa_tab' ) ?: 'yes',
			'show_review_tab'               => (string) $product->get_meta( 'show_review_tab' ) ?: 'yes',
			'show_review_list'              => (string) $product->get_meta( 'show_review_list' ) ?: 'yes',
			'show_course_complete'          => (string) $product->get_meta( 'show_course_complete' ) ?: 'no',
			'show_course_schedule'          => (string) $product->get_meta( 'show_course_schedule' ) ?: 'yes',
			'show_course_time'              => (string) $product->get_meta( 'show_course_time' ) ?: 'yes',
			'show_course_chapters'          => (string) $product->get_meta( 'show_course_chapters' ) ?: 'yes',
			'show_course_limit'             => (string) $product->get_meta( 'show_course_limit' ) ?: 'yes',
			'show_total_student'            => (string) $product->get_meta( 'show_total_student' ) ?: 'yes',
			'enable_comment'                => (string) $product->get_meta( 'enable_comment' ) ?: 'yes',
			'hide_single_course'            => (string) $product->get_meta( 'hide_single_course' ) ?: 'no',
			'enable_bundles_sticky'         => (string) $product->get_meta( 'enable_bundles_sticky' ) ?: 'no',
			'enable_mobile_fixed_cta'       => (string) $product->get_meta( 'enable_mobile_fixed_cta' ) ?: 'no',
			'show_stock_quantity'           => (string) $product->get_meta( 'show_stock_quantity' ) ?: 'no',
			'show_customer_amount'          => (string) $product->get_meta( 'show_customer_amount' ) ?: 'no',
			'show_total_sales'              => (string) $product->get_meta( 'show_total_sales' ) ?: 'no',
			'show_rest_stock'               => (string) $product->get_meta( 'show_rest_stock' ) ?: 'no',
			'extra_student_count'           => (int) $product->get_meta( 'extra_student_count' ),
			'feature_video'                 => $product->get_meta( 'feature_video' ) ?: [
				'type' => 'none',
				'id'   => '',
				'meta' => [],
			],
			'trial_video'                   => $product->get_meta( 'trial_video' ) ?: [
				'type' => 'none',
				'id'   => '',
				'meta' => [],
			],
			// bundle product
			'bundle_ids'                    => $bundle_ids,
		] + SubscriptionUtils::get_normalized_meta( $product ) + [
			'editor'                        => (string) $product->get_meta( 'editor' ) ?: 'power-editor',

		] + $children;

		return array_merge(
			$base_array,
			$extra_array,
		);
	}

	/**
	 * Format course base records
	 *
	 * @see https://www.businessbloomer.com/woocommerce-easily-get-product-info-title-sku-desc-product-object/
	 *
	 * @param \WC_Product $product Product.
	 *
	 * @return array<string, mixed>
	 */
	public function format_course_base_records( $product ) { // phpcs:ignore

		// @phpstan-ignore-next-line
		if ( ! ( $product instanceof \WC_Product ) ) {
			return [];
		}

		$date_created  = $product->get_date_created();
		$date_modified = $product->get_date_modified();

		$image_id          = $product->get_image_id();
		$gallery_image_ids = $product->get_gallery_image_ids();

		$image_ids = $image_id ? [ $image_id, ...$gallery_image_ids ] : [];
		$images    = $image_ids ? array_map( [ WP::class, 'get_image_info' ], $image_ids ) : [];

		$low_stock_amount = ( '' === $product->get_low_stock_amount() ) ? null : $product->get_low_stock_amount();

		$chapters = array_values(
			\get_children(
				[
					'post_parent' => $product->get_id(),
					'post_type'   => ChapterCPT::POST_TYPE,
					'numberposts' => - 1,
					'post_status' => 'any',
					'orderby'     => 'menu_order',
					'order'       => 'ASC',
				]
			)
		);
		// @phpstan-ignore-next-line
		$chapters = array_values(array_map( [ ChapterUtils::class, 'format_chapter_details' ], $chapters ));
		// 把子章節的時間加總
		/** @var array<int, array<string, mixed>> $chapters */
		$course_length = array_reduce(
			$chapters,
			function ( $acc, $chapter ) {
				/** @var array<int, array<string, mixed>> $sub_chapters */
				$sub_chapters       = $chapter['chapters'] ?? [];
				$sub_chapter_length = array_reduce(
					$sub_chapters,
					function ( $acc, $sub_chapter ) {
						return $acc + (int) $sub_chapter['chapter_length'];
					},
					0
					);
				return $acc + $sub_chapter_length;
			},
			0
			);

		$regular_price = $product->get_regular_price();
		$sale_price    = $product->get_sale_price();
		$price_html    = CRUD::get_price_html($product);
		$product_id    = $product->get_id();
		$categories    = Product::format_terms(
			[
				'taxonomy'   => 'product_cat',
				'object_ids' => $product_id,
			]
			);
		$category_ids  = array_column( $categories, 'id' );
		$tags          = Product::format_terms(
			[
				'taxonomy'   => 'product_tag',
				'object_ids' => $product_id,
			]
			);
		$tag_ids       = array_column( $tags, 'id' );

		$sale_date_range = [ (int) $product->get_date_on_sale_from()?->getTimestamp(), (int) $product->get_date_on_sale_to()?->getTimestamp() ];

		$base_array = [
			// Get Product General Info
			'id'                 => (string) $product_id,
			'type'               => $product->get_type(),
			'name'               => $product->get_name(),
			'slug'               => $product->get_slug(),
			'date_created'       => $date_created?->date( 'Y-m-d H:i:s' ),
			'date_modified'      => $date_modified?->date( 'Y-m-d H:i:s' ),
			'status'             => $product->get_status(),
			'featured'           => $product->get_featured(),
			'catalog_visibility' => $product->get_catalog_visibility(),
			'sku'                => $product->get_sku(),
			'menu_order'         => (int) $product->get_menu_order(),
			'virtual'            => $product->get_virtual(),
			'downloadable'       => $product->get_downloadable(),
			'permalink'          => \get_permalink( $product->get_id() ),
			'edit_url'           => \get_edit_post_link( $product->get_id(), 'raw' ) ?? '',
			'custom_rating'      => (float) $product->get_meta('custom_rating') ?: 2.5,
			'extra_review_count' => (int) $product->get_meta('extra_review_count'),

			// Get Product Prices
			'price_html'         => $price_html,
			'regular_price'      => $regular_price,
			'sale_price'         => $sale_price,
			'on_sale'            => $product->is_on_sale(),
			'sale_date_range'    => $sale_date_range,
			'date_on_sale_from'  => $sale_date_range[0],
			'date_on_sale_to'    => $sale_date_range[1],
			'total_sales'        => $product->get_total_sales(),

			// Get Product Stock
			'stock'              => $product->get_stock_quantity(),
			'stock_status'       => $product->get_stock_status(),
			'manage_stock'       => \wc_bool_to_string( $product->get_manage_stock() ),
			'stock_quantity'     => $product->get_stock_quantity(),
			'backorders'         => $product->get_backorders(),
			'backorders_allowed' => \wc_bool_to_string( $product->backorders_allowed() ),
			'backordered'        => \wc_bool_to_string( $product->is_on_backorder() ),
			'low_stock_amount'   => $low_stock_amount,
			'sold_individually'  => \wc_bool_to_string( $product->is_sold_individually() ),

			// Get Product Taxonomies
			'category_ids'       => $category_ids,

			'categories'         => Product::format_terms(
				[
					'taxonomy'   => 'product_cat',
					'object_ids' => $product_id,
				]
				),
			'tags'               => Product::format_terms(
				[
					'taxonomy'   => 'product_tag',
					'object_ids' => $product_id,
				]
				),
			'tag_ids'            => $tag_ids,
			// Get Product Images
			'images'             => $images,
			'is_course'          => (string) $product->get_meta( '_' . AdminProduct::PRODUCT_OPTION_NAME ),
			'is_free'            => (string) $product->get_meta( 'is_free' ),
			'qa_list'            => (array) $product->get_meta( 'qa_list' ),
			'course_schedule'    => (int) $product->get_meta( 'course_schedule' ),
			'course_hour'        => (int) $product->get_meta( 'course_hour' ),
			'course_minute'      => (int) $product->get_meta( 'course_minute' ),
			'teacher_ids'        => (array) \get_post_meta( $product->get_id(), 'teacher_ids', false ),
			'course_length'      => $course_length,
			'classroom_link'     => (string) CourseUtils::get_classroom_permalink( $product->get_id(), 'any' ),
			// 外部課程欄位（站內課程為空字串）
			'product_url'        => ( $product instanceof \WC_Product_External ) ? $product->get_product_url() : '',
			'button_text'        => ( $product instanceof \WC_Product_External ) ? $product->get_button_text() : '',
		];

		return $base_array;
	}

	/**
	 * Post courses callback
	 *
	 * @see https://rudrastyh.com/woocommerce/create-product-programmatically.html
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_courses_callback( $request ): \WP_REST_Response|\WP_Error {
		/** @var array<string, array<mixed>|string> $meta_data */
		[
			'product' => $product,
			'data'      => $data,
			'meta_data' => $meta_data,
		] = $this->separator($request);

		$this->handle_save_course_data($product, $data );
		$result = $this->handle_save_course_meta_data($product, $meta_data );

		if (true !== $result ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'create_success',
				'message' => '新增成功',
				'data'    => [
					'id' => (string) $product->get_id(),
				],
			]
		);
	}

	/**
	 * 處理並分離產品資訊
	 *
	 * 根據請求分離產品資訊，並處理描述欄位。
	 *
	 * @param \WP_REST_Request $request 包含產品資訊的請求對象。
	 * @throws \Exception 當找不到商品時拋出異常。.
	 * @return array{product:\WC_Product, data: array<string, mixed>, meta_data: array<string, mixed>} 包含產品對象、資料和元數據的陣列。
	 */
	private function separator( $request ): array {
		$id = $request['id'] ?? '';

		$body_params = $request->get_body_params();

		$skip_keys   = [
			'feature_video',
			'trial_video',
			'description',
			'short_description',
			'slug',
		];
		$body_params = WP::sanitize_text_field_deep($body_params, true, $skip_keys);

		// 將 '[]' 轉為 []，例如，清除原本分類時，如果空的，前端會是 undefined，轉成 formData 時會遺失
		/** @var array<string, mixed|string> $body_params */
		$body_params = is_array($body_params) ? General::parse($body_params) : [];

		// 取出 is_external 參數，在決定產品實例類型後不需進入 meta_data 流程
		$is_external = ! empty( $body_params['is_external'] ) && 'false' !== $body_params['is_external'];
		unset( $body_params['is_external'] );

		$product = \wc_get_product( $id );
		if ( ! $product ) {
			// 新增模式：根據 is_external 建立正確的產品實例
			if ( $is_external ) {
				$product = new \WC_Product_External();
			} else {
				$product = new \WC_Product_Simple();
			}
		}

		[
			'data'      => $data,
			'meta_data' => $meta_data,
		] = WP::separator( $body_params, 'product' );

		return [
			'product'   => $product,
			'data'      => $data,
			'meta_data' => $meta_data,
		];
	}

	/**
	 * 處理儲存課程資料
	 *
	 * @param \WC_Product          $product 產品物件
	 * @param array<string, mixed> $data 要更新的資料
	 * @return void
	 */
	private function handle_save_course_data( \WC_Product $product, array $data ): void {
		$data['virtual'] = true; // 課程固定為虛擬商品
		foreach ( $data as $key => $value ) {
			$method_name = 'set_' . $key;
			$product->$method_name( $value );
		}

		$product->save();
	}

	/**
	 * 儲存課程的元資料
	 *
	 * @param \WC_Product                        $product 代表 WooCommerce 產品的物件
	 * @param array<string, array<mixed>|string> $meta_data 需要更新的元資料陣列
	 * @return \WP_Error|true
	 */
	private function handle_save_course_meta_data( \WC_Product $product, array $meta_data ): \WP_Error|bool {
		// 判斷是否為外部課程
		$is_external = $product instanceof \WC_Product_External;

		// type 會被儲存為商品的類型，不需要再額外存進 meta data
		$is_subscription = 'subscription' === ( $meta_data['type'] ?? '' );
		unset( $meta_data['type'] );

		if ( $is_external ) {
			// 外部課程處理：取出 product_url 與 button_text，套用並驗證
			$product_url = isset( $meta_data['product_url'] ) ? (string) $meta_data['product_url'] : '';
			$button_text = isset( $meta_data['button_text'] ) ? (string) $meta_data['button_text'] : '';
			unset( $meta_data['product_url'], $meta_data['button_text'] );

			// 驗證 product_url（只有在有傳入時才驗證）
			if ( '' !== $product_url ) {
				$validation = $this->validate_product_url( $product_url );
				if ( \is_wp_error( $validation ) ) {
					return $validation;
				}
				$product->set_product_url( $product_url );
			} elseif ( 0 === $product->get_id() ) {
				// 新增模式下 product_url 為必填
				return new \WP_Error(
					'product_url_required',
					'外部課程的 product_url 為必填',
					[ 'status' => 400 ]
				);
			}

			// 設定 button_text，未填時使用預設值
			$product->set_button_text( '' !== $button_text ? $button_text : '前往課程' );

			// product_url 與 button_text 是 WC_Product_External 的 extra_data props，
			// 必須透過 $product->save() 才能持久化，save_meta_data() 不會處理這些欄位
			$product->save();

			// 外部課程不適用：subscription、limit、course_schedule
			SubscriptionUtils::delete_meta( $product );
			foreach ( SubscriptionUtils::get_fields() as $field ) {
				unset( $meta_data[ $field ] );
			}
			$external_unsupported_keys = [ 'limit_type', 'limit_value', 'limit_unit', 'course_schedule' ];
			foreach ( $external_unsupported_keys as $key ) {
				unset( $meta_data[ $key ] );
			}
		} else {
			// 站內課程：訂閱處理邏輯
			if ( $is_subscription ) {
				$validation = SubscriptionUtils::validate_class();
				if ( \is_wp_error( $validation ) ) {
					return $validation;
				}
			}

			// 如果是非訂閱商品，則刪除訂閱商品的相關資料
			if ( ! $is_subscription ) {
				SubscriptionUtils::delete_meta( $product );
				foreach ( SubscriptionUtils::get_fields() as $field ) {
					unset( $meta_data[ $field ] );
				}
			}
		}

		unset( $meta_data['images'] ); // 圖片只做顯示用，不用存

		// 將 teacher_ids 分離出來，因為要單獨處理，不是直接存 serialized array 進 db
		$array_keys = [ 'teacher_ids' ];
		foreach ( $array_keys as $meta_key ) {

			$array_value = [];
			if ( isset( $meta_data[ $meta_key ] ) ) {
				$array_value = $meta_data[ $meta_key ];
				unset( $meta_data[ $meta_key ] );
			}

			if ( \is_array( $array_value ) ) {
				// 先刪除現有的 teacher_ids
				$product->delete_meta_data( $meta_key );

				/** @var array<string, array<mixed>|string> $array_value */
				foreach ( $array_value as $meta_value ) {
					$product->add_meta_data( $meta_key, $meta_value );
				}
			}
		}

		\do_action( LifeCycle::BEFORE_UPDATE_PRODUCT_META_ACTION, $product, $meta_data );

		// 最後再來處理剩餘的 meta_data
		foreach ( $meta_data as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		$product->update_meta_data( '_' . AdminProduct::PRODUCT_OPTION_NAME, 'yes' );

		$product->save_meta_data();

		$id = $product->get_id();

		// 根據產品類型設定正確的 product_type taxonomy
		if ( $is_external ) {
			$product_type_term = 'external';
		} elseif ( $is_subscription ) {
			$product_type_term = 'subscription';
		} else {
			$product_type_term = 'simple';
		}

		$result = \wp_set_object_terms( $id, $product_type_term, 'product_type' );
		\wc_delete_product_transients( $id );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * 驗證外部課程的 product_url
	 *
	 * 驗證規則：
	 * 1. 不可為空
	 * 2. 必須以 http:// 或 https:// 開頭
	 * 3. 必須通過 filter_var FILTER_VALIDATE_URL 驗證
	 * 4. 排除 javascript: 等非法協定
	 *
	 * @param string $url 要驗證的 URL
	 * @return \WP_Error|true
	 */
	private function validate_product_url( string $url ): \WP_Error|bool {
		if ( '' === $url ) {
			return new \WP_Error(
				'product_url_required',
				'外部課程的 product_url 為必填',
				[ 'status' => 400 ]
			);
		}

		// 必須以 http:// 或 https:// 開頭
		if ( ! \str_starts_with( $url, 'http://' ) && ! \str_starts_with( $url, 'https://' ) ) {
			return new \WP_Error(
				'product_url_invalid',
				'product_url 必須以 http:// 或 https:// 開頭',
				[ 'status' => 400 ]
			);
		}

		// 使用 filter_var 驗證 URL 格式
		if ( false === \filter_var( $url, \FILTER_VALIDATE_URL ) ) {
			return new \WP_Error(
				'product_url_invalid',
				'product_url 格式不合法，請提供完整的 URL',
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Post courses with id callback
	 * 更新課程
	 *
	 * @param \WP_REST_Request<array{'id': string}> $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function post_courses_with_id_callback( \WP_REST_Request $request ):\WP_REST_Response|\WP_Error { // phpcs:ignore
		/** @var array<string, array<mixed>|string> $meta_data */
		[
			'product' => $product,
			'data'      => $data,
			'meta_data' => $meta_data,
		] = $this->separator($request);

		$this->handle_save_course_data($product, $data );
		$result = $this->handle_save_course_meta_data($product, $meta_data );

		if (true !== $result ) {
			return $result;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'update_success',
				'message' => '更新成功',
				'data'    => [
					'id' => $product->get_id(),
				],
			]
		);
	}



	/**
	 * 刪除課程
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 當刪除課程資料失敗時拋出異常
	 */
	public function delete_courses_callback( $request ) {
		$body_params = $request->get_json_params();

		$body_params = WP::sanitize_text_field_deep( $body_params, false );

		// @phpstan-ignore-next-line
		$ids = @$body_params['ids'];
		$ids = is_array( $ids ) ? $ids : [];

		foreach ($ids as $id) {
			/** @var string $id */
			$result = \wp_delete_post( (int) $id, true );
			if (!$result) {
				throw new \Exception(__('刪除課程資料失敗', 'power-course') . " #{$id}");
			}
		}

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => '刪除成功',
				'data'    => $ids,
			]
		);
	}


	/**
	 * Delete courses with id callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete_courses_with_id_callback( $request ) {
		$id = (int) $request['id'];
		if ( empty( $id ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'id_not_provided',
					'message' => '刪除失敗，請提供ID',
					'data'    => null,
				],
				400
			);
		}

		\wp_delete_post( $id, true );

		return new \WP_REST_Response(
			[
				'code'    => 'delete_success',
				'message' => '刪除成功',
				'data'    => [
					'id' => $id,
				],
			]
		);
	}

	/**
	 * Get terms callback
	 *
	 * @see https://developer.wordpress.org/reference/functions/get_terms/
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array
	 * @phpstan-ignore-next-line
	 */
	public function get_courses_terms_callback( $request ) { // phpcs:ignore

		$params = $request->get_query_params();

		$params = WP::sanitize_text_field_deep( $params, false );

		$formatted_terms = is_array($params) ? Product::format_terms( $params ) : [];

		return $formatted_terms;
	}

	/**
	 * Get options callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array
	 * @phpstan-ignore-next-line
	 */
	public function get_courses_options_callback( $request ) { // phpcs:ignore
		$formatted_cats = Product::format_terms();
		$formatted_tags = Product::format_terms(
			[
				'taxonomy' => 'product_tag',
			]
			);

		$top_sales_products = CourseUtils::get_top_sales_courses( 5 );

		[
			'max_price' => $max_price,
			'min_price' => $min_price,
		] = Product::get_max_min_prices();

		return [
			'product_cats'       => $formatted_cats,
			'product_tags'       => $formatted_tags,
			'top_sales_products' => $top_sales_products,
			'max_price'          => (int) $max_price,
			'min_price'          => (int) $min_price,
		];
	}
}
