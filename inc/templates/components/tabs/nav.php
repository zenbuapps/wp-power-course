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


echo '<div class="bg-base-100 flex gap-0 justify-between [&_.active]:!border-primary [&_.active]:text-primary [&_div:hover]:!border-primary [&_div:hover]:text-primary text-base-content/30">';

foreach ( $course_tabs as $key => $course_tab ) {
	printf(
		/*html*/'<div id="tab-nav-%1$s" class="cursor-pointer w-full text-center py-2 px-2 lg:px-8 relative -bottom-[3px] font-normal lg:font-semibold transition text-sm lg:text-base duration-300 ease-in-out %2$s" style="border-bottom: 2px solid;">%3$s</div>',
		$key,
		$default_active_key === $key ? 'active' : '',
		$course_tab['label']
		);
}

echo '</div>';
