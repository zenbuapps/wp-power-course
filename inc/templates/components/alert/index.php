<?php
/**
 * Alert component
 */

$default_args = [
	'type'    => 'info', // error, success, warning, info
	'message' => '您還沒購買此課程，無法上課，前往購買',
	'buttons' => '',
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'type'    => $alert_type,
	'message' => $message,
	'buttons' => $buttons,
] = $args;

$component_class = "pc-alert-{$alert_type}";

printf(
	/*html*/'
	<div role="alert" class="mb-4 pc-alert %1$s">
		<svg
			xmlns="http://www.w3.org/2000/svg"
			fill="none"
			viewBox="0 0 24 24"
			class="stroke-info size-6 shrink-0">
			<path
				stroke-linecap="round"
				stroke-linejoin="round"
				stroke-width="2"
				d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<span>%2$s</span>
		<div>
			%3$s
		</div>
	</div>
	',
	$component_class,
	$message,
	$buttons
);
