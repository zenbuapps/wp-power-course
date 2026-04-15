<?php
/**
 * Header for course product
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Course as CourseUtils;

$default_args = [
	'product'   => $GLOBALS['course'] ?? null,
	'show_link' => false,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product'   => $product,
	'show_link' => $show_link,
] = $args;

if ( !( $product instanceof \WC_Product ) ) {
	return;
}

$product_id = $product->get_id();
$product_name = $product->get_name();
$teacher_ids = \get_post_meta( $product_id, 'teacher_ids', false );
$is_popular = \get_post_meta( $product_id, 'is_popular', true ) === 'yes';
$is_featured = \get_post_meta( $product_id, 'is_featured', true ) === 'yes';
$show_join = \get_post_meta( $product_id, 'show_join', true ) === 'yes';
$show_review = \get_post_meta( $product_id, 'show_review', true ) === 'yes';

if ( !is_array( $teacher_ids ) ) {
	$teacher_ids = [];
}

?>
<div class="flex gap-12 flex-col md:flex-row mb-20">
	<div id="courses-product__feature-video" class="w-full md:w-[55%] px-0 z-40">
		<?php Plugin::load_template( 'course-product/header/feature-video' ); ?>
	</div>

	<div id="courses-product__feature-content" class="w-full md:w-[45%] px-4 md:px-0 flex flex-col justify-center"
		 style="opacity: 0;">
		<div class="mb-2 flex gap-x-4 gap-y-2 flex-wrap">
			<?php
			foreach ( $teacher_ids as $teacher_id ) {
				$teacher = \get_user_by( 'id', (int) $teacher_id );
				Plugin::load_template(
					'user',
					[
						'user' => $teacher,
					]
				);
			}
			?>
		</div>

		<h1 class="mb-6 text-xl md:text-[1.65rem] md:leading-[2.5rem] font-semibold text-base-content">
			<?php echo $product->get_name(); ?>
		</h1>

		<?php
		echo '<div class="flex gap-2 items-center mb-[10px] flex-wrap">';
		Plugin::load_template(
			'stock',
			[
				'product' => $product,
				'class'   => 'flex items-center h-6',
			]
		);

		Plugin::load_template(
			'stock/total_sales',
			[
				'product' => $product,
				'class'   => 'flex items-center h-6',
			]
		);

		if ( $is_popular ) {
			Plugin::load_template( 'badge/popular' );
		}
		if ( $is_featured ) {
			Plugin::load_template( 'badge/feature' );
		}
		if ( $show_join ) {
			Plugin::load_template( 'badge/join' );
		}
		echo '</div>';


		Plugin::load_template(
			'typography/paragraph/expandable',
			[
				'children' => \do_shortcode(
					\wpautop( $product->get_short_description() )
				),
			]
		);

		if ( $show_review ) {
			$rating = (float) $product->get_meta( 'custom_rating' );
			$review_count = $product->get_review_count();
			$extra_review_count = (int) $product->get_meta( 'extra_review_count' );
			Plugin::load_template(
				'rate',
				[
					'show_before' => true,
					'value'       => $rating,
					'total'       => $review_count + $extra_review_count,
				]
			);
		}

		$course_permalink_structure = CourseUtils::get_course_permalink_structure();
		if ( $show_link ) {
			echo '<div class="mt-6">';
			Plugin::load_template(
				'button',
				[
					'href'     => \site_url( "{$course_permalink_structure}/{$product->get_slug()}" ),
					'children' => esc_html__( '查看課程', 'power-course' ),
					'class'    => 'w-full text-white',
					'type'     => 'primary',
				]
			);
			echo '</div>';
		}


		?>
	</div>
</div>
