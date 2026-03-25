<?php
/**
 * Classroom > body > Progress
 */

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Utils\Base;

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
<div class="flex gap-2 items-center">
	<span class="text-gray-400 text-sm text-nowrap">%1$s</span>
	<span class="text-sm text-nowrap font-bold" style="color:%3$s">%2$s%%</span>
	<progress class="pc-progress pc-progress-primary flex-1" value="%2$s" max="100"></progress>
</div>',
$label,
	$progress,
	Base::PRIMARY_COLOR
);
