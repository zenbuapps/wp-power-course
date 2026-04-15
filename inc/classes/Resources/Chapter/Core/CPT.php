<?php

declare( strict_types=1 );

namespace J7\PowerCourse\Resources\Chapter\Core;

use J7\PowerCourse\Plugin;

/** Class CPT */
final class CPT {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const POST_TYPE = 'pc_chapter';

	/** Constructor */
	public function __construct() {
		\add_action( 'init', [ __CLASS__, 'register_cpt' ] );
		\add_filter('option_elementor_cpt_support', [ $this, 'add_elementor_cpt_support' ]);
	}

	/**
	 * Register power-course custom post type
	 */
	public static function register_cpt(): void {
		$labels = [
			'name'                     => \esc_html__( 'chapter', 'power-course' ),
			'singular_name'            => \esc_html__( 'chapter', 'power-course' ),
			'add_new'                  => \esc_html__( 'Add new', 'power-course' ),
			'add_new_item'             => \esc_html__( 'Add new item', 'power-course' ),
			'edit_item'                => \esc_html__( 'Edit', 'power-course' ),
			'new_item'                 => \esc_html__( 'New', 'power-course' ),
			'view_item'                => \esc_html__( 'View', 'power-course' ),
			'view_items'               => \esc_html__( 'View', 'power-course' ),
			'search_items'             => \esc_html__( 'Search power-course', 'power-course' ),
			'not_found'                => \esc_html__( 'Not Found', 'power-course' ),
			'not_found_in_trash'       => \esc_html__( 'Not found in trash', 'power-course' ),
			'parent_item_colon'        => \esc_html__( 'Parent item', 'power-course' ),
			'all_items'                => \esc_html__( 'All', 'power-course' ),
			'archives'                 => \esc_html__( 'chapter archives', 'power-course' ),
			'attributes'               => \esc_html__( 'chapter attributes', 'power-course' ),
			'insert_into_item'         => \esc_html__( 'Insert to this power-course', 'power-course' ),
			'uploaded_to_this_item'    => \esc_html__( 'Uploaded to this power-course', 'power-course' ),
			'featured_image'           => \esc_html__( 'Featured image', 'power-course' ),
			'set_featured_image'       => \esc_html__( 'Set featured image', 'power-course' ),
			'remove_featured_image'    => \esc_html__( 'Remove featured image', 'power-course' ),
			'use_featured_image'       => \esc_html__( 'Use featured image', 'power-course' ),
			'menu_name'                => \esc_html__( 'chapter', 'power-course' ),
			'filter_items_list'        => \esc_html__( 'Filter power-course list', 'power-course' ),
			'filter_by_date'           => \esc_html__( 'Filter by date', 'power-course' ),
			'items_list_navigation'    => \esc_html__( 'chapter list navigation', 'power-course' ),
			'items_list'               => \esc_html__( 'chapter list', 'power-course' ),
			'item_published'           => \esc_html__( 'chapter published', 'power-course' ),
			'item_published_privately' => \esc_html__( 'chapter published privately', 'power-course' ),
			'item_reverted_to_draft'   => \esc_html__( 'chapter reverted to draft', 'power-course' ),
			'item_scheduled'           => \esc_html__( 'chapter scheduled', 'power-course' ),
			'item_updated'             => \esc_html__( 'chapter updated', 'power-course' ),
		];

		$args = [
			'label'                 => \esc_html__( 'chapter', 'power-course' ),
			'labels'                => $labels,
			'description'           => '',
			'public'                => true,
			'hierarchical'          => true,
			'exclude_from_search'   => false,
			'publicly_queryable'    => true,
			'show_ui'               => Plugin::$is_local,
			'show_in_nav_menus'     => Plugin::$is_local,
			'show_in_admin_bar'     => Plugin::$is_local,
			'show_in_rest'          => true,
			'can_export'            => true,
			'delete_with_user'      => false,
			'has_archive'           => false,
			'rest_base'             => '',
			'show_in_menu'          => Plugin::$is_local,
			'menu_position'         => 6,
			'menu_icon'             => 'dashicons-list-view',
			'capability_type'       => 'post',
			'supports'              => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'author', 'page-attributes' ],
			'taxonomies'            => [],
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'rewrite'               => [
				'slug' => 'classroom',
			],
		];

		\register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Add elementor cpt support
	 *
	 * @param array<string> $value Value.
	 *
	 * @return array<string>
	 */
	public function add_elementor_cpt_support( $value ): array {
		$value[] = self::POST_TYPE;
		return $value;
	}
}
