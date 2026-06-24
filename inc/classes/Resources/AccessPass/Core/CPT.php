<?php
/**
 * AccessPass CPT
 *
 * 註冊課程權限包自訂文章類型 pc_access_pass（Issue #252）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\AccessPass\Core;

use J7\PowerCourse\Plugin;

/**
 * Class CPT
 */
final class CPT {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const POST_TYPE = 'pc_access_pass';

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register_cpt' ] );
	}

	/**
	 * 註冊 pc_access_pass CPT
	 *
	 * 設計決策（Issue #252）：
	 * - hierarchical=false：權限包無父子關係
	 * - public=false：後台管理型 CPT，站長透過 React SPA 管理，不需前台 archive / single 頁
	 * - show_ui 僅在開發環境（Plugin::$is_local）顯示，正式環境僅透過 React SPA 管理
	 * - show_in_rest=true：前端 React SPA 透過 REST API 操作權限包
	 * - supports 含 custom-fields：範圍 / 期限 / 狀態以 postmeta 儲存
	 *   （scope_type / limit_mode / limit_value / limit_unit / access_pass_status / scope_term_ids / scope_course_ids）
	 */
	public static function register_cpt(): void {
		$labels = [
			'name'                  => \esc_html__( 'Access passes', 'power-course' ),
			'singular_name'         => \esc_html__( 'Access pass', 'power-course' ),
			'add_new'               => \esc_html__( 'Add new', 'power-course' ),
			'add_new_item'          => \esc_html__( 'Add new access pass', 'power-course' ),
			'edit_item'             => \esc_html__( 'Edit access pass', 'power-course' ),
			'new_item'              => \esc_html__( 'New access pass', 'power-course' ),
			'view_item'             => \esc_html__( 'View access pass', 'power-course' ),
			'view_items'            => \esc_html__( 'View access passes', 'power-course' ),
			'search_items'          => \esc_html__( 'Search access passes', 'power-course' ),
			'not_found'             => \esc_html__( 'No access passes found', 'power-course' ),
			'not_found_in_trash'    => \esc_html__( 'No access passes found in trash', 'power-course' ),
			'all_items'             => \esc_html__( 'All access passes', 'power-course' ),
			'archives'              => \esc_html__( 'Access pass archives', 'power-course' ),
			'attributes'            => \esc_html__( 'Access pass attributes', 'power-course' ),
			'menu_name'             => \esc_html__( 'Access passes', 'power-course' ),
			'filter_items_list'     => \esc_html__( 'Filter access passes list', 'power-course' ),
			'items_list_navigation' => \esc_html__( 'Access passes list navigation', 'power-course' ),
			'items_list'            => \esc_html__( 'Access passes list', 'power-course' ),
		];

		$args = [
			'label'                 => \esc_html__( 'Access passes', 'power-course' ),
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
			'menu_icon'             => 'dashicons-tickets-alt',
			'capability_type'       => 'post',
			'supports'              => [ 'title', 'custom-fields', 'author' ],
			'taxonomies'            => [],
			'rest_controller_class' => 'WP_REST_Posts_Controller',
		];

		\register_post_type( self::POST_TYPE, $args );
	}
}
