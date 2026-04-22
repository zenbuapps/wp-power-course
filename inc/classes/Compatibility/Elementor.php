<?php

declare(strict_types=1);

namespace J7\PowerCourse\Compatibility;

/**
 * Elementor CPT 自動支援
 * 當 Power Course 與 Elementor 同時啟用時，自動將 product 加入 Elementor 的 CPT 支援清單
 */
final class Elementor {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** Constructor */
	public function __construct() {
		\add_action( 'admin_init', [ $this, 'ensure_product_cpt_support' ] );
	}

	/**
	 * 確保 product CPT 在 Elementor 的支援清單中
	 *
	 * @return void
	 */
	public function ensure_product_cpt_support(): void {
		if ( ! class_exists( 'Elementor\Plugin' ) ) {
			return;
		}

		/** @var array<string>|false $cpt_support */
		$cpt_support = \get_option( 'elementor_cpt_support', false );

		if ( ! is_array( $cpt_support ) ) {
			$cpt_support = [ 'post', 'page' ];
		}

		if ( in_array( 'product', $cpt_support, true ) ) {
			return;
		}

		$cpt_support[] = 'product';
		\update_option( 'elementor_cpt_support', $cpt_support );
	}
}
