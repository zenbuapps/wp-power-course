<?php

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Admin\Product as AdminProduct;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\WC;
use J7\PowerCourse\BundleProduct\Helper;
use J7\WpUtils\Classes\WC\Product as WcProduct;
use J7\PowerCourse\Resources\Course\Limit;
use J7\PowerCourse\Resources\Course\BindCoursesData;
use J7\Powerhouse\Domains\Product\Utils\CRUD;
use J7\PowerCourse\Utils\Subscription as SubscriptionUtils;

/** Product API */
final class Product {
	use \J7\WpUtils\Traits\SingletonTrait;
	use \J7\WpUtils\Traits\ApiRegisterTrait;

	/**
	 * APIs
	 *
	 * @var array<int, array{endpoint: string, method: string, permission_callback?: callable}>
	 */
	protected array $apis = [
		[
			'endpoint' => 'products',
			'method'   => 'get',
		],
		[
			'endpoint' => 'products/select',
			'method'   => 'get',
		],
		[
			'endpoint' => 'products',
			'method'   => 'post',
		],
		[
			'endpoint' => 'products/bind-courses',
			'method'   => 'post',
		],
		[
			'endpoint' => 'products/unbind-courses',
			'method'   => 'post',
		],
		[
			'endpoint' => 'products/update-bound-courses',
			'method'   => 'post',
		],
		[
			'endpoint' => 'bundle_products',
			'method'   => 'get',
		],
		[
			'endpoint' => 'bundle_products',
			'method'   => 'post',
		],
		[ // TODO 有需要這個嗎? 要檢查一下
			'endpoint' => 'bundle_products/(?P<id>\d+)',
			'method'   => 'post',
		],
		[
			'endpoint' => 'bundle_products/sort',
			'method'   => 'post',
		],
		[
			'endpoint' => 'bundle_products/(?P<id>\d+)',
			'method'   => 'delete',
		],
		[
			'endpoint' => 'products/options',
			'method'   => 'get',
		],
		[
			'endpoint' => 'products/(?P<id>\d+)',
			'method'   => 'delete',
		],
	];

	/**
	 * Constructor.
	 */
	public function __construct() {
		\add_action( 'rest_api_init', [ $this, 'register_api_products' ] );

		\add_filter(
			'woocommerce_product_data_store_cpt_get_products_query',
			function ( $query, $query_vars ) {
				if ( isset($query_vars['is_course']) ) {
					$query['meta_query'][] = [
						'key'   => '_is_course',
						'value' => $query_vars['is_course'] ? 'yes' : 'no',
					];
				}

				if ( isset($query_vars[ Helper::LINK_COURSE_IDS_META_KEY ]) ) {
					$query['meta_query'][] = [
						'key'   => Helper::LINK_COURSE_IDS_META_KEY,
						'value' => $query_vars[ Helper::LINK_COURSE_IDS_META_KEY ],
					];
				}
				return $query;
			},
			10,
			2,
		);
	}

	/**
	 * Register products API
	 *
	 * @return void
	 */
	public function register_api_products(): void {
		$this->register_apis(
			$this->apis,
			Plugin::$kebab,
			fn() => \current_user_can( 'manage_woocommerce' ),
		);
	}


	/**
	 * Get products callback
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_products_callback( $request ) { // phpcs:ignore

		$params = $request->get_query_params();

		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'status'         => [ 'publish', 'draft' ],
			'paginate'       => true,
			'posts_per_page' => 10,
			'paged'          => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		if ( isset( $args['price_range'] ) ) {
			$args['meta_query']             = [];
			$args['meta_query']['relation'] = 'AND';
			$args['meta_query'][]           = [
				'key'     => '_price', // 價格自定義欄位
				'value'   => $args['price_range'], // 設定價格範圍
				'compare' => 'BETWEEN', // 在此範圍之間
				'type'    => 'DECIMAL', // 處理為數值
			];
			unset( $args['price_range'] );
		}

		/** @var \stdClass $results */
		$results = \wc_get_products( $args );

		/** @var int $total */
		$total       = $results->total;
		/** @var int $total_pages */
		$total_pages = $results->max_num_pages;

		/** @var array<\WC_Product> $products_list */
		$products_list = $results->products;
		$products = $products_list;

		$formatted_products = array_values(array_map( [ $this, 'format_product_details' ], $products ));

