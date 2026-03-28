<?php
/**
 * Countdown component
 */

$default_args = [
	'type'       => 'lg', // sm, lg,lg-col
	'item_class' => '', // bg-neutral rounded-box text-neutral-content p-2
	'timestamp'  => time() + 8 * 60 * 60 + 15 * 24 * 60 * 60,
	'format'     => [ '天', '時', '分', '秒' ],
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'type' => $countdown_type,
	'item_class' => $item_class,
	'timestamp' => $timestamp,
	'format' => $format,
] = $args;

$base_class = match ($countdown_type) {
	'lg' => 'flex flex-row items-end',
	'lg-col' => 'flex flex-col p-2',
	default => '',
};

$item_class .= ' ' . $base_class;

if ('sm' === $countdown_type) {
	printf(
		/*html*/'
		<span class="fixed-day pc-countdown-component__hour font-mono -mr-2"></span>
		<div data-timestamp="%1$s" class="pc-countdown-component %2$s">
			<span class="pc-countdown flex items-center gap-x-1" style="line-height:1em !important">
				<span class="pc-countdown-component__day font-mono" style="--value:0;"></span>
				%3$s
				<span class="pc-countdown-component__hour font-mono" style="--value:0;"></span>
				%4$s
				<span class="pc-countdown-component__min font-mono" style="--value:0;"></span>
				%5$s
				<span class="pc-countdown-component__sec font-mono" style="--value:0;"></span>
				%6$s
			</span>
		</div>',
		$timestamp,
		$item_class,
		$format[0],
		$format[1],
		$format[2],
		$format[3]
	);
	return;
}



printf(
	/*html*/'
	<div data-timestamp="%1$s" class="pc-countdown-component grid auto-cols-max grid-flow-col gap-4 text-center">
		<div class="%2$s">
			<span class="pc-countdown" style="font-size:2rem">
				<span class="pc-countdown-component__day font-mono" style="--value:0;"></span>
			</span>
			%3$s
		</div>
		<div class="%2$s">
			<span class="pc-countdown" style="font-size:2rem">
				<span class="pc-countdown-component__hour font-mono" style="--value:0;"></span>
			</span>
			%4$s
		</div>
		<div class="%2$s">
			<span class="pc-countdown" style="font-size:2rem">
				<span class="pc-countdown-component__min font-mono" style="--value:0;"></span>
			</span>
			%5$s
		</div>
		<div class="%2$s">
			<span class="pc-countdown" style="font-size:2rem">
				<span class="pc-countdown-component__sec font-mono" style="--value:0;"></span>
			</span>
			%6$s
		</div>
	</div>',
	$timestamp,
	$item_class,
	$format[0],
	$format[1],
	$format[2],
	$format[3]
);

return;
