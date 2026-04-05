<?php
/**
 * Rate component
 */

$default_args = [
	'show_before' => false, // 是否顯示前面的文字
	'count'       => 5, // 總共幾個星星
	'value'       => 3.7, // 有幾個星星是填滿的
	'total'       => null, // 有幾個評論 null | int
	'disabled'    => true, // 是否禁用
	'name'        => 'rating-10',
	'half'        => true, // 是否允許半星
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'show_before' => $show_before,
	'count'       => $count,
	'value'       => $value,
	'total'       => $total,
	'disabled'    => $disabled,
	'name'        => $name,
	'half'        => $half,
] = $args;

$rest = fmod( $value, 1 ); // 取餘數

$fill_star_num    = ( (int) $value ) + ( $rest >= 0.8 ? 1 : 0 );
$half_star_num    = ( $rest > 0.2 && $rest < 0.8 ) ? 1 : 0;
$outline_star_num = $count - $fill_star_num - $half_star_num;
$cursor           = $disabled ? 'cursor-default' : '';
$disabled         = $disabled ? 'disabled' : '';


$icons_html = sprintf(
/*html*/'<div class="pc-rating pc-rating-sm %2$s">',
$name,
$half ? 'pc-rating-half' : ''
);
for ( $i = 1; $i <= $count; $i++ ) {
	$half_checked = ( $i === $fill_star_num + 1 && $half_star_num === 1 ) ? 'checked="checked"' : '';
	$full_checked = ( $i === $fill_star_num && $half_star_num === 0 ) ? 'checked="checked"' : '';

	if ( $half ) {
		$icons_html .= sprintf(
			/*html*/'<input type="radio" value="%1$s" name="%2$s" class="pc-mask pc-mask-star-2 pc-mask-half-1 %3$s" %4$s %5$s />',
		$i - 0.5,
		$name,
		$cursor,
		$half_checked,
		$disabled
		);
		$icons_html .= sprintf(
		/*html*/'<input type="radio" value="%1$s" name="%2$s" class="pc-mask pc-mask-star-2 pc-mask-half-2 %3$s" %4$s %5$s />',
		$i,
		$name,
		$cursor,
		$full_checked,
		$disabled
		);
	} else {
		$icons_html .= sprintf(
			/*html*/'<input type="radio" value="%1$s" name="%2$s" class="pc-mask pc-mask-star %3$s" %4$s %5$s />',
			$i,
			$name,
			$cursor,
			$full_checked,
			$disabled
		);
	}
}
$icons_html .= '</div>';

printf(
/*html*/'
<div class="flex items-center gap-1 whitespace-nowrap">
	%1$s
	%2$s
	%3$s
</div>
',
$show_before ? "<span class=\"text-xl font-bold\">{$value}</span>" : '',
$icons_html,
null !== $total ? "<span>({$total})</span>" : ''
);
