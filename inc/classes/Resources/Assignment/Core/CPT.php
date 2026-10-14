<?php
/**
 * Assignment CPT
 *
 * 註冊章節作業自訂文章類型 pc_assignment（Issue #131，DEC-05）。
 */

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Assignment\Core;

use J7\PowerCourse\Plugin;

/**
 * Class CPT
 */
final class CPT {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const POST_TYPE = 'pc_assignment';

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register_cpt' ] );
	}

	/**
	 * 註冊 pc_assignment CPT（可重複呼叫，後者覆蓋前者）
	 *
	 * 設計決策（DEC-05）：
	 * - public / publicly_queryable=false、exclude_from_search=true：作業說明是付費內容，不提供前台單頁與搜尋
	 * - show_in_rest=false：避免經 /wp/v2/pc_assignment 外洩作業說明（R-05），一律走 power-course/assignments
	 * - hierarchical=false：post_parent 指向 pc_chapter，作業本身沒有父子關係
	 * - show_ui 僅在開發環境顯示；show_in_menu 一律 false，站長透過 React SPA 管理
	 * - supports：title（標題）、editor（說明）、custom-fields（題型設定 postmeta）、page-attributes（menu_order）
	 */
	public static function register_cpt(): void {
		$args = [
			'label'               => \esc_html__( 'Assignments', 'power-course' ),
			'labels'              => [
				'name'          => \esc_html__( 'Assignments', 'power-course' ),
				'singular_name' => \esc_html__( 'Assignment', 'power-course' ),
			],
			'description'         => '',
			'public'              => false,
			'hierarchical'        => false,
			'exclude_from_search' => true,
			'publicly_queryable'  => false,
			'show_ui'             => Plugin::$is_local,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'can_export'          => true,
			'delete_with_user'    => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'capability_type'     => 'post',
			'supports'            => [ 'title', 'editor', 'custom-fields', 'page-attributes' ],
			'taxonomies'          => [],
		];

		\register_post_type( self::POST_TYPE, $args );
	}
}
