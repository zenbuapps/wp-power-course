<?php
/**
 * @var mixed $args
 */

$default_args = [
	'course_tabs'        => [],
	'default_active_key' => null,
];

// @phpstan-ignore-next-line
$args = wp_parse_args( $args, $default_args );

/** @var array{course_tabs: array<string, array{label: string, content: string, disabled?: boolean}>, default_active_key?: string|null} $args */
$course_tabs        = $args['course_tabs'];
$course_tabs        = is_array($course_tabs) ? $course_tabs : []; // @phpstan-ignore-line
$default_active_key = $args['default_active_key'] ?? array_key_first($course_tabs);

echo '<div class="[&_.active]:!tw-block mb-12">';

foreach ( $course_tabs as $key => $course_tab ) {
	printf(
			'<div id="tab-content-%1$s" class="tw-hidden py-8 %2$s">%3$s</div>',
			$key,
			$default_active_key === $key ? 'active' : '',
			$course_tab['content']
		);
}

echo '</div>';
