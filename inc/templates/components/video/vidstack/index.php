<?php

/**
 * Vidstack component 用 React 渲染，見 /js/src/App2.tsx
 * 可以撥放 youtube, vimeo, HLS (bunny) 影片
 *
 * @see https://www.vidstack.io
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\Powerhouse\Settings\Model\Settings as PowerhouseSettings;
use J7\PowerCourse\Resources\Settings\Model\Settings;

$default_args = [
	'class'          => 'rounded-xl',
	'thumbnail_url'  => '',
	'hide_watermark' => false,
	'video_info'     => [
		'type' => 'youtube',
		'id'   => '',
		'meta' => [],
	],
	'next_post_url'  => '',
	'chapter_id'     => 0,
	'course_id'      => 0,
	'is_finished'    => false,
	'video_slot'     => 'chapter_video',
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args($args, $default_args);

/**
 * @var array{type: string, id: string, meta: ?array<string, mixed>} $video_info
 */
[
	'class'          => $class,
	'thumbnail_url'  => $thumbnail_url,
	'hide_watermark' => $hide_watermark,
	'video_info'     => $video_info,
	'next_post_url'  => $next_post_url,
	'chapter_id'     => $chapter_id,
	'course_id'      => $course_id,
	'is_finished'    => $is_finished,
	'video_slot'     => $video_slot,
] = $args;
/** @var string $next_post_url */
/** @var string $video_slot */

[
	'id'   => $video_id,
] = $video_info;

$bunny_cdn_hostname = PowerhouseSettings::instance()->bunny_cdn_hostname;

$src = match ($video_info['type']) {
	'youtube' => "youtube/{$video_id}",
	'vimeo' => "vimeo/{$video_id}",
	'bunny-stream-api' => "https://{$bunny_cdn_hostname}/{$video_id}/playlist.m3u8",
	default => '',
};

if (!$video_id || !$src || ( !$bunny_cdn_hostname && 'bunny-stream-api' === $video_info['type'] )) {

	Plugin::load_template(
		'video/404',
		[
			'message' => esc_html__('Missing video_id | src. Please contact the instructor.', 'power-course'),
		]
	);
	return;
}

$settings = Settings::instance();
$watermark_qty      = $hide_watermark ? '0' : (string) $settings->pc_watermark_qty;
$watermark_color    = $settings->pc_watermark_color;
$watermark_interval = $settings->pc_watermark_interval;
$watermark_text     = ChapterUtils::get_formatted_watermark_text();

$autoplay = 'no';
if (isset($_GET['autoplay'])) {
	if ('yes' === $_GET['autoplay']) {
		$autoplay = 'yes';
	}
}

if ($next_post_url) {
	$next_post_url = \add_query_arg('autoplay', 'yes', $next_post_url);
}

// 讀取字幕列表，根據 video_slot 動態決定 meta key.
/** @var int $chapter_id */
$subtitles_attr = '';
if ($chapter_id > 0) {
	$meta_key      = "pc_subtitles_{$video_slot}";
	$raw_subtitles = \get_post_meta($chapter_id, $meta_key, true);

	// v1.3 → v1.4+ 向下相容：trial_video_0 讀不到時 fallback 讀舊 pc_subtitles_trial_video.
	// （純讀，不做 migration；migration 由 Subtitle Service 在後台操作時觸發）.
	$is_empty_subtitles = ! \is_array($raw_subtitles) || empty($raw_subtitles);
	if ($is_empty_subtitles && 'trial_video_0' === $video_slot) {
		$raw_subtitles = \get_post_meta($chapter_id, 'pc_subtitles_trial_video', true);
	}

	if (\is_array($raw_subtitles) && ! empty($raw_subtitles)) {
		$subtitles_json = \wp_json_encode($raw_subtitles);
		if ($subtitles_json) {
			$subtitles_attr = \sprintf(' data-subtitles="%s"', \esc_attr($subtitles_json));
		}
	}
}

/** @var int $course_id */
/** @var bool $is_finished */
$is_finished_attr = $is_finished ? 'true' : 'false';

printf(
	/*html*/
	'
<div class="pc-vidstack relative aspect-video %1$s !overflow-hidden"
	data-src="%2$s"
	data-thumbnail_url="%3$s"
	data-watermark_text="%4$s"
	data-watermark_qty="%5$s"
	data-watermark_color="%6$s"
	data-watermark_interval="%7$s"
	data-next_post_url="%8$s"
	data-autoplay="%9$s"
	data-chapter_id="%11$s"
	data-course_id="%12$s"
	data-is_finished="%13$s"
	data-video_type="%14$s"
	%10$s
>
	<div class="z-10 animate-pulse aspect-video bg-gray-200 text-gray-400 tracking-widest flex items-center justify-center %1$s">%15$s</div>
</div>
',
	$class,
	$src,
	$thumbnail_url,
	$watermark_text,
	$watermark_qty,
	$watermark_color,
	$watermark_interval,
	(string) $next_post_url,
	$autoplay,
	$subtitles_attr,
	(string) $chapter_id,
	(string) $course_id,
	$is_finished_attr,
	$video_info['type'],
	esc_html__('Loading video...', 'power-course'),
);
