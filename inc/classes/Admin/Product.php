<?php

declare(strict_types=1);

namespace J7\PowerCourse\Admin;

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\BundleProduct\Helper;

/** Class Product */
final class Product {
	use \J7\WpUtils\Traits\SingletonTrait;

	const PRODUCT_OPTION_NAME = 'is_course';


	/** Constructor */
	public function __construct() {
		\add_filter( 'product_type_options', [ __CLASS__, 'add_product_type_options' ] );
		\add_action( 'save_post_product', [ __CLASS__, 'save_product_type_options' ], 10, 3 );
		\add_filter( 'display_post_states', [ __CLASS__, 'custom_display_post_states' ], 10, 2 );
	}



	/**
	 * Add product type options
	 *
	 * @param array{
	 *     string:array{
	 *      id:string,
	 *      wrapper_class:string,
	 *      label:string,
	 *      description:string,
	 *      default:string
	 *     }
	 * } $product_type_options - Product type options
	 *
	 * @return array{
	 *     string:array{
	 *      id:string,
	 *      wrapper_class:string,
	 *      label:string,
	 *      description:string,
	 *      default:string
	 *     }
	 * }
	 */
	public static function add_product_type_options( $product_type_options ): array {

		$option = self::PRODUCT_OPTION_NAME;

		$product_type_options[ $option ] = [
			'id'            => "_{$option}",
			'wrapper_class' => 'show_if_simple show_if_external',
			'label'         => '課程',
			'description'   => '是否為課程商品，課程商品只能用於【簡單商品】、【外部商品】以及【簡易訂閱】',
			'default'       => 'no',
		];

		return $product_type_options;
	}

	/**
	 * Save product type options
	 *
	 * @deprecated 已經有 J7\PowerCourse\FrontEnd\Product::add_post_meta_to_course_product
	 *
	 * @param int      $post_id - Post ID
	 * @param \WP_Post $product_post - Post object
	 * @param bool     $update - Update flag
	 *
	 * @return void
	 */
	public static function save_product_type_options( $post_id, $product_post, $update ): void {
		$option = self::PRODUCT_OPTION_NAME;
		$option = "_{$option}";

		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! \wp_verify_nonce( \sanitize_key( (string) $_POST['woocommerce_meta_nonce'] ), 'woocommerce_save_data' ) ) {
			return;
		}

		if (!isset( $_REQUEST[ $option ] )) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// 只有在傳統的 WordPress 商品編輯才會預設儲存為 no
			$is_admin = \is_admin() && ( isset($_POST['publish']) || isset($_POST['save']) );
			if ($is_admin) {
				\update_post_meta( $post_id, $option, 'no' );
			}
			return;
		}

		if (empty($_REQUEST[ $option ])) { // phpcs:ignore
			return;
		}

		$is_course = \wc_string_to_bool( (string) $_REQUEST[ $option ] ) || 'on' === $_REQUEST[ $option ]; // phpcs:ignore

		\update_post_meta( $post_id, $option, \wc_bool_to_string( $is_course) ); // phpcs:ignore
	}

	/**
	 * Custom display post states
	 *
	 * @param array{string:string} $post_states - Post states
	 * @param \WP_Post|null        $post - Post object
	 *
	 * @return array{string:string}
	 */
	public static function custom_display_post_states( array $post_states, $post ): array {
		if ( !$post?->ID ) {
			return $post_states;
		}

		if ( CourseUtils::is_course_product( $post->ID ) ) {
			$post_states['course'] = '課程商品';
		}
		$helper = Helper::instance( $post->ID );
		if ( $helper?->is_bundle_product ) {
			$post_states['bundle'] = '銷售方案商品';
		}
		return $post_states;
	}


	/**
	 * 針對課程商品 || 銷售方案商品在訂單 detail 添加額外的 class
	 * 不然 WC 會把編輯的連結顯示在畫面上
	 *
	 * @param string                 $class - Class
	 * @param \WC_Order_Item_Product $item - Order item
	 * @param \WC_Order              $order - Order
	 *
	 * @return string
	 */
	public static function add_order_item_class( string $class, \WC_Order_Item_Product $item, \WC_Order $order ): string {
		$product_id = $item->get_product_id();
		$helper     = Helper::instance( $product_id );
		if ( CourseUtils::is_course_product( $product_id ) || $helper?->is_bundle_product ) {
			$class .= ' [&_.wc-order-item-name]:pointer-events-none';
		}

		return $class;
	}
}
