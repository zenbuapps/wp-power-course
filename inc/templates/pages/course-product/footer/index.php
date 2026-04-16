<?php
/**
 * Footer for course product
 */

use J7\PowerCourse\Plugin;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

$product_id  = $product->get_id();
$teacher_ids = \get_post_meta( $product_id, 'teacher_ids', false );
if ( ! is_array( $teacher_ids ) ) {
	$teacher_ids = [];
}

$trial_video = \get_post_meta( $product_id, 'trial_video', true );
/** @var array{type: string, id: string, meta: ?array<string, mixed>}|array<empty> $trial_video */
$trial_video = is_array( $trial_video ) ? $trial_video : [];
$video_type  = $trial_video['type'] ?? 'none';

if ( 'none' !== $video_type ) {
	printf(
	/*html*/'
<div class="mb-12">
	%1$s
	<div class="max-w-[30rem]">
		%2$s
	</div>
</div>
',
	Plugin::load_template(
	'typography/title',
	[
		'value' => esc_html__( 'Course preview', 'power-course' ),
		'class' => 'mb-8 text-xl font-normal text-base-content',
	],
	false
	),
	Plugin::load_template(
	'video',
	[
		'video_info'     => $trial_video,
		'hide_watermark' => true,
		'chapter_id'     => $product_id,
		'video_slot'     => 'trial_video',
	],
	false
	)
	);
}

if ( (bool) $teacher_ids ) {
	Plugin::load_template(
		'typography/title',
		[
			'value' => esc_html__( 'About the instructor', 'power-course' ),
			'class' => 'mb-8 text-xl font-normal text-base-content',
		]
	);
}

foreach ( $teacher_ids as $teacher_id ) {
	$teacher = \get_user_by( 'id', (string) $teacher_id );
	echo '<div class="mb-12">';
	Plugin::load_template(
		'user/about',
		[
			'user' => $teacher,
		]
		);
	echo '</div>';
}
