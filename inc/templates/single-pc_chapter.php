<?php
/**
 * @deprecated 0.8.0 之後使用 chapter 自己的 template
 */

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Plugin;
use J7\Powerhouse\Theme\Core\FrontEnd as Theme;
use J7\PowerCourse\Resources\Chapter\Model\Chapter;
use J7\PowerCourse\Resources\Settings\Model\Settings;
use J7\PowerCourse\Utils\LinearViewing;





if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

$current_user_id = \get_current_user_id();

if ( ! $current_user_id ) {
	\wp_safe_redirect( \get_permalink( (int) \get_option( 'woocommerce_myaccount_page_id' ) ) ?: \site_url() );
	exit;
}

global $post;

$chapter_post   = $post;
$chapter        = new Chapter( (int) $chapter_post->ID );
$course_product = $chapter->get_course_product();


$GLOBALS['course']  = $course_product;
$GLOBALS['chapter'] = $chapter_post;

$is_expired = CourseUtils::is_expired($course_product, $current_user_id);

$is_avl = CourseUtils::is_avl($course_product?->get_id());

if (!current_user_can('manage_woocommerce')) {
	if ( ! $is_avl ) {
		get_header();
		Plugin::load_template( '404/buy', null );
		get_footer();
		exit;
	} elseif ( ! $course_product || ! CourseUtils::is_course_ready( $course_product ) ) {
		get_header();
		Plugin::load_template( '404/not-ready', null );
		get_footer();
		exit;
	} elseif ( $is_expired ) {
		get_header();
		Plugin::load_template( '404/expired', null );
		get_footer();
		exit;
	}

	// 線性觀看存取控制：鎖定章節 redirect 到當前應觀看章節
	if ( $course_product && LinearViewing::is_enabled( (int) $course_product->get_id() ) ) {
		if ( LinearViewing::is_chapter_locked( (int) $chapter_post->ID, (int) $course_product->get_id(), $current_user_id ) ) {
			$target_chapter_id = LinearViewing::get_current_chapter_id(
				(int) $course_product->get_id(),
				$current_user_id
			);
			if ( $target_chapter_id ) {
				\wp_safe_redirect(
					\add_query_arg( 'linear_locked', '1', \get_the_permalink( $target_chapter_id ) )
				);
				exit;
			}
		}
	}

	if ('publish' !== $post->post_status) {
		\wp_safe_redirect(site_url('404'));
		exit;
	}
}


do_action('power_course_before_classroom_render');

$settings = Settings::instance();

// phpcs:disable
?>
<!doctype html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5, viewport-fit=cover">
			<link rel="profile" href="https://gmpg.org/xfn/11">
			<?php wp_head(); ?>
		</head>

		<body class="!m-0 min-h-screen bg-base-100 classroom">
			<?php

			Plugin::load_template('classroom/sider');
			echo '<div id="pc-classroom-main">';
			Plugin::load_template( 'classroom/body', null, true, true );
			echo '</div>';


			$is_linear_enabled   = $course_product ? LinearViewing::is_enabled( (int) $course_product->get_id() ) : false;
			$next_post_id        = ChapterUtils::get_next_post_id( (int) $chapter_post->ID );
			$next_chapter_locked = false;
			if ( $is_linear_enabled && $next_post_id && ! LinearViewing::is_exempt( $current_user_id ) ) {
				$next_chapter_locked = LinearViewing::is_chapter_locked(
					$next_post_id,
					(int) $course_product->get_id(),
					$current_user_id
				);
			}

			printf(
			/*html*/'
			<script>
				window.pc_data = {
					"nonce": "%1$s",
					"plugin_url": "%2$s",
					"pdf_watermark": {
						"qty": "%3$d",
						"color": "%4$s",
						"text": "%5$s"
					},
					"linear_viewing": %6$s,
					"next_chapter_locked": %7$s
				}
			</script>
			',
			\wp_create_nonce( 'wp_rest' ),
			Plugin::$url,
			$settings->pc_pdf_watermark_qty,
			$settings->pc_pdf_watermark_color,
			ChapterUtils::get_formatted_watermark_text('pdf'),
			$is_linear_enabled ? 'true' : 'false',
			$next_chapter_locked ? 'true' : 'false'
			);
			Theme::render_button();
			\wp_footer();
			 ?>

</body>
</html>
<?php // phpcs:enabled
