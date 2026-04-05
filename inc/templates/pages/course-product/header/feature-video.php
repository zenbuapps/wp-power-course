<?php
/**
 * Feature video for course product
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Utils\Base;

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

$product_id   = $product->get_id();
$product_name = $product->get_name();
$teacher_ids  = \get_post_meta( $product_id, 'teacher_ids', false );
if ( ! is_array( $teacher_ids ) ) {
	$teacher_ids = [];
}

/**
 * @var array{type: string, id: string, meta: ?array<mixed>} $feature_video
 */
$feature_video = \get_post_meta( $product_id, 'feature_video', true );
$image_id      = $product->get_image_id();
$image_url     = $image_id ? \wp_get_attachment_image_url( (int) $image_id, 'full' ) : Base::DEFAULT_IMAGE;

// @phpstan-ignore-next-line
$video_type = $feature_video['type'] ?? 'none';

if ( 'none' !== $video_type ) {
	Plugin::load_template(
		'video',
		[
			'video_info'     => $feature_video,
			'class'          => 'md:rounded-2xl',
			'thumbnail_url'  => $image_url,
			'hide_watermark' => true,
			'chapter_id'     => $product_id,
			'video_slot'     => 'feature_video',
		]
	);
} else {
	printf(
		/*html*/'<div class="group w-full md:rounded-2xl aspect-video overflow-hidden">
			<img src="%1$s" class="w-full h-full object-cover group-hover:scale-105 duration-500 transition ease-in-out" alt="%2$s" loading="lazy" decoding="async">
			</div>',
		$image_url,
		$product_name
	);
}
