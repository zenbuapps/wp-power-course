<?php
/**
 * Course Info component
 */

use J7\PowerCourse\Plugin;

/**
 * @var array{icon:string, label:string, value:string}[] $args
 */
$items = $args;

// @phpstan-ignore-next-line
if ( ! is_array( $items ) ) {
	echo 'items 必須是陣列';
	$items = [];
}

echo '<div class="w-full grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-6">';
foreach ( $items as $index => $item ) :
	printf(
		/*html*/'
        <div class="flex items-center gap-3">
					<div class="pc-badge pc-badge-primary size-8 flex items-center justify-center">
		        %1$s
          </div>
					<div>
							%2$s
					</div>
					<div class="font-semibold">
							%3$s
					</div>
        </div>
        ',
		Plugin::safe_get(
			'icon/' . $item['icon'],
			[
				'class' => 'size-4',
				'color' => '#ffffff',
			],
			false,
			false
		),
		$item['label'],
		$item['value']
	);
endforeach;
echo '</div>';
