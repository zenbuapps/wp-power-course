<?php

/**
 * User About component
 */

$default_args = [
	'user' => wp_get_current_user(),
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args($args, $default_args);

[
	'user' => $user,
] = $args;


if (! ($user instanceof \WP_User)) {
	return;
}

$user_id = $user->ID;

$display_name = \J7\PowerCourse\Utils\User::get_formatted_name($user_id);

$user_avatar_url = \get_user_meta($user_id, 'user_avatar_url', true);

$user_avatar_url = $user_avatar_url ? $user_avatar_url : \get_avatar_url(
	$user->ID,
	[
		'size' => 200,
	]
);

$description = \wpautop($user->description);

$user_link = \get_author_posts_url($user_id);

printf(
	/*html*/
	'
<div class="flex gap-6 items-center mb-6">
	<div class="group rounded-full size-20 overflow-hidden">
		<img class="group-hover:scale-105 duration-500 transition ease-in-out w-full aspect-square object-cover" src="%1$s" loading="lazy" decoding="async"/>
	</div>
	<h4 class="text-xl font-semibold">%2$s</h4>
</div>

<div class="mb-6 max-w-[40rem] text-justify">%3$s</div>

<div class="hidden">
	<a target="_blank" href="%4$s"
		class="flex hover:opacity-75 whitespace-nowrap items-center text-sm text-base-content hover:text-base-content transition duration-300 ease-in-out">
		<span style="border-bottom: 1px solid #333">' . esc_html__('Go to instructor profile', 'power-course') . '</span>
		<svg class="size-4" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
			<path d="M10 7L15 12L10 17" stroke="#333" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
		</svg>
	</a>
</div>
',
	(string) $user_avatar_url,
	$display_name,
	$description,
	$user_link // TODO 等講師頁做好再開放，目前先 hidden 隱藏
);
