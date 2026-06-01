<?php

declare(strict_types=1);

namespace J7\PowerCourse\Api;

use J7\WpUtils\Classes\WP;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\File;
use J7\WpUtils\Classes\UniqueArray;
use J7\PowerCourse\Resources\Course\Service\AddStudent;

/** Class Api */
final class User extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	const BATCH_SIZE = 50;

	/** @var string Namespace */
	protected $namespace = 'power-course';

	/**
	 * APIs
	 *
	 * @var array{endpoint: string, method: string, permission_callback: ?callable}[]
	 * - endpoint: string
	 * - method: 'get' | 'post' | 'patch' | 'delete'
	 * - permission_callback : callable
	 */
	protected $apis = [
		[
			'endpoint'            => 'users',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_manage_woocommerce_permission' ],
		],
		[
			// 角色下拉選項來源，放在 users/(?P<id>\d+) 之前以清楚表意（options 非數字不會被 \d+ 吃掉）。
			'endpoint'            => 'users/options',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			'endpoint'            => 'users/(?P<id>\d+)',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			'endpoint'            => 'users/(?P<id>\d+)',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			'endpoint'            => 'users/(?P<id>\d+)/reset-password',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			'endpoint'            => 'users/(?P<id>\d+)/orders-summary',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			// 聯絡註記（contact_remark）列表，query: commented_user_id
			'endpoint'            => 'comments',
			'method'              => 'get',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			// 新增聯絡註記
			'endpoint'            => 'comments',
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			// 刪除聯絡註記（僅限 contact_remark 類型）
			'endpoint'            => 'comments/(?P<id>\d+)',
			'method'              => 'delete',
			'permission_callback' => [ self::class, 'check_edit_users_permission' ],
		],
		[
			'endpoint'            => 'users/add-teachers', // 設定為講師
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_manage_woocommerce_permission' ],
		],
		[
			'endpoint'            => 'users/remove-teachers', // 解除講師身分
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_manage_woocommerce_permission' ],
		],
		[
			'endpoint'            => 'users/upload-students', // CSV 新增學員
			'method'              => 'post',
			'permission_callback' => [ self::class, 'check_manage_woocommerce_permission' ],
		],
	];

	/** Constructor*/
	public function __construct() {
		parent::__construct();
		\add_action( 'pc_batch_add_students_task', [ $this, 'process_batch_add_students' ], 10, 4 );
	}

	/**
	 * 權限檢查：要求 manage_woocommerce 能力
	 *
	 * 統一所有 User API endpoint 的權限檢查。未登入回 401；
	 * 已登入但無權限回 403。
	 *
	 * @return bool|\WP_Error true 代表有權限；WP_Error 代表拒絕（含 HTTP status）。
	 */
	public static function check_manage_woocommerce_permission() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'power-course' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! \current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * 權限檢查：要求 edit_users 能力
	 *
	 * 學員快速編輯相關 endpoint 的權限守門（取得 / 更新 / 重設密碼 / 訂單摘要）。
	 * 未登入回 401；已登入但無 edit_users 能力回 403。
	 *
	 * @return bool|\WP_Error true 代表有權限；WP_Error 代表拒絕（含 HTTP status）。
	 */
	public static function check_edit_users_permission() {
		if ( ! \is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'You are not currently logged in.', 'power-course' ),
				[ 'status' => 401 ]
			);
		}

		if ( ! \current_user_can( 'edit_users' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to do that.', 'power-course' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Log 到 WC Logger 的指定檔名
	 *
	 * @param string               $message 訊息
	 * @param string               $level 等級
	 * @param array<string, mixed> $args 參數
	 * @return void
	 */
	protected static function log( string $message, $level = 'info', array $args = [] ): void {
		\J7\WpUtils\Classes\WC::logger(
			$message,
			$level,
			$args,
			'power_course_csv_upload_students'
			);
	}

	/**
	 * 新增用戶
	 *
	 * @param \WP_REST_Request $request 包含新增用戶所需資料的REST請求對象。
	 * @return \WP_REST_Response 返回包含操作結果的REST響應對象。成功時返回用戶資料，失敗時返回錯誤訊息。
	 */
	public function post_users_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array{data: array<string, mixed>, meta_data: array<string, mixed>} $separated */
		$separated = WP::separator( $body_params, 'user' );
		/** @var array{ID?: int, user_pass?: string, user_login?: string, user_nicename?: string, user_url?: string, user_email?: string, display_name?: string, nickname?: string} $data */
		$data      = $separated['data'];
		/** @var array<string, mixed> $meta_data */
		$meta_data = $separated['meta_data'];

		$user_id = \wp_insert_user( $data );

		if (\is_wp_error($user_id)) {
			return new \WP_REST_Response(
			[
				'code'    => 'create_user_error',
				'message' => $user_id->get_error_message(),
				'data'    => null,
			],
			400
			);
		}

		foreach ( $meta_data as $key => $value ) {
			\update_user_meta($user_id, $key, $value );
		}

		return new \WP_REST_Response(
			[
				'code'    => 'post_user_success',
				'message' => __( 'Modified successfully', 'power-course' ),
				'data'    => [
					'id' => (string) $user_id,
				],
			],
			200
			);
	}




	/**
	 * 取得單一學員完整資料
	 *
	 * 供後台 Drawer 載入學員基本資料與所有 WC billing / shipping meta。
	 * 回傳為扁平結構（id / user_login / display_name / email / meta_data）；
	 * 刻意排除敏感欄位 user_pass 與 session_tokens。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_users_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$user_id = (int) $request['id'];
		$user    = \get_userdata( $user_id );

		if ( ! $user ) {
			return new \WP_REST_Response(
				[
					'code'    => 'user_not_found',
					'message' => __( 'User not found', 'power-course' ),
					'data'    => null,
				],
				404
			);
		}

		// 固定回傳的 meta key 白名單（含 WC billing_*/shipping_*），確保前端欄位永遠存在。
		$meta_keys = [
			'first_name',
			'last_name',
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
			'billing_country',
			'shipping_first_name',
			'shipping_last_name',
			'shipping_address_1',
			'shipping_address_2',
			'shipping_city',
			'shipping_state',
			'shipping_postcode',
			'shipping_country',
		];

		$meta_data = [];
		foreach ( $meta_keys as $meta_key ) {
			$meta_data[ $meta_key ] = (string) \get_user_meta( $user_id, $meta_key, true );
		}

		// 消費統計（以 WC_Customer 為來源，try/catch 靜默降級成 0/''，避免 WC 未啟用或例外時整支 API 掛掉）。
		$stats = $this->get_customer_stats( $user_id );

		$user_registered = (string) $user->user_registered;
		$registered_ts   = $user_registered ? \strtotime( $user_registered ) : false;

		// 扁平結構：前端直接讀 $data['id'] / $data['meta_data']['billing_phone']；不得包含 user_pass / session_tokens。
		// 既有欄位（id/user_login/name/display_name/email/meta_data）保留不刪，向下相容 issue/229；以下為鏡像 antd-toolkit/wp TUserBaseRecord 的新增頂層欄位。
		return new \WP_REST_Response(
			[
				'id'                    => (string) $user_id,
				'user_login'            => $user->user_login,
				'name'                  => $user->display_name,
				'display_name'          => $user->display_name,
				'email'                 => $user->user_email,
				'meta_data'             => $meta_data,
				// --- 消費數據（鏡像 TUserBaseRecord）---
				'total_spend'           => $stats['total_spend'],
				'orders_count'          => $stats['orders_count'],
				'avg_order_value'       => $stats['avg_order_value'],
				'date_last_active'      => $stats['date_last_active'],
				'date_last_order'       => $stats['date_last_order'],
				// --- 帳號資訊 ---
				'user_registered'       => $user_registered,
				'user_registered_human' => false !== $registered_ts
					? \sprintf(
						/* translators: %s: 距今多久（例如 3 天） */
						__( '%s ago', 'power-course' ),
						\human_time_diff( $registered_ts )
					)
					: '',
				'user_avatar_url'       => (string) \get_avatar_url( $user_id ),
				'user_birthday'         => (string) \get_user_meta( $user_id, 'user_birthday', true ),
				'description'           => (string) \get_user_meta( $user_id, 'description', true ),
				'role'                  => $user->roles[0] ?? '',
				'edit_url'              => (string) \get_edit_user_link( $user_id ),
				// --- billing / shipping 物件 ---
				'billing'               => $this->get_address_object( $user_id, 'billing' ),
				'shipping'              => $this->get_address_object( $user_id, 'shipping' ),
				// --- 購物車 / 最近訂單 / 聯絡註記 / 其他 meta ---
				'cart'                  => $this->get_persistent_cart( $user_id ),
				'recent_orders'         => $this->get_recent_orders( $user_id ),
				'contact_remarks'       => $this->get_contact_remarks( $user_id ),
				'other_meta_data'       => $this->get_other_meta_data( $user_id ),
			],
			200
		);
	}

	/**
	 * 取得單一學員的消費統計數據
	 *
	 * 以 WC_Customer 為資料來源，所有 WC 呼叫包在 try/catch 內靜默降級（WC 未啟用 / 例外時 fallback 0/''）。
	 *
	 * @param int $user_id User ID.
	 * @return array{total_spend: float, orders_count: int, avg_order_value: float, date_last_active: string, date_last_order: string}
	 */
	private function get_customer_stats( int $user_id ): array {
		$total_spend      = 0.0;
		$orders_count     = 0;
		$date_last_order  = '';
		$date_last_active = '';

		try {
			if ( \class_exists( '\WC_Customer' ) ) {
				$customer     = new \WC_Customer( $user_id );
				$total_spend  = (float) $customer->get_total_spent();
				$orders_count = (int) $customer->get_order_count();

				$last_order = $customer->get_last_order();
				if ( $last_order instanceof \WC_Order ) {
					$last_order_date = $last_order->get_date_created();
					$date_last_order = $last_order_date ? $last_order_date->date( 'Y-m-d H:i:s' ) : '';
				}
			}
		} catch ( \Throwable $th ) {
			// 靜默降級：統計欄位維持預設值。
			$total_spend  = 0.0;
			$orders_count = 0;
		}

		// date_last_active 無對應 WC 函式，讀 meta wc_last_active（timestamp）轉可讀的 Y-m-d H:i:s。
		$last_active_ts = \get_user_meta( $user_id, 'wc_last_active', true );
		if ( \is_numeric( $last_active_ts ) && (int) $last_active_ts > 0 ) {
			$date_last_active = \gmdate( 'Y-m-d H:i:s', (int) $last_active_ts );
		}

		$avg_order_value = $orders_count ? \round( $total_spend / $orders_count, 2 ) : 0.0;

		return [
			'total_spend'      => $total_spend,
			'orders_count'     => $orders_count,
			'avg_order_value'  => $avg_order_value,
			'date_last_active' => $date_last_active,
			'date_last_order'  => $date_last_order,
		];
	}

	/**
	 * 取得 billing / shipping 地址物件
	 *
	 * 對齊 power-shop InfoTable 欄位；billing 含 email/phone/company，shipping 去 email（保留 company/phone）。
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    'billing' | 'shipping'.
	 * @return array<string, string>
	 */
	private function get_address_object( int $user_id, string $type ): array {
		// billing 與 shipping 共用欄位（對齊 power-shop InfoTable AddressInput）。
		$fields = [
			'first_name',
			'last_name',
			'company',
			'country',
			'state',
			'postcode',
			'city',
			'address_1',
			'address_2',
			'phone',
		];

		// billing 多一個 email；shipping 無 email。
		if ( 'billing' === $type ) {
			$fields[] = 'email';
		}

		$address = [];
		foreach ( $fields as $field ) {
			$address[ $field ] = (string) \get_user_meta( $user_id, "{$type}_{$field}", true );
		}

		return $address;
	}

	/**
	 * 取得使用者的 persistent cart（當前購物車）
	 *
	 * Meta key 為 `_woocommerce_persistent_cart_{blog_id}`，用 get_current_blog_id() 動態組 key（禁止硬編碼 _1）。
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{product_id: int, product_name: string, quantity: int, price: float, line_total: float, product_image: string}>
	 */
	private function get_persistent_cart( int $user_id ): array {
		$meta_key = '_woocommerce_persistent_cart_' . \get_current_blog_id();
		/** @var mixed $persistent_cart */
		$persistent_cart = \get_user_meta( $user_id, $meta_key, true );

		if ( ! \is_array( $persistent_cart ) || empty( $persistent_cart['cart'] ) || ! \is_array( $persistent_cart['cart'] ) ) {
			return [];
		}

		$items = [];
		foreach ( $persistent_cart['cart'] as $cart_item ) {
			if ( ! \is_array( $cart_item ) ) {
				continue;
			}

			$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
			$quantity   = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
			$line_total = isset( $cart_item['line_total'] ) ? (float) $cart_item['line_total'] : 0.0;

			$items[] = $this->shape_product_item( $product_id, $quantity, $line_total );
		}

		return $items;
	}

	/**
	 * 將商品塑形為 cart/order item 統一形狀
	 *
	 * 商品已刪除時用 wc_placeholder_img_src() + 名稱 fallback。
	 *
	 * @param int   $product_id 商品 ID.
	 * @param int   $quantity   數量.
	 * @param float $line_total 小計（若為 0 則以 price * quantity 推算）.
	 * @return array{product_id: int, product_name: string, quantity: int, price: float, line_total: float, product_image: string}
	 */
	private function shape_product_item( int $product_id, int $quantity, float $line_total ): array {
		$product_name  = '';
		$price         = 0.0;
		$product_image = '';

		try {
			$product = $product_id > 0 && \function_exists( 'wc_get_product' ) ? \wc_get_product( $product_id ) : null;
		} catch ( \Throwable $th ) {
			$product = null;
		}

		if ( $product instanceof \WC_Product ) {
			$product_name = $product->get_name();
			$price        = (float) $product->get_price();

			$image_id = $product->get_image_id();
			if ( $image_id ) {
				$image_url     = \wp_get_attachment_image_url( (int) $image_id, 'thumbnail' );
				$product_image = $image_url ?: '';
			}
		} else {
			// 商品已刪除：名稱 fallback，沿用 WC 慣例顯示 (deleted)。
			$product_name = \sprintf(
				/* translators: %d: 商品 ID */
				__( 'Product #%d (deleted)', 'power-course' ),
				$product_id
			);
		}

		if ( '' === $product_image && \function_exists( 'wc_placeholder_img_src' ) ) {
			$product_image = (string) \wc_placeholder_img_src( 'thumbnail' );
		}

		// line_total 缺值時以 price * quantity 推算。
		if ( 0.0 === $line_total ) {
			$line_total = $price * $quantity;
		}

		return [
			'product_id'    => $product_id,
			'product_name'  => $product_name,
			'quantity'      => $quantity,
			'price'         => $price,
			'line_total'    => $line_total,
			'product_image' => $product_image,
		];
	}

	/**
	 * 取得最近訂單（含商品縮圖）
	 *
	 * @param int $user_id User ID.
	 * @param int $limit   筆數上限.
	 * @return array<int, array{order_id: int, order_date: string, order_total: float, order_status: string, order_items: array<int, array{product_id: int, product_name: string, quantity: int, price: float, line_total: float, product_image: string}>}>
	 */
	private function get_recent_orders( int $user_id, int $limit = 5 ): array {
		try {
			if ( ! \function_exists( 'wc_get_orders' ) ) {
				return [];
			}

			$orders = \wc_get_orders(
				[
					'customer_id' => $user_id,
					'limit'       => $limit,
					'orderby'     => 'date',
					'order'       => 'DESC',
				]
			);
		} catch ( \Throwable $th ) {
			return [];
		}

		if ( ! \is_array( $orders ) ) {
			return [];
		}

		$result = [];
		foreach ( $orders as $order ) {
			// wc_get_orders 回傳 WC_Order 物件陣列。
			$result[] = [
				'order_id'     => $order->get_id(),
				'order_date'   => $this->get_order_date_string( $order ),
				'order_total'  => (float) $order->get_total(),
				// 去掉 wc- 前綴（get_status() 本身已不含前綴，但保險處理）。
				'order_status' => \str_replace( 'wc-', '', $order->get_status() ),
				'order_items'  => $this->get_order_items( $order ),
			];
		}

		return $result;
	}

	/**
	 * 取得訂單建立日期字串（'Y-m-d H:i:s' 格式，無則空字串）
	 *
	 * @param \WC_Order $order Order.
	 * @return string
	 */
	private function get_order_date_string( \WC_Order $order ): string {
		$date_created = $order->get_date_created();
		return $date_created ? $date_created->date( 'Y-m-d H:i:s' ) : '';
	}

	/**
	 * 取得訂單的商品項目（統一 cart/order item 形狀）
	 *
	 * @param \WC_Order $order Order.
	 * @return array<int, array{product_id: int, product_name: string, quantity: int, price: float, line_total: float, product_image: string}>
	 */
	private function get_order_items( \WC_Order $order ): array {
		$items = [];
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id = (int) ( $item->get_product_id() ?: 0 );
			$quantity   = (int) $item->get_quantity();
			$line_total = (float) $item->get_total();

			$shaped = $this->shape_product_item( $product_id, $quantity, $line_total );
			// 優先使用訂單記錄的商品名稱（商品已刪除時仍保留下單當下名稱）。
			$item_name = $item->get_name();
			if ( $item_name ) {
				$shaped['product_name'] = $item_name;
			}
			$items[] = $shaped;
		}

		return $items;
	}

	/**
	 * 取得使用者的其他 meta data（過濾敏感 / 系統 key）
	 *
	 * 回傳 {umeta_id, meta_key, meta_value}[]，供前端 Meta Tab 雙向編輯。
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{umeta_id: int, meta_key: string, meta_value: string}>
	 */
	private function get_other_meta_data( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT umeta_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id ASC",
				$user_id
			)
		);

		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$blog_id             = \get_current_blog_id();
		$persistent_cart_key = '_woocommerce_persistent_cart_' . $blog_id;
		$result              = [];

		foreach ( $rows as $row ) {
			if ( ! \is_object( $row ) ) {
				continue;
			}
			/** @var object{umeta_id: string, meta_key: string, meta_value: string} $row */
			$meta_key = (string) $row->meta_key;

			// 過濾敏感 / 系統 key：session_tokens、*_capabilities、persistent cart、user-settings、密碼相關。
			if ( $this->is_sensitive_meta_key( $meta_key, $persistent_cart_key ) ) {
				continue;
			}

			$result[] = [
				'umeta_id'   => (int) $row->umeta_id,
				'meta_key'   => $meta_key,
				'meta_value' => (string) $row->meta_value,
			];
		}

		return $result;
	}

	/**
	 * 判斷 meta key 是否為敏感 / 系統欄位（不應出現在 other_meta_data）
	 *
	 * @param string $meta_key            Meta key.
	 * @param string $persistent_cart_key 當前站台的 persistent cart meta key.
	 * @return bool
	 */
	private function is_sensitive_meta_key( string $meta_key, string $persistent_cart_key ): bool {
		// 完全比對的敏感 key。
		$exact_blocklist = [
			'session_tokens',
			'user_pass',
			'user_activation_key',
			$persistent_cart_key,
		];
		if ( \in_array( $meta_key, $exact_blocklist, true ) ) {
			return true;
		}

		// 前綴 / 後綴比對：*_capabilities、*_user_level、_woocommerce_persistent_cart_*、user-settings*、session_tokens*。
		if ( \str_ends_with( $meta_key, '_capabilities' ) || \str_ends_with( $meta_key, '_user_level' ) ) {
			return true;
		}
		if ( \str_starts_with( $meta_key, '_woocommerce_persistent_cart_' ) ) {
			return true;
		}
		if ( \str_starts_with( $meta_key, 'user-settings' ) || \str_starts_with( $meta_key, 'session_tokens' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * 取得使用者的聯絡註記（contact_remark）
	 *
	 * 與 GET comments 共用此 reader。資料儲存於 wp_comments（comment_post_ID=0），
	 * 被留言對象 user_id 存於 commentmeta commented_user_id。
	 *
	 * @param int $user_id 被留言對象 User ID.
	 * @return array<int, array{id: int, content: string, date_created: string, customer_note: bool, added_by: string, user_id: int, commented_user_id: int}>
	 */
	private function get_contact_remarks( int $user_id ): array {
		$comments = \get_comments(
			[
				'type'       => 'contact_remark',
				'status'     => 'approve',
				'meta_key'   => 'commented_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $user_id,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'    => 'comment_date_gmt',
				'order'      => 'DESC',
			]
		);

		if ( ! \is_array( $comments ) ) {
			return [];
		}

		$result = [];
		foreach ( $comments as $comment ) {
			if ( ! $comment instanceof \WP_Comment ) {
				continue;
			}
			$result[] = $this->shape_contact_remark( $comment );
		}

		return $result;
	}

	/**
	 * 將 WP_Comment 塑形為 TUserContactRemark
	 *
	 * @param \WP_Comment $comment Comment.
	 * @return array{id: int, content: string, date_created: string, customer_note: bool, added_by: string, user_id: int, commented_user_id: int}
	 */
	private function shape_contact_remark( \WP_Comment $comment ): array {
		$author_id         = (int) $comment->user_id;
		$commented_user_id = (int) \get_comment_meta( (int) $comment->comment_ID, 'commented_user_id', true );
		$is_customer_note  = (bool) \get_comment_meta( (int) $comment->comment_ID, 'is_customer_note', true );

		// added_by：有作者顯示作者名，否則 'system'。
		$added_by = 'system';
		if ( $author_id > 0 ) {
			$author = \get_userdata( $author_id );
			if ( $author ) {
				$added_by = $author->display_name;
			}
		}

		return [
			'id'                => (int) $comment->comment_ID,
			'content'           => $comment->comment_content,
			'date_created'      => (string) \mysql2date( 'c', $comment->comment_date ),
			'customer_note'     => $is_customer_note,
			'added_by'          => $added_by,
			'user_id'           => $author_id,
			'commented_user_id' => $commented_user_id,
		];
	}

	/**
	 * Post user callback
	 * 修改 user
	 * 用 form-data 方式送出
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_users_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$user_id     = (int) $request['id'];
		$body_params = $request->get_body_params();
		$file_params = $request->get_file_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		/** @var array{data: array<string, mixed>, meta_data: array<string, mixed>} $separated */
		$separated = WP::separator( $body_params, 'user', $file_params['files'] ?? null );
		/** @var array<string, mixed> $data */
		$data      = $separated['data'];
		/** @var array<string, mixed> $meta_data */
		$meta_data = $separated['meta_data'];

		$data['ID'] = $user_id;
		unset($meta_data['id']);

		// 密碼留空（'' 或未提供）時移除 user_pass，避免 wp_update_user 將密碼清空。
		if ( empty( $data['user_pass'] ) ) {
			unset( $data['user_pass'] );
		}

		// role：WP::separator 會把 role 歸入 $data（user data field），但 wp_update_user 不做安全守門。
		// 規則：僅當角色合法（wp_roles()->is_role）且非更新自己（防自我降權鎖死）才套用，否則靜默忽略。
		$requested_role = isset( $data['role'] ) ? (string) $data['role'] : '';
		unset( $data['role'] ); // 一律從 $data 移除，由下方守門邏輯決定是否 set_role，避免 wp_update_user 直接改 role。
		if (
			'' !== $requested_role &&
			\wp_roles()->is_role( $requested_role ) &&
			$user_id !== \get_current_user_id()
		) {
			$user_obj = \get_userdata( $user_id );
			if ( $user_obj ) {
				$user_obj->set_role( $requested_role );
			}
		}

		// user_birthday：僅當格式為 YYYY-MM-DD 才寫入 meta，不符則不寫該欄位（從 $meta_data 移除避免落入下方通用迴圈）。
		if ( array_key_exists( 'user_birthday', $meta_data ) ) {
			$user_birthday = (string) $meta_data['user_birthday'];
			unset( $meta_data['user_birthday'] );
			if ( '' === $user_birthday || \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $user_birthday ) ) {
				\update_user_meta( $user_id, 'user_birthday', $user_birthday );
			}
		}

		// 處理 other_meta_data：講師 Edit 頁 Meta Tab 透過 umeta_id 直接更新指定 meta row。
		// 注意：WP::separator 會把非 user data field 的 key 一律丟進 $meta_data；
		// other_meta_data 不是標準 user column，因此需從 $meta_data 取，而非 $data。
		$other_meta_data = $meta_data['other_meta_data'] ?? [];
		$other_meta_data = \is_array( $other_meta_data ) ? $other_meta_data : [];
		unset( $meta_data['other_meta_data'] );

		foreach ( $other_meta_data as $other_meta_data_record ) {
			if ( ! \is_array( $other_meta_data_record ) ) {
				continue;
			}
			/** @var array{umeta_id?: string|int, meta_key?: string, meta_value?: string} $other_meta_data_record */
			$umeta_id   = isset( $other_meta_data_record['umeta_id'] ) ? (int) $other_meta_data_record['umeta_id'] : 0;
			$meta_key   = isset( $other_meta_data_record['meta_key'] ) ? (string) $other_meta_data_record['meta_key'] : '';
			$meta_value = $other_meta_data_record['meta_value'] ?? '';
			if ( $umeta_id > 0 && '' !== $meta_key ) {
				\update_metadata_by_mid( 'user', $umeta_id, $meta_value, $meta_key );
			}
		}

		$update_user_result = \wp_update_user( $data );

		$update_success = \is_numeric($update_user_result);

		foreach ( $meta_data as $key => $value ) {
			\update_user_meta($user_id, $key, $value );
		}

		return new \WP_REST_Response(
			[
				'code'    => $update_success ? 'post_user_success' : 'post_user_error',
				'message' => $update_success ? __( 'Modified successfully', 'power-course' ) : __( 'Modification failed', 'power-course' ),
				'data'    => [
					'id'                 => (string) $user_id,
					'update_user_result' => $update_user_result,
				],
			],
			$update_success ? 200 : 400
			);
	}

	/**
	 * 發送 WordPress 原生密碼重設信
	 *
	 * 觸發 WordPress 原生密碼重設流程，將重設連結寄至學員登入 Email。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_users_with_id_reset_password_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$user = \get_userdata( (int) $request['id'] );

		if ( ! $user ) {
			return new \WP_REST_Response(
				[
					'code'    => 'user_not_found',
					'message' => __( 'User not found', 'power-course' ),
					'data'    => null,
				],
				404
			);
		}

		$result = \retrieve_password( $user->user_login );

		if ( true === $result ) {
			return new \WP_REST_Response(
				[
					'code'    => 'reset_password_email_sent',
					'message' => __( 'Password reset email sent', 'power-course' ),
					'data'    => null,
				],
				200
			);
		}

		return new \WP_REST_Response(
			[
				'code'    => 'reset_password_failed',
				'message' => \is_wp_error( $result ) ? $result->get_error_message() : __( 'Failed to send password reset email', 'power-course' ),
				'data'    => null,
			],
			500
		);
	}

	/**
	 * 取得學員訂單摘要
	 *
	 * 供後台 Drawer「訂單摘要」區塊使用，回傳訂單總筆數與最近數筆訂單摘要。
	 * 回傳為扁平結構（total / view_all_url / recent）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_users_with_id_orders_summary_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$user_id = (int) $request['id'];
		$limit   = (int) ( $request->get_param( 'limit' ) ?: 5 );

		/** @var \stdClass&object{orders: array<\WC_Order>, total: int} $results */
		$results = \wc_get_orders(
			[
				'customer_id' => $user_id,
				'limit'       => $limit,
				'paginate'    => true,
				'orderby'     => 'date',
				'order'       => 'DESC',
			]
		);

		$total  = (int) $results->total;
		$orders = $results->orders;

		$recent = array_map(
			function ( \WC_Order $order ): array {
				$date_created = $order->get_date_created();
				return [
					'id'           => $order->get_id(),
					'number'       => $order->get_order_number(),
					'date_created' => $date_created ? $date_created->date( 'c' ) : '',
					'status'       => $order->get_status(),
					'total'        => $order->get_total(),
					'currency'     => $order->get_currency(),
					'edit_url'     => $order->get_edit_order_url(),
					// 向下相容：既有欄位不刪，補上 order_items[]（含商品縮圖）以對齊新版前端 RecentOrders。
					'order_items'  => $this->get_order_items( $order ),
				];
			},
			$orders
		);

		// HPOS 相容：依是否啟用自訂訂單表決定訂單列表連結。
		if (
			class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
		) {
			$view_all_url = \admin_url( 'admin.php?page=wc-orders&_customer_user=' . $user_id );
		} else {
			$view_all_url = \admin_url( 'edit.php?post_type=shop_order&_customer_user=' . $user_id );
		}

		return new \WP_REST_Response(
			[
				'total'        => $total,
				'view_all_url' => $view_all_url,
				'recent'       => $recent,
			],
			200
		);
	}

	/**
	 * 取得角色選項
	 *
	 * 供基本資料 Tab 的角色下拉選單使用。回傳格式對齊 power-shop useOptions：{data:{roles:[{value,label}]}}。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_users_options_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$roles = [];
		foreach ( \wp_roles()->get_names() as $slug => $label ) {
			$roles[] = [
				'value' => (string) $slug,
				// translate_user_role 會套用 WP 既有的角色名稱翻譯。
				'label' => \function_exists( 'translate_user_role' ) ? \translate_user_role( (string) $label ) : (string) $label,
			];
		}

		return new \WP_REST_Response(
			[
				'data' => [
					'roles' => $roles,
				],
			],
			200
		);
	}

	/**
	 * 取得聯絡註記列表
	 *
	 * Query: commented_user_id（被留言對象 user）。回傳 TUserContactRemark[]。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function get_comments_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$commented_user_id = (int) ( $request->get_param( 'commented_user_id' ) ?: 0 );

		if ( $commented_user_id <= 0 ) {
			return new \WP_REST_Response( [], 200 );
		}

		return new \WP_REST_Response( $this->get_contact_remarks( $commented_user_id ), 200 );
	}

	/**
	 * 新增聯絡註記
	 *
	 * Body: {comment_type:'contact_remark', commented_user_id, note, is_customer_note}。
	 * 儲存於 wp_comments（comment_post_ID=0），被留言對象存於 commentmeta commented_user_id。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function post_comments_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$body_params = $request->get_body_params();
		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );

		$commented_user_id = (int) ( $body_params['commented_user_id'] ?? 0 );
		$note              = isset( $body_params['note'] ) ? \trim( (string) $body_params['note'] ) : '';
		$is_customer_note  = ! empty( $body_params['is_customer_note'] );

		if ( '' === $note ) {
			return new \WP_REST_Response(
				[
					'code'    => 'empty_note',
					'message' => __( 'Note content cannot be empty', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}

		if ( $commented_user_id <= 0 || ! \get_userdata( $commented_user_id ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'invalid_user',
					'message' => __( 'The specified user does not exist', 'power-course' ),
					'data'    => null,
				],
				400
			);
		}

		$current_user_id = \get_current_user_id();
		$current_user    = $current_user_id ? \get_userdata( $current_user_id ) : false;

		$comment_id = \wp_insert_comment(
			[
				'comment_post_ID'      => 0,
				'comment_type'         => 'contact_remark',
				'comment_content'      => $note,
				'user_id'              => $current_user_id,
				'comment_author'       => $current_user ? $current_user->display_name : '',
				'comment_author_email' => $current_user ? $current_user->user_email : '',
				'comment_approved'     => 1,
			]
		);

		if ( ! $comment_id ) {
			return new \WP_REST_Response(
				[
					'code'    => 'create_comment_failed',
					'message' => __( 'Failed to create note', 'power-course' ),
					'data'    => null,
				],
				500
			);
		}

		\add_comment_meta( (int) $comment_id, 'commented_user_id', $commented_user_id );
		\add_comment_meta( (int) $comment_id, 'is_customer_note', $is_customer_note ? '1' : '' );

		// Refine create 格式：{data:{id}}。
		return new \WP_REST_Response(
			[
				'data' => [
					'id' => (int) $comment_id,
				],
			],
			200
		);
	}

	/**
	 * 刪除聯絡註記
	 *
	 * 安全守門：僅允許刪除 comment_type 為 contact_remark 的留言（防誤刪一般留言）。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function delete_comments_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		\nocache_headers();

		$comment_id = (int) $request['id'];
		$comment    = \get_comment( $comment_id );

		if ( ! $comment instanceof \WP_Comment ) {
			return new \WP_REST_Response(
				[
					'code'    => 'comment_not_found',
					'message' => __( 'Note not found', 'power-course' ),
					'data'    => null,
				],
				404
			);
		}

		// 安全守門：非 contact_remark 一律拒刪，避免誤刪一般文章留言。
		if ( 'contact_remark' !== $comment->comment_type ) {
			return new \WP_REST_Response(
				[
					'code'    => 'forbidden_comment_type',
					'message' => __( 'Only contact notes can be deleted', 'power-course' ),
					'data'    => null,
				],
				403
			);
		}

		\wp_delete_comment( $comment_id, true );

		// Refine delete 格式：{data:{id}}。
		return new \WP_REST_Response(
			[
				'data' => [
					'id' => $comment_id,
				],
			],
			200
		);
	}

	/**
	 * 處理批次將用戶設定為講師的請求。
	 *
	 * 回傳結構：
	 * - data.user_ids: 成功設定為講師的用戶 ID 陣列（字串）
	 * - data.failed_user_ids: 設定失敗的用戶 ID 陣列（字串）
	 *
	 * 狀態碼：
	 * - 全部成功：200
	 * - 部分成功：200（failed_user_ids 非空）
	 * - 全部失敗：400
	 * - user_ids 為空：400
	 *
	 * @param \WP_REST_Request $request REST請求對象，包含需要處理的用戶ID。
	 * @return \WP_REST_Response 返回REST響應對象，包含操作結果的狀態碼和訊息。
	 */
	public function post_users_add_teachers_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		/** @var array<int|string> $user_ids */
		$user_ids = $body_params['user_ids'] ?? [];

		if ( empty( $user_ids ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'add_teachers_invalid_params',
					'message' => __( 'user_ids is required', 'power-course' ),
					'data'    => [
						'user_ids'        => [],
						'failed_user_ids' => [],
					],
				],
				400
			);
		}

		$success_ids = [];
		$failed_ids  = [];

		foreach ( $user_ids as $user_id ) {
			$user_id_int = (int) $user_id;

			// 用戶不存在視為失敗
			if ( ! \get_userdata( $user_id_int ) ) {
				$failed_ids[] = (string) $user_id_int;
				continue;
			}

			$result = \update_user_meta( $user_id_int, 'is_teacher', 'yes' );

			// update_user_meta 回傳 false 代表失敗；回傳 true / meta_id 皆視為成功
			if ( false === $result ) {
				$failed_ids[] = (string) $user_id_int;
			} else {
				$success_ids[] = (string) $user_id_int;
			}
		}

		$all_failed = empty( $success_ids );

		return new \WP_REST_Response(
			[
				'code'    => $all_failed ? 'add_teachers_failed' : 'add_teachers_success',
				'message' => $all_failed
					? __( 'Failed to batch convert users to instructors', 'power-course' )
					: __( 'Users batch converted to instructors successfully', 'power-course' ),
				'data'    => [
					'user_ids'        => $success_ids,
					'failed_user_ids' => $failed_ids,
				],
			],
			$all_failed ? 400 : 200
		);
	}

	/**
	 * 將指定用戶批次移除講師身分
	 *
	 * 行為特性：
	 * - 逐一嘗試移除每個 user 的 is_teacher meta。
	 * - 單筆失敗不中斷迴圈，繼續處理後續用戶（**不再 early-break**）。
	 * - 回傳 success_ids / failed_user_ids 區分成功與失敗。
	 *
	 * 回傳結構：
	 * - data.user_ids: 成功移除講師身分的用戶 ID 陣列（字串）
	 * - data.failed_user_ids: 移除失敗的用戶 ID 陣列（字串；含「非講師、meta 不存在」的情況）
	 *
	 * 狀態碼：
	 * - 全部成功：200
	 * - 部分成功：200（failed_user_ids 非空）
	 * - 全部失敗：400
	 * - user_ids 為空：400
	 *
	 * @param \WP_REST_Request $request 包含用戶ID的REST請求。
	 * @return \WP_REST_Response 包含操作結果的響應對象。
	 */
	public function post_users_remove_teachers_callback( \WP_REST_Request $request ): \WP_REST_Response {

		$body_params = $request->get_body_params();

		/** @var array<string, mixed> $body_params */
		$body_params = WP::sanitize_text_field_deep( $body_params );
		/** @var array<int|string> $user_ids */
		$user_ids = $body_params['user_ids'] ?? [];

		if ( empty( $user_ids ) ) {
			return new \WP_REST_Response(
				[
					'code'    => 'remove_teachers_invalid_params',
					'message' => __( 'user_ids is required', 'power-course' ),
					'data'    => [
						'user_ids'        => [],
						'failed_user_ids' => [],
					],
				],
				400
			);
		}

		$success_ids = [];
		$failed_ids  = [];

		foreach ( $user_ids as $user_id ) {
			$user_id_int = (int) $user_id;
			$result      = \delete_user_meta( $user_id_int, 'is_teacher' );

			if ( false === $result ) {
				// 不 early-break：收集失敗、繼續處理後續用戶
				$failed_ids[] = (string) $user_id_int;
			} else {
				$success_ids[] = (string) $user_id_int;
			}
		}

		$all_failed = empty( $success_ids );

		return new \WP_REST_Response(
			[
				'code'    => $all_failed ? 'remove_teachers_failed' : 'remove_teachers_success',
				'message' => $all_failed
					? __( 'Failed to batch remove instructors', 'power-course' )
					: __( 'Instructors batch removed successfully', 'power-course' ),
				'data'    => [
					'user_ids'        => $success_ids,
					'failed_user_ids' => $failed_ids,
				],
			],
			$all_failed ? 400 : 200
		);
	}

	/**
	 * 上傳學員
	 *
	 * @param \WP_REST_Request $request 包含上傳學員資料的REST請求對象。
	 * @return \WP_REST_Response 包含操作結果的REST響應對象。
	 */
	public function post_users_upload_students_callback( \WP_REST_Request $request ): \WP_REST_Response {

		try {

			$file_params = $request->get_file_params();
			/**
			 * @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file
			 */
			$file = $file_params['files'];

			// 上傳到媒體庫
			$file_content = file_get_contents($file['tmp_name']);
			if ($file_content === false) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => __( 'Failed to read uploaded file', 'power-course' ),
					'data'    => $file,
				],
				400
				);
			}
			$upload = \wp_upload_bits($file['name'], null, (string) $file_content);

			if ($upload['error'] !== false) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => __( 'Failed to upload students', 'power-course' ),
					'data'    => $file,
				],
				400
				);
			}

			$allowed_mime_types = [ 'text/csv', 'application/csv', 'text/comma-separated-values', 'application/excel', 'application/vnd.ms-excel', 'application/vnd.msexcel' ];
			// 限制檔案類型
			$wp_filetype = \wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowed_mime_types);
			$attachment  = [
				'post_mime_type' => $wp_filetype['type'],
				'post_title'     => \sanitize_file_name($file['name']),
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			/** @var int|\WP_Error $attachment_id */
			$attachment_id = \wp_insert_attachment($attachment, $upload['file']);

			if (\is_wp_error($attachment_id)) {
				return new \WP_REST_Response(
				[
					'code'    => 'upload_students_error',
					'message' => \sprintf(
						/* translators: %s: 錯誤訊息 */
						__( 'Failed to upload students: %s', 'power-course' ),
						$attachment_id->get_error_message()
					),
					'data'    => $file,
				],
				400
				);
			}

			// --- START 將 email_content 寫入到 txt 檔，不然太大傳參會 exception START ---
			$email_content_file_name    = 'pc_batch_upload_email_content_' . time() . '.txt';
			$email_content_file_content = \sprintf(
				/* translators: %d: 每批次筆數 */
				__( "CSV student import started, %d records per batch \n\n\n", 'power-course' ),
				self::BATCH_SIZE
			);

			// 獲取 WordPress 上傳目錄的路徑
			$upload_dir  = \wp_upload_dir();
			$upload_path = $upload_dir['path'];

			// 創建文字檔的完整路徑
			$email_content_file_path = "{$upload_path}/{$email_content_file_name}";

			// 初始化 WP_Filesystem
			global $wp_filesystem;
			if (empty($wp_filesystem)) {
				require_once ABSPATH . '/wp-admin/includes/file.php';
				\WP_Filesystem();
			}

			// 寫入文字檔內容
			$wp_filesystem->put_contents($email_content_file_path, $email_content_file_content, FS_CHMOD_FILE);

			// --- END 將 email content 寫入到 txt 檔，不然太大傳參會 exception END ---

			$action_id = \as_enqueue_async_action( 'pc_batch_add_students_task', [ $attachment_id, 0, self::BATCH_SIZE, $email_content_file_path ], 'power_course_batch_add_students' );

			// 寫入 DB
			return new \WP_REST_Response(
			[
				'code'    => 'upload_students_success',
				'message' => __( 'Student upload scheduled successfully, results will be notified via email', 'power-course' ),
				'data'    => [
					'action_id' => $action_id,
					'url'       => \admin_url('admin.php?page=wc-status&tab=action-scheduler&s=pc_batch_add_students_task'),
				],
			]
			);
		} catch (\Throwable $th) {
			return new \WP_REST_Response(
				[
					'code'    => 'upload_students_failed',
					'message' => \sprintf(
						/* translators: %s: 錯誤訊息 */
						__( 'Failed to upload students: %s', 'power-course' ),
						$th->getMessage()
					),
					'data'    => null,
				],
				400
			);
		}
	}

	/**
	 * 經過實測，大約連續創建 32個用戶，系統就會資源不足
	 * 每批數量建議100人
	 *
	 * @param int    $attachment_id 附件 ID
	 * @param int    $batch 批次
	 * @param int    $batch_size 每批數量
	 * @param string $email_content_file_path Email content file path
	 * @return void
	 */
	public function process_batch_add_students( int $attachment_id, int $batch, int $batch_size, string $email_content_file_path = '' ): void {
		$file = File::get_file_by_id($attachment_id);
		// 獲取當前批次的資料
		$current_batch_rows = File::parse_csv_streaming($file, $batch, $batch_size);
		$is_last_batch      = $batch === 0 ? count($current_batch_rows) < $batch_size - 1 : count($current_batch_rows) < $batch_size; // -1 是要扣掉標題欄
		// 去除重複

		$unique_array_instance = new UniqueArray($current_batch_rows);
		$unique_rows           = $unique_array_instance->get_list();
		$email_content         = '';
		$add_student           = new AddStudent();
		foreach ($unique_rows as $csv_row) {
			$email       = $csv_row[0];
			$course_id   = $csv_row[1];
			$expire_date = $csv_row[2] ?? 0;
			if (!\is_email($email) || !$course_id || !\is_numeric($course_id)) {
				continue;
			}

			$user = \get_user_by('email', $email);

			// ----- ▼ 判斷用戶存不存在，不存在就創建 ----- //

			if (!$user) {
				// 如果用戶不存在，要創建用戶，並且計送 EMAIL 設置密碼

				$username = $email;
				$password = \wp_generate_password(12);
				$user_id  = \wp_create_user($username, $password, $email);
				if (\is_wp_error($user_id)) {
					self::log(
						\sprintf(
							/* translators: 1: 錯誤訊息, 2: 用戶名稱, 3: 用戶信箱 */
							__( 'Failed to create user: %1$s, username: %2$s, email: %3$s', 'power-course' ),
							$user_id->get_error_message(),
							$username,
							$email
						),
						'error'
					);
					continue;
				}

				// 發送重新設置密碼的信
				$result = \retrieve_password($username);

				if (true !== $result) {
					self::log(
						\sprintf(
							/* translators: 1: 錯誤訊息, 2: 用戶名稱, 3: 用戶信箱 */
							__( 'Failed to send password reset email: %1$s, username: %2$s, email: %3$s', 'power-course' ),
							$result->get_error_message(),
							$username,
							$email
						),
						'error'
					);
					continue;
				}
			} else {
				// 如果用戶已經存在，要先取得用戶 ID
				$user_id = (int) $user->ID;
				// 原本用戶已經可以上課，那就一樣覆蓋課程時間
			}

			// 處理 $expire_date，如果 是 subscription_ 開頭, 0, timestamp，則不需要處理
			if (!str_starts_with( (string) $expire_date, 'subscription_') && !\is_numeric($expire_date)) {
				$expire_date = WP::wp_strtotime($expire_date) ?? 0;
			}
			if (is_numeric($expire_date)) {
				$expire_date = (int) $expire_date;
			}

			$add_student->add_item( (int) $user_id, (int) $course_id, $expire_date, null );

			$email_content .= \sprintf(
				/* translators: 1: 用戶 ID, 2: 課程 ID, 3: 到期日 */
				__( "User #%1\$d granted access to course #%2\$d, expire date %3\$s \n\n", 'power-course' ),
				$user_id,
				$course_id,
				(string) $expire_date
			);
		}

		$add_student->do_action();

		// ----- ▼ 寫入 log 以及 email 文字檔 ----- //
		$attachment_url = \wp_get_attachment_url($attachment_id);
		self::log(
			\sprintf(
				$is_last_batch
					/* translators: 1: 附件 ID, 2: 附件 URL */
					? __( 'Attachment #%1$d %2$s CSV import is on the last batch, sending email and ending', 'power-course' )
					/* translators: 1: 附件 ID, 2: 附件 URL */
					: __( 'Attachment #%1$d %2$s CSV import is not on the last batch, continuing', 'power-course' ),
				$attachment_id,
				(string) $attachment_url
			),
			'info'
		);

		// 初始化 WP_Filesystem
		global $wp_filesystem;
		if (empty($wp_filesystem)) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		// 讀取原本檔案內容
		$old_content = $wp_filesystem->get_contents($email_content_file_path);

		// 新內容
		$new_content = $old_content . $email_content;

		// 寫入文字檔內容
		$wp_filesystem->put_contents($email_content_file_path, $new_content, FS_CHMOD_FILE);

		// 如果還有下一批資料,安排下一次執行
		if ( !$is_last_batch ) {
			$action_id = \as_enqueue_async_action(
			'pc_batch_add_students_task',
			[ $attachment_id, $batch + 1, $batch_size, $email_content_file_path ],
			'power_course_batch_add_students',
			);
			self::log(
				\sprintf(
					/* translators: 1: 附件 ID, 2: 附件 URL, 3: 下次排程 action ID */
					__( 'Attachment #%1$d %2$s CSV import next scheduled as %3$d', 'power-course' ),
					$attachment_id,
					(string) $attachment_url,
					(int) $action_id
				),
				'info'
			);
		} else {

			$upload_dir = \wp_upload_dir();

			// 標準化路徑
			$basedir   = \wp_normalize_path($upload_dir['basedir']);
			$file_path = \wp_normalize_path($email_content_file_path);

			// 取得相對路徑
			$relative_path = ltrim(str_replace($basedir, '', $file_path), '/');

			// 組合成 URL
			$file_url = \trailingslashit($upload_dir['baseurl']) . $relative_path;

			// 如果已經沒有下一批資料, 就發送 EMAIL
			$admin_email = (string) \get_option('admin_email');
			\wp_mail(
			$admin_email,
			\sprintf(
				/* translators: 1: 總筆數, 2: 批次數, 3: 每批筆數 */
				__( 'CSV student import result: %1$d records total, %2$d batches, %3$d per batch', 'power-course' ),
				( $batch ) * $batch_size + count($current_batch_rows),
				$batch + 1,
				$batch_size
			),
			'<a href="' . $file_url . '">' . \esc_html__( 'Download student course access details', 'power-course' ) . '</a>',
			[
				'Content-Type: text/html; charset=UTF-8',
			]
			);
		}
	}
}
