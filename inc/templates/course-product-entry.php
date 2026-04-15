<?php
/**
 * 課程銷售頁
 */

use J7\Powerhouse\Theme\Core\FrontEnd as Theme;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

global $post;
$product_id = $post->ID;
$product    = wc_get_product( $product_id );

if (!$product) {
	\wp_safe_redirect( home_url('/404') );
	exit;
}

$GLOBALS['course'] = $product;

\add_filter(
'body_class',
function ( $classes ) {
	$classes[] = 'courses-product'; // 添加背景色
	return $classes;
}
);

use J7\PowerCourse\Plugin;

$product_status = $product->get_status();
$can_edit       = \current_user_can( 'edit_product', $product->get_id() );

if ('draft' === $product_status && !$can_edit) {
	// 如果商品為草稿且用戶沒有權限編輯，就 redirect
	\wp_safe_redirect( home_url('/404') );
}

get_header();


if ('draft' === $product_status) {
	echo /*html*/'
	<div role="alert" class="flex justify-center items-center text-white text-xs bg-primary py-2">
		<svg
		xmlns="http://www.w3.org/2000/svg"
		fill="none"
		viewBox="0 0 24 24"
		class="size-4 shrink-0 stroke-current mr-2">
		<path
			stroke-linecap="round"
			stroke-linejoin="round"
			stroke-width="2"
			d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<span>課程尚未發布，目前為預覽模式</span>
	</div>';
}

echo '<div class="leading-7 text-base-content w-full mx-auto  px-0 md:px-6 text-base font-normal pt-0 lg:pt-[5rem] pb-[10rem]" style="max-width: 1200px;">';

// Header
Plugin::load_template( 'course-product/header', null, true, true );

echo '<div class="flex flex-col md:flex-row gap-6">';

// Body
Plugin::load_template( 'course-product/body', null, true, true );

// Sider
Plugin::load_template( 'course-product/sider', null, true, true );

echo '</div>';
echo '</div>';

printf(
/*html*/'
<dialog class="pc-already-bought-modal pc-modal">
  <div class="pc-modal-box">
    <h3 class="text-lg font-bold">%1$s</h3>
    <p>%2$s</p>
		<div class="pc-modal-action">
			<button class="pc-already-bought-modal__cancel pc-btn">取消</button>
			<button class="pc-already-bought-modal__confirm pc-btn pc-btn-primary text-white">確認購買</button>
		</div>
	</div>
  <form method="dialog" class="pc-modal-backdrop">
    <button class="opacity-0">close</button>
  </form>
</dialog>
',
'您已經購買過此課程',
'還是要購買嗎?'
);

Theme::render_button();

get_footer();
