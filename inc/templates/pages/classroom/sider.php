<?php
/**
 * Sidebar for classroom
 */

use J7\PowerCourse\Plugin;
use J7\PowerCourse\FrontEnd\MyAccount;


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

printf(
	/*html*/'
	<div class="pc-drawer lg:pc-drawer-open h-0">
		<input id="pc-classroom-drawer" type="checkbox" class="pc-drawer-toggle" />
		<div class="pc-drawer-side z-40">
			<div id="pc-sider" class="w-4/5 px-2 max-w-[25rem] lg:w-[25rem] h-screen bg-base-100 z-40"
				style="border-right: 1px solid var(--fallback-bc,oklch(var(--bc)/.1));position:fixed;left:0px">
				<div id="pc-sider__main" class="h-full flex flex-col [&_.pc-sider-chapters]:flex-1 [&_.pc-sider-chapters]:pb-12">
					%1$s
					<div class="py-4 pl-9 absolute bottom-0 left-0 w-full bg-base-100">
						<a
							href="%2$s"
							class="hover:opacity-75 transition duration-300 flex gap-4 items-center"
						>
							<img class="size-6" src="%3$s" loading="lazy" decoding="async" />
							<span class="text-gray-400 font-light">
									回《我的課程》
							</span>
						</a>
					</div>
				</div>
			</div>
			<label for="pc-classroom-drawer" aria-label="close sidebar" class="pc-drawer-overlay w-full h-full"></label>
		</div>
	</div>
',
	Plugin::load_template(
		'classroom/chapters',
		[
			'product' => $product,
		],
		false
		),
	\wc_get_account_endpoint_url( MyAccount::COURSES_ENDPOINT ),
	Plugin::$url . '/inc/assets/images/back.svg',
);
