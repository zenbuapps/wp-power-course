<?php
/**
 * Classroom > body > Progress
 */

use J7\PowerCourse\Utils\Course as CourseUtils;

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
	'label'   => '上課進度',
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
	'label'   => $label,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

$progress = CourseUtils::get_course_progress( $product );

printf(
	/*html*/'
	<progress class="pc-progress pc-progress-primary flex-1" value="%2$s" max="100"></progress>
	<div class="flex gap-2 items-center">
		<span class="text-gray-400 text-xs text-nowrap">%1$s</span>
		<span class="text-primary text-xs text-nowrap font-bold">%2$s%%</span>
	</div>

',
$label,
	$progress
);
