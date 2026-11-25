<?php
/**
 * Video 404 component
 *
 * 學員一律看到通用說明；管理員 / 該課程講師額外看到原因與修正路徑（Issue #299）。
 *
 * 可傳入參數：
 * - reason：'bunny_settings'（網站 Bunny 串接未設定完成）| 'no_video'（這裡還沒選影片）| ''（不顯示原因）
 * - course_id：用來判斷目前使用者是否為該課程講師（0 則只看管理權限）
 */

$default_args = [
	'class'     => 'bg-primary aspect-video w-full text-white flex flex-col items-center justify-center',
	'title'     => esc_html__( 'Video not found', 'power-course' ),
	'message'   => esc_html__( 'This video can\'t play right now. Try again later, or contact course support if it keeps happening.', 'power-course' ),
	'reason'    => '',
	'course_id' => 0,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
		'class'     => $class,
		'title'     => $video_title,
		'message'   => $message,
		'reason'    => $reason,
		'course_id' => $course_id,
] = $args;

// 只有管理員或該課程講師看得到原因說明
$can_see_reason = \current_user_can( 'manage_woocommerce' );
if ( ! $can_see_reason && $course_id && \is_user_logged_in() ) {
	$teacher_ids    = \get_post_meta( (int) $course_id, 'teacher_ids', false );
	$current_user_id = \get_current_user_id();
	foreach ( is_array( $teacher_ids ) ? $teacher_ids : [] as $teacher_id ) {
		if ( is_numeric( $teacher_id ) && (int) $teacher_id === $current_user_id ) {
			$can_see_reason = true;
			break;
		}
	}
}

$reason_message = '';
if ( $can_see_reason ) {
	$reason_message = match ( $reason ) {
		'bunny_settings' => esc_html__( '[Admins and instructors only] Bunny is not set up. Fill in all 3 fields in Power Course > Bunny media library > Bunny settings and save.', 'power-course' ),
		'no_video'       => esc_html__( '[Admins and instructors only] No video selected. Choose one on the course or chapter edit page and save.', 'power-course' ),
		default          => '',
	};
}

printf(
		/*html*/'
	<div class="%1$s">
		<p class="font-bold text-4xl mb-2">%2$s</p>
		<p class="text-base">%3$s</p>
		%4$s
	</div>
	',
		$class,
		$video_title,
		$message,
		$reason_message ? '<p class="text-sm mt-4 px-8 max-w-2xl opacity-90">' . $reason_message . '</p>' : ''
	);

return;
