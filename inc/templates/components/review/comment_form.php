<?php
/**
 * Comment Form
 */

use J7\PowerCourse\Plugin;

$commenter    = wp_get_current_commenter();
$comment_form = [
	'title_reply'         => sprintf(/*html*/'<p class="text-base-content text-base font-bold mb-0">%s</p>', __( 'Add a review', 'woocommerce' )),
	'comment_notes_after' => '',
	'label_submit'        => esc_html__( 'Submit', 'woocommerce' ),
	'logged_in_as'        => '',
	'comment_field'       => '',
	'submit_field'        => sprintf(
		/*html*/'<div class="text-right">%s</div>',
		Plugin::load_template(
			'button',
			[
				'size'     => 'sm',
				'children' => \esc_html__( 'Submit', 'power-course' ),
				'class'    => 'px-4 pc-btn-primary text-white',
				'attr'     => 'name="submit" type="submit"',
			],
			false,
			),
	),
];


$comment_form['comment_field'] = sprintf(
/*html*/'
<div class="mb-2">%1$s</div>
<textarea class="mb-2 rounded h-24 bg-base-100" id="comment" name="comment" rows="4"></textarea>
',
Plugin::load_template(
	'rate',
	[
		'name'     => 'rating',
		'value'    => 5,
		'disabled' => false,
		'half'     => false,
	],
	false
	)
);



/** @var \WC_Product $product */
comment_form( $comment_form, $product->get_id() );
