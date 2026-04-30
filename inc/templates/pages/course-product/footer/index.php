<?php
/**
 * Footer for course product
 *
 * Issue #10：支援多影片試看
 * - 0 部 → 不渲染區塊
 * - 1 部 → 直接渲染單一影片（與舊版行為一致，不載入 Swiper）
 * - 2~6 部 → 渲染 Swiper 輪播容器
 *
 * 同時做 lazy migration：若僅有舊的 trial_video（單一物件）postmeta，自動包裝為陣列。
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Templates\Ajax;

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

/**
 * 讀取 trial_videos（含 lazy migration）
 *
 * @return array<int, array{type: string, id: string, meta?: array<string, mixed>}>
 */
$resolve_trial_videos = static function ( int $product_id ): array {
	$raw = \get_post_meta( $product_id, 'trial_videos', true );
	if ( is_string( $raw ) && '' !== $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( $decoded, 'is_array' ) );
		}
	}
	if ( is_array( $raw ) && ! empty( $raw ) ) {
		return array_values( array_filter( $raw, 'is_array' ) );
	}

	$legacy = \get_post_meta( $product_id, 'trial_video', true );
	if ( is_array( $legacy ) && isset( $legacy['type'] ) && 'none' !== $legacy['type'] ) {
		return [ $legacy ];
	}
	return [];
};

$trial_videos = $resolve_trial_videos( $product_id );
$video_count  = count( $trial_videos );

if ( $video_count > 0 ) {
	$title_html = (string) Plugin::load_template(
		'typography/title',
		[
			'value' => esc_html__( 'Course preview', 'power-course' ),
			'class' => 'mb-8 text-xl font-normal text-base-content',
		],
		false
	);

	if ( 1 === $video_count ) {
		// 單一影片：保持與舊版相同行為，不載入 Swiper
		printf(
			/*html*/'
<div class="mb-12">
	%1$s
	<div class="max-w-[30rem]">
		%2$s
	</div>
</div>
',
			$title_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			(string) Plugin::load_template(
				'video',
				[
					'video_info'     => $trial_videos[0],
					'hide_watermark' => true,
					'chapter_id'     => $product_id,
					'video_slot'     => 'trial_video',
				],
				false
			)
		);
	} else {
		// 2~6 部：載入 Swiper 輪播
		Ajax::enqueue_swiper_assets();

		$slides_html = '';
		foreach ( $trial_videos as $video ) {
			$slides_html .= sprintf(
				/*html*/'<div class="swiper-slide">%s</div>',
				(string) Plugin::load_template(
					'video',
					[
						'video_info'     => $video,
						'hide_watermark' => true,
						'chapter_id'     => $product_id,
						'video_slot'     => 'trial_video',
					],
					false
				)
			);
		}

		printf(
			/*html*/'
<div class="mb-12">
	%1$s
	<div class="max-w-[30rem]">
		<div class="swiper pc-trial-videos-swiper" data-pc-trial-videos-swiper="1">
			<div class="swiper-wrapper">%2$s</div>
			<div class="swiper-pagination"></div>
			<div class="swiper-button-prev"></div>
			<div class="swiper-button-next"></div>
		</div>
	</div>
</div>
',
			$title_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$slides_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}
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
