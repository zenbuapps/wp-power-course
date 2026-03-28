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
foreach ( $qa_list as $qa ) {
	/** @var array{question?: string, answer?: string} $qa */
	if (!isset($qa['question']) || !isset($qa['answer'])) {
		continue;
	}

	printf(
		/*html*/'
	<div class="pc-collapse pc-collapse-arrow rounded-none mb-1">
		<input type="checkbox" checked="checked" />
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
		\wpautop( (string) $qa['answer'])
	);
}