		$response = new \WP_REST_Response( $formatted_products );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}


	/**
	 * Get products tree select callback
	 * 用 WP Query 而不是 wc_get_products
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_products_select_callback( $request ) { // phpcs:ignore

		$params = $request->get_query_params();

		/** @var array<string, mixed> $params */
		$params = WP::sanitize_text_field_deep( $params, false );

		$default_args = [
			'post_status'    => [ 'publish' ],
			'post_type'      => 'product',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'fields'         => 'ids',
		];

		$args = \wp_parse_args(
			$params,
			$default_args,
		);

		$results = new \WP_Query( $args );

		$total       = $results->found_posts;
		$total_pages = $results->max_num_pages;

		$product_ids        = $results->posts;
		$products           = array_map(fn( $product_id ) => \wc_get_product( $product_id ), $product_ids);
		$products           = array_filter($products);
		$formatted_products = array_values(array_map( [ $this, 'format_select' ], $products ));

		$response = new \WP_REST_Response( $formatted_products );

		// set pagination in header
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $total_pages );

		return $response;
	}

	/**
	 * 批次創建或修改商品
	 * 創建要帶 qty
	 * 修改要帶 ids
	 * form-data 方式送出
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_products_callback( $request ) {
		$body_params = $request->get_body_params();
		$file_params = $request->get_file_params();

		WP::include_required_params( $body_params, [ 'action' ] );

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array<int> $ids */
		$ids = $body_params['ids'] ?? []; // 修改才需要 ids
		unset( $body_params['ids'] );
		$qty = (int) ( $body_params['qty'] ?? 0 ); // 創建才需要 qty
		unset( $body_params['qty'] );
		/** @var string $action */
		$action = $body_params['action'];
		unset( $body_params['action'] );

		[
			'data' => $data,
			'meta_data' => $meta_data,
			] = WP::separator( $body_params, 'product', $file_params['files'] ?? [] );

		$std_response = match ($action) {
			'update' => WcProduct::multi_update( $ids, $data, $meta_data ),
			'create' => WcProduct::multi_create( $qty, (string) ( $data['product_type'] ?? 'simple' ) ),
			default => [
				'code'    => 'post_failed',
				'message' => '修改失敗，未知的 action',
				'data'    => [
					'action' => $action,
				],
			],
		};

		return new \WP_REST_Response(
			$std_response,
			$std_response['code'] === 'success' ? 200 : 400
		);
	}


	/**
	 * 綁定課程到商品上
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_products_bind_courses_callback( $request ) {
		$body_params = $request->get_body_params();

		WP::include_required_params( $body_params, [ 'product_ids', 'course_ids', 'limit_type' ] );

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array<int|string> $product_ids */
		$product_ids = $body_params['product_ids'] ?? [];
		/** @var array<int|string> $course_ids */
		$course_ids  = $body_params['course_ids'] ?? [];
		$limit       = new Limit( (string) ( $body_params['limit_type'] ?? '' ), (int) ( $body_params['limit_value'] ?? 0 ), isset( $body_params['limit_unit'] ) ? (string) $body_params['limit_unit'] : null );

		$success_ids = [];
		$failed_ids  = [];
		foreach ($product_ids as $product_id) {
			$result = WcProduct::add_meta_array( (int) $product_id, 'bind_course_ids', $course_ids );
			if (\is_wp_error($result)) {
				$failed_ids[] = $product_id;
				continue;
			}

			$bind_courses_data_instance = BindCoursesData::instance( (int) $product_id );

			foreach ($course_ids as $course_id) {
				$bind_courses_data_instance->add_course_data(
					(int) $course_id,
					$limit
				);
			}

			$bind_courses_data_instance->save();

			$success_ids[] = $product_id;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '綁定成功',
				'data'    => [
					'success_ids' => $success_ids,
					'failed_ids'  => $failed_ids,
					'course_ids'  => $course_ids,
				],
			],
			200
		);
	}


	/**
	 * 更新已綁定課程觀看權限到商品上
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_products_update_bound_courses_callback( $request ) {
		$body_params = $request->get_body_params();

		WP::include_required_params( $body_params, [ 'product_ids', 'course_ids', 'limit_type' ] );

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array<int|string> $product_ids */
		$product_ids = $body_params['product_ids'] ?? [];
		/** @var array<int|string> $course_ids */
		$course_ids  = $body_params['course_ids'] ?? [];

		$success_ids = [];
		$failed_ids  = [];
		foreach ($product_ids as $product_id) {

			$bind_courses_data_instance = BindCoursesData::instance( (int) $product_id);
			foreach ($course_ids as $course_id) {
				$limit = new Limit( (string) ( $body_params['limit_type'] ?? '' ), (int) ( $body_params['limit_value'] ?? 0 ), isset( $body_params['limit_unit'] ) ? (string) $body_params['limit_unit'] : null );
				$bind_courses_data_instance->update_course_data( (int) $course_id, $limit );
			}
			$bind_courses_data_instance->save();
			$success_ids[] = $product_id;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '修改成功',
				'data'    => [
					'success_ids' => $success_ids,
					'failed_ids'  => $failed_ids,
					'course_ids'  => $course_ids,
				],
			],
			200
		);
	}


	/**
	 * 解除綁定課程到商品上
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_products_unbind_courses_callback( $request ) {
		$body_params = $request->get_body_params();

		WP::include_required_params( $body_params, [ 'product_ids', 'course_ids' ] );

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array<int|string> $product_ids */
		$product_ids = $body_params['product_ids'] ?? [];
		/** @var array<int|string> $course_ids */
		$course_ids  = $body_params['course_ids'] ?? [];

		$success_ids = [];
		$failed_ids  = [];
		foreach ($product_ids as $product_id) {
			/** @var array<mixed> $original_course_ids */
			$original_course_ids = \get_post_meta( (int) $product_id, 'bind_course_ids' ) ?: [];
			/** @var array<string> $new_course_ids */
			$new_course_ids      = \array_filter( $original_course_ids, fn( $original_course_id ) => ! \in_array( $original_course_id, $course_ids ) );

			$result = WcProduct::update_meta_array( (int) $product_id, 'bind_course_ids', $new_course_ids );
			if (\is_wp_error($result)) {
				$failed_ids[] = $product_id;
				continue;
			}

			$bind_courses_data_instance = BindCoursesData::instance( (int) $product_id );
			foreach ($course_ids as $course_id) {
				$bind_courses_data_instance->remove_course_data( (int) $course_id );
			}
			$bind_courses_data_instance->save();
			$success_ids[] = $product_id;
		}

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '解除綁定成功',
				'data'    => [
					'success_ids' => $success_ids,
					'failed_ids'  => $failed_ids,
					'course_ids'  => $course_ids,
				],
			],
			200
		);
	}

	/**
	 * Format product details
	 *
	 * @see https://www.businessbloomer.com/woocommerce-easily-get-product-info-title-sku-desc-product-object/
	 *
	 * @param \WC_Product $product Product.
	 * @param bool        $with_description With description.
	 * @return array<string, mixed>
	 */
	public function format_product_details( \WC_Product $product , $with_description = true) { // phpcs:ignore
		$product_id    = $product->get_id();
		$date_created  = $product->get_date_created();
		$date_modified = $product->get_date_modified();

		$image_id          = $product->get_image_id();
		$gallery_image_ids = $product->get_gallery_image_ids();

		$image_ids = [ $image_id, ...$gallery_image_ids ];
		$images    = array_filter(array_map( [ WP::class, 'get_image_info' ], $image_ids ));

		$description_array = $with_description ? [
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
		] : [];

		$low_stock_amount = ( '' === $product->get_low_stock_amount() ) ? null : $product->get_low_stock_amount();

		$variation_ids = $product->get_children(); // get variations
		$children      = [];
		if ( ! empty( $variation_ids ) ) {
			$variation_products = array_map( 'wc_get_product', $variation_ids );
			$variation_products = array_filter($variation_products);
			$children_details   = array_values(array_map( [ $this, 'format_product_details' ], $variation_products ));
			$children           = [
				'children'  => $children_details,
				'parent_id' => (string) $product_id,
			];
		}

		$attributes = $product->get_attributes(); // get attributes object

		$attributes_arr = [];

		foreach ( $attributes as $key => $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute ) {
				$attributes_arr[] = [
					'name'     => \wc_attribute_label( $attribute->get_name() ),
					'options'  => $attribute->get_options(),
					'position' => $attribute->get_position(),
				];
			}

			if ( is_string( $key ) && is_string( $attribute ) ) {
				$attributes_arr[ urldecode( $key ) ] = $attribute;
			}
		}

		$price_html = CRUD::get_price_html( $product );

		$product_id = $product->get_id();

		$bind_courses_data_instance  = BindCoursesData::instance( (int) $product_id );
		$formatted_bind_courses_data = $bind_courses_data_instance->get_data(ARRAY_N);

		$sale_date_range = [ (int) $product->get_date_on_sale_from()?->getTimestamp(), (int) $product->get_date_on_sale_to()?->getTimestamp() ];

		$helper     = Helper::instance( $product );
		$permalink  = ( $helper !== null && $helper->link_course_id ) ? \get_permalink( $helper->link_course_id ) : \get_permalink( $product_id );
		$base_array = [
			// Get Product General Info
			'id'                                 => (string) $product_id,
			'type'                               => $product->get_type(),
			'name'                               => $product->get_name(),
			'slug'                               => $product->get_slug(),
			'date_created'                       => $date_created?->date( 'Y-m-d H:i:s' ),
			'date_modified'                      => $date_modified?->date( 'Y-m-d H:i:s' ),
			'status'                             => $product->get_status(),
			'featured'                           => $product->get_featured(),
			'catalog_visibility'                 => $product->get_catalog_visibility(),
			'sku'                                => $product->get_sku(),
			// 'menu_order'         => $product?->get_menu_order(),
			'virtual'                            => $product->get_virtual(),
			'downloadable'                       => $product->get_downloadable(),
			'permalink'                          => $permalink,

			// Get Product Prices
			'price_html'                         => $price_html,
			'regular_price'                      => $product->get_regular_price(),
			'sale_price'                         => $product->get_sale_price(),
			'on_sale'                            => $product->is_on_sale(),
			'sale_date_range'                    => $sale_date_range,
			'date_on_sale_from'                  => $sale_date_range[0],
			'date_on_sale_to'                    => $sale_date_range[1],
			'total_sales'                        => $product->get_total_sales(),

			// Get Product Stock
			'stock'                              => $product->get_stock_quantity(),
			'stock_status'                       => $product->get_stock_status(),
			'manage_stock'                       => \wc_bool_to_string( $product->get_manage_stock() ),
			'stock_quantity'                     => $product->get_stock_quantity(),
			'backorders'                         => $product->get_backorders(),
			'backorders_allowed'                 => \wc_bool_to_string( $product->backorders_allowed() ),
			'backordered'                        => \wc_bool_to_string( $product->is_on_backorder() ),
			'low_stock_amount'                   => $low_stock_amount,
			'sold_individually'                  => \wc_bool_to_string( $product->is_sold_individually() ),

			// Get Linked Products
			'upsell_ids'                         => array_map( 'strval', $product->get_upsell_ids() ),
			'cross_sell_ids'                     => array_map( 'strval', $product->get_cross_sell_ids() ),

			// Get Product Variations and Attributes
			'attributes'                         => $attributes_arr,

			// Get Product Taxonomies
			'categories'                         => self::format_terms(
				[
					'taxonomy'   => 'product_cat',
					'object_ids' => $product_id,
				]
				),
			'tags'                               => self::format_terms(
				[
					'taxonomy'   => 'product_tag',
					'object_ids' => $product_id,
				]
				),

			// Get Product Images
			'images'                             => $images,

			'is_course'                          => $product->get_meta( '_' . AdminProduct::PRODUCT_OPTION_NAME ),
			'parent_id'                          => (string) $product->get_parent_id(),

			// Bundle 商品包含的商品 ids
			Helper::INCLUDE_PRODUCT_IDS_META_KEY => ( $helper !== null ? $helper->get_product_ids() : [] ),

			'is_free'                            => (string) $product->get_meta( 'is_free' ),
			'qa_list'                            => [],
			'bundle_type_label'                  => (string) $product->get_meta( 'bundle_type_label' ),
			'exclude_main_course'                => (string) $product->get_meta( 'exclude_main_course' ) ?: 'no',
			'bind_courses_data'                  => $formatted_bind_courses_data,
			Helper::LINK_COURSE_IDS_META_KEY     => $product->get_meta( Helper::LINK_COURSE_IDS_META_KEY ),

			'bundle_type'                        => (string) $product->get_meta( 'bundle_type' ),
		] + SubscriptionUtils::get_normalized_meta( $product ) + [
			'purchase_note'                      => $product->get_purchase_note(),

		] + $children;

		return array_merge(
			$description_array,
			$base_array
		);
	}


	/**
	 * Format product Select
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, mixed>
	 */
	public function format_select( \WC_Product $product) { // phpcs:ignore

		// 取出銷售方案
		// $bundles  =Helper::get_bundle_products( $product->get_id());
		$product_id = $product->get_id();

		$base_array = [
			// Get Product General Info
			'id'        => (string) $product_id,
			'type'      => $product->get_type(),
			'name'      => $product->get_name(),
			'slug'      => $product->get_slug(),
			'permalink' => \get_permalink( $product_id ),
			'is_course' => $product->get_meta( '_' . AdminProduct::PRODUCT_OPTION_NAME ) === 'yes',
		];

		return $base_array;
	}

	/**
	 * Get bundle products callback
	 * 目前跟 get_products_callback 行為一樣
	 * 只是標示為相同資源，方便前端 invalidate 資源
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_bundle_products_callback( $request ) { // phpcs:ignore
		return $this->get_products_callback( $request );
	}

	/**
	 * Post bundle product callback (bundle product)
	 * 新增 bundle product
	 * 用 form-data 方式送出
	 *
	 * @see https://rudrastyh.com/woocommerce/create-product-programmatically.html
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_bundle_products_callback( $request ) {

		$body_params = $request->get_body_params();
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		$file_params = $request->get_file_params();

		[
			'data' => $data,
			'meta_data' => $meta_data,
			] = WP::separator( $body_params, 'product', $file_params['files'] ?? [] );

		$product = new \WC_Product_Simple();

		$data['catalog_visibility'] = 'hidden'; // 新增 bundle product 預設為 "不可見"
		foreach ( $data as $key => $value ) {
			$method_name = 'set_' . $key;
			$product->$method_name( $value );
		}

		$product->save();

		$meta_data = self::handle_special_fields( $meta_data, $product );

		foreach ( $meta_data as $key => $value ) {
			/** @var string|array<mixed> $value */
			$product->update_meta_data( $key, $value );
		}

		$product->save_meta_data();

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
	 * 排序
	 *
	 * @see https://rudrastyh.com/woocommerce/create-product-programmatically.html
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_bundle_products_sort_callback( $request ) {
		$body_params             = $request->get_json_params();
		/** @var array<string, mixed> $body_params */
		$body_params             = WP::sanitize_text_field_deep( $body_params );
		WP::include_required_params( $body_params, [ 'sort_list' ] );

		/**
		 * @var array<int, array{id: int, menu_order: int}>
		 */
		$sort_list = $body_params['sort_list'] ?? [];

		$success_ids = [];
		$failed_ids  = [];
		foreach ($sort_list as $sort_item) {
			$id         = $sort_item['id'];
			$menu_order = $sort_item['menu_order'];
			$result     = \wp_update_post(
				[
					'ID'         => $id,
					'menu_order' => $menu_order,
				]
				);
			if ($result > 0) {
				$success_ids[] = $id;
			} else {
				$failed_ids[] = $id;
			}
		}

		return new \WP_REST_Response(
			[
				'code'    => 'sort_success',
				'message' => '排序成功',
				'data'    => [
					'success_ids' => $success_ids,
					'failed_ids'  => $failed_ids,
				],
			],
		);
	}

	/**
	 * Patch bundle product callback (bundle product)
	 * 修改 bundle product
	 * 用 form-data 方式送出
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_bundle_products_with_id_callback( $request ) {
		$id          = (int) $request['id'];
		$body_params = $request->get_body_params();
		$file_params = $request->get_file_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		$product = \wc_get_product( $id );
		if ( ! $product ) {
			return new \WP_REST_Response(
				[
					'code'    => 'patch_failed',
					'message' => '修改失敗，找不到商品',
					'data'    => [
						'id' => (string) $id,
					],
				],
				400
			);
		}
		// $product = new BundleProduct( $product );

		[
			'data' => $data,
			'meta_data' => $meta_data,
			] = WP::separator( $body_params, 'product', $file_params['files'] ?? [] );

		if (isset($meta_data['images'])) {
			$gallery_image_ids = [];
			$images            = $meta_data['images'];
			$images            = is_array($images) ? $images : [];
			foreach ($images as $index => $image) {
				/** @var array<string, mixed> $image */
				$image_id = $image['id'] ?? null;
				if (!$image_id) {
					continue;
				}
				if ($index === 0) {
					$product->set_image_id( (string) $image_id);
				}
				$gallery_image_ids[] = $image_id;
			}
			$product->set_gallery_image_ids($gallery_image_ids);
			unset($meta_data['images']);
		}

		// type 會被儲存為商品的類型，不需要再額外存進 meta data
		$is_subscription = 'subscription' === $meta_data['type'];
		unset($meta_data['type']);

		if ($is_subscription) {
			$validation = SubscriptionUtils::validate_class();
			if (\is_wp_error( $validation )) {
				return new \WP_REST_Response(
					[
						'code'    => $validation->get_error_code(),
						'message' => $validation->get_error_message(),
					],
					400
				);
			}
		}

		foreach ( $data as $key => $value ) {
			$method_name = 'set_' . $key;
			$product->$method_name( $value );
		}

		// 如果是非訂閱商品，則刪除訂閱商品的相關資料，並同步從 $meta_data 移除避免後續 update loop 又寫回
		if (!$is_subscription) {
			SubscriptionUtils::delete_meta( $product );
			foreach ( SubscriptionUtils::get_fields() as $field ) {
				unset( $meta_data[ $field ] );
			}
		}

		$product->save();

		$meta_data = self::handle_special_fields( $meta_data, $product );

		foreach ( $meta_data as $key => $value ) {
			/** @var string|array<mixed> $value */
			$product->update_meta_data( $key, $value );
		}

		$product->save_meta_data();

		$result = \wp_set_object_terms( $id, $is_subscription ? 'subscription' : 'simple', 'product_type');
		\wc_delete_product_transients( $id );
		if (\is_wp_error($result)) {
			return new \WP_REST_Response(
				[
					'code'    => 'patch_failed',
					'message' => $result->get_error_message(),
				],
				400
			);
		}

		return new \WP_REST_Response(
			[
				'code'    => 'patch_success',
				'message' => '修改成功',
				'data'    => [
					'id' => (string) $id,
				],
			]
		);
	}


	/**
	 * Delete bundle product with id callback
	 * 目前跟 delete_products_with_id_callback 行為一樣
	 * 只是標示為相同資源，方便前端 invalidate 資源
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete_bundle_products_with_id_callback( $request ) {
		return $this->delete_products_with_id_callback( $request );
	}


	/**
	 * Delete product with id callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function delete_products_with_id_callback( $request ) {
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
	 * 針對特殊欄位處理
	 * 例如要儲存成 array 的 meta data，或是要略過的 meta data
	 *
	 * @param array<string, mixed> $meta_data Meta data.
	 * @param \WC_Product          $product Product.
	 * @return array<string, mixed>
	 */
	public static function handle_special_fields( array $meta_data, \WC_Product $product ) {
		$update_array_meta_keys = [
			Helper::INCLUDE_PRODUCT_IDS_META_KEY,
			Helper::LINK_COURSE_IDS_META_KEY, // 用來表示 bundle product 連結的課程
		];
		$add_array_meta_keys    = [
			'bind_course_ids', // 綁定的課程
		];
		$unset_meta_keys        = [
			'product_type', // product_type 就不用處理了，前端會帶
		];

		$product_id = $product->get_id();

		// 直接更新 array 的 meta data
		foreach ($update_array_meta_keys as $meta_key) {
			if ( isset( $meta_data[ $meta_key ] ) && is_array( $meta_data[ $meta_key ] ) ) {
				// 先刪除原本的 meta data
				/** @var array<string> $meta_values */
				$meta_values = $meta_data[ $meta_key ];
				WcProduct::update_meta_array( $product_id, $meta_key, $meta_values );
				unset( $meta_data[ $meta_key ] );
			}
		}

		// 添加 array 的 meta data
		foreach ($add_array_meta_keys as $meta_key) {
			if ( isset( $meta_data[ $meta_key ] ) && is_array( $meta_data[ $meta_key ] ) ) {
				// 先刪除原本的 meta data
				/** @var array<int|string> $meta_values */
				$meta_values = $meta_data[ $meta_key ];
				WcProduct::add_meta_array( $product_id, $meta_key, $meta_values );
				unset( $meta_data[ $meta_key ] );

				$bind_courses_data_instance = BindCoursesData::instance( $product_id );
				foreach ($meta_values as $course_id) {
					$limit = Limit::instance( (int) $course_id );
					$bind_courses_data_instance->add_course_data( (int) $course_id, $limit );
				}
				$bind_courses_data_instance->save();
			}
		}

		// 刪除不需要的 meta data
		foreach ($unset_meta_keys as $meta_key) {
			if ( isset( $meta_data[ $meta_key ] ) ) {
				unset( $meta_data[ $meta_key ] );
			}
		}

		return $meta_data;
	}

	/**
	 * Format terms
	 *
	 * @param array<string, mixed> $params Params.
	 *
	 * @return list<array{id: string, name: string}>
	 */
	public static function format_terms( array $params = [] ): array {
		// it seems no need to add post_per_page, get_terms will return all terms
		$default_args = [
			'taxonomy'   => 'product_cat',
			'fields'     => 'id=>name',
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		];

		$args = \wp_parse_args(
				$params,
				$default_args,
			);

		$terms = \get_terms( $args );

		if ( !\is_array( $terms ) ) {
			return [];
		}

		/** @var array<int|string, string> $terms */
		$formatted_terms = array_map(
				fn( $key, $value ) => [
					'id'   => (string) $key,
					'name' => (string) $value,
				],
				array_keys( $terms ),
				array_values( $terms )
				);

		return $formatted_terms;
	}

	/**
	 * Get Max Min Price
	 *
	 * @return array{max_price:int, min_price:int}
	 */
	public static function get_max_min_prices(): array {
		$transient_key = 'max_min_prices';

		$max_min_prices = \get_transient( $transient_key );

		if ( is_array( $max_min_prices ) && isset( $max_min_prices['max_price'], $max_min_prices['min_price'] ) ) {
			/** @var array{max_price: int, min_price: int} $max_min_prices */
			return $max_min_prices;
		}
		// 獲取最高價格的商品
		$max_price_products = \wc_get_products(
			[
				'order'    => 'DESC', // 遞減排序
				'orderby'  => 'meta_value_num',
				'meta_key' => '_price',
				'limit'    => 1,         // 僅獲取一個結果
				'status'   => 'publish', // 僅包含已發佈的商品
			]
		);
		$max_price          = 0;
		if ( ! empty( $max_price_products ) ) {
			$max_price_product = reset( $max_price_products );     // 獲取第一個元素
			$max_price         = (int) ( $max_price_product instanceof \WC_Product ? $max_price_product->get_price() : 0 ); // 獲取最高價格
		}

		// 獲取最低價格的商品
		$min_price_products = \wc_get_products(
			[
				'order'    => 'ASC', // 遞增排序
				'orderby'  => 'meta_value_num',
				'meta_key' => '_price',
				'limit'    => 1,         // 僅獲取一個結果
				'status'   => 'publish', // 僅包含已發佈的商品
			]
		);

		$min_price = 0;
		if ( ! empty( $min_price_products ) ) {
			$min_price_product = reset( $min_price_products );     // 獲取第一個元素
			$min_price         = (int) ( $min_price_product instanceof \WC_Product ? $min_price_product->get_price() : 0 ); // 獲取最低價格
		}

		$max_min_prices = [
			'max_price' => $max_price,
			'min_price' => $min_price,
		];

		// @phpstan-ignore-next-line
		\set_transient( $transient_key, $max_min_prices, 1 * HOUR_IN_SECONDS );

		return $max_min_prices;
	}

	/**
	 * Get options callback
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return array<string, mixed>
	 */
	public function get_products_options_callback( $request ) { // phpcs:ignore
		$formatted_cats = self::format_terms();
		$formatted_tags = self::format_terms(
			[
				'taxonomy' => 'product_tag',
			]
			);

		$top_sales_products = WC::get_top_sales_products( 5 );

		[
			'max_price' => $max_price,
			'min_price' => $min_price,
		] = self::get_max_min_prices();

		return [
			'product_cats'       => $formatted_cats,
			'product_tags'       => $formatted_tags,
			'top_sales_products' => $top_sales_products,
			'max_price'          => (int) $max_price,
			'min_price'          => (int) $min_price,
		];
	}
}
