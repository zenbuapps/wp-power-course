<?php

declare (strict_types = 1);

namespace J7\PowerCourse\Compatibility;

/**
 * 銷售方案相容性
 */
final class BundleProduct {
	/**
	 * 取得所有銷售方案的 id
	 *
	 * @return array<string> 所有銷售方案的 id
	 */
	public static function get_all_bundle_products(): array {
		global $wpdb;

		$bundle_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id WHERE p.post_type = 'product' AND pm.meta_key = 'bundle_type'"
		)
		);

		return $bundle_ids;
	}

	/**
	 * 將所有銷售方案 set_catalog_visibility 為 hidden
	 *
	 * @since 0.8.19
	 * @return void
	 */
	public static function set_catalog_visibility_to_hidden(): void {
		$bundle_ids = self::get_all_bundle_products();
		foreach ( $bundle_ids as $bundle_id ) {
			$bundle_product = \wc_get_product( $bundle_id );
			if ( ! $bundle_product ) {
				continue;
			}
			$bundle_product->set_catalog_visibility( 'hidden' );
			$bundle_product->save();
		}
	}
}
