<?php
/**
 * Announcement CPT
 *
 * 註冊課程公告自訂文章類型 pc_announcement。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Announcement\Core;

use J7\PowerCourse\Plugin;

/**
 * Class CPT
 */
final class CPT {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const POST_TYPE = 'pc_announcement';

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register_cpt' ] );
	}

	/**
	 * 註冊 pc_announcement CPT
	 *
	 * 設計決策（Issue #6）：
	 * - hierarchical=false：公告無父子關係
	 * - public=false：不開放前台 archive 與 single 頁
	 * - show_ui 僅在開發環境（Plugin::$is_local）顯示，正式環境僅透過 React SPA 管理
	 * - supports 不含 page-attributes（公告依 post_date 排序，不需 menu_order）
	 */
	public static function register_cpt(): void {
		$labels = [
			'name'                  => \esc_html__( 'Announcements', 'power-course' ),
			'singular_name'         => \esc_html__( 'Announcement', 'power-course' ),
			'add_new'               => \esc_html__( 'Add new', 'power-course' ),
			'add_new_item'          => \esc_html__( 'Add new announcement', 'power-course' ),
			'edit_item'             => \esc_html__( 'Edit announcement', 'power-course' ),
			'new_item'              => \esc_html__( 'New announcement', 'power-course' ),
			'view_item'             => \esc_html__( 'View announcement', 'power-course' ),
			'view_items'            => \esc_html__( 'View announcements', 'power-course' ),
			'search_items'          => \esc_html__( 'Search announcements', 'power-course' ),
			'not_found'             => \esc_html__( 'No announcements found', 'power-course' ),
			'not_found_in_trash'    => \esc_html__( 'No announcements found in trash', 'power-course' ),
			'all_items'             => \esc_html__( 'All announcements', 'power-course' ),
			'archives'              => \esc_html__( 'Announcement archives', 'power-course' ),
			'attributes'            => \esc_html__( 'Announcement attributes', 'power-course' ),
			'menu_name'             => \esc_html__( 'Announcements', 'power-course' ),
			'filter_items_list'     => \esc_html__( 'Filter announcements list', 'power-course' ),
			'items_list_navigation' => \esc_html__( 'Announcements list navigation', 'power-course' ),
			'items_list'            => \esc_html__( 'Announcements list', 'power-course' ),
		];

		$args = [
			'label'                 => \esc_html__( 'Announcements', 'power-course' ),
			'labels'                => $labels,
			'description'           => '',
			'public'                => false,
			'hierarchical'          => false,
			'exclude_from_search'   => true,
			'publicly_queryable'    => false,
			'show_ui'               => Plugin::$is_local,
			'show_in_nav_menus'     => false,
			'show_in_admin_bar'     => false,
			'show_in_rest'          => true,
			'can_export'            => true,
			'delete_with_user'      => false,
			'has_archive'           => false,
			'rest_base'             => '',
			'show_in_menu'          => Plugin::$is_local,
			'menu_position'         => 7,
			'menu_icon'             => 'dashicons-megaphone',
			'capability_type'       => 'post',
			'supports'              => [ 'title', 'editor', 'custom-fields', 'author' ],
			'taxonomies'            => [],
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		];

		\register_post_type( self::POST_TYPE, $args );
	}
}
