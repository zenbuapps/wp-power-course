<?php
/**
 * QA of the course.
 */

$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args( $args, $default_args );

[
	'product' => $product,
] = $args;

if ( ! ( $product instanceof \WC_Product ) ) {
	return;
}

$product_id = $product->get_id();

$qa_list = \get_post_meta( $product_id, 'qa_list', true );

if ( ! is_array( $qa_list ) ) {
	$qa_list = [];
}

// 過濾出有效的問答項目（question / answer 皆存在）
$valid_qa_list = array_values(
	array_filter(
		$qa_list,
		fn( $qa ) => is_array( $qa ) && isset( $qa['question'], $qa['answer'] )
	)
);

if ( ! $valid_qa_list ) {
	return;
}

// 排他 accordion：以 data-pc-qa-exclusive 容器包覆，由前台 JS（qaAccordion）控制「同時只開一個」
echo '<div class="pc-qa-list" data-pc-qa-exclusive>';

foreach ( $valid_qa_list as $index => $qa ) {
	/** @var array{question?: string, answer?: string} $qa */

	// 載入時預設僅展開第一個項目
	$is_first = 0 === $index;

	printf(
		/*html*/'
	<div class="pc-collapse pc-collapse-arrow rounded-none mb-1">
		<input type="checkbox" class="pc-qa-item__toggle"%3$s />
		<div class="pc-collapse-title text-sm font-semibold bg-base-300 py-3 flex items-center justify-between">
			<span>%1$s</span>
		</div>
		<div class="pc-collapse-content bg-base-200 p-0">
			<div class="text-sm border-t-0 border-x-0 border-b border-gray-200 border-solid py-6 flex flex-col px-8 leading-7">
				%2$s
			</div>
		</div>
	</div>
',
		(string) $qa['question'],
		\wpautop( (string) $qa['answer'] ),
		$is_first ? ' checked="checked"' : ''
	);
}

echo '</div>';
