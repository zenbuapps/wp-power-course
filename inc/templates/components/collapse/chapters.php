<?php
/**
 * Sidebar for classroom
 */

use J7\PowerCourse\Utils\Course as CourseUtils;
use J7\PowerCourse\Resources\Chapter\Utils\Utils as ChapterUtils;
use J7\PowerCourse\Resources\Chapter\Core\CPT;


$default_args = [
	'product' => $GLOBALS['course'] ?? null,
];

/**
 * @var array $args
 * @phpstan-ignore-next-line
 */
$args = wp_parse_args($args, $default_args);

[
	'product' => $product,
] = $args;

if (! ( $product instanceof \WC_Product )) {
	return;
}

$chapters_html = ChapterUtils::get_children_posts_html_uncached($product->get_id(), null, 0, 'course-product');

global $chapter;

?>
<style>
	.icon-arrow svg {
		transform: rotate(0deg);
		transition: all 0.3s ease-in-out;
	}

	.expanded .icon-arrow svg {
		transform: rotate(90deg);
		transition: all 0.3s ease-in-out;
	}
</style>

<div id="pc-sider__main-chapters" class="pc-sider-chapters overflow-y-auto lg:ml-0 lg:mr-0">
	<?php echo $chapters_html; ?>
</div>


<script type="module" async>
	(function($) {
		$(document).ready(function() {
			const $el = $('#pc-sider__main-chapters')
			if(!$el.length){
				console.error('#pc-sider__main-chapters 節點不存在')
				return
			}

			// 點擊箭頭展開或收合章節
			$el.on('click', 'li', function() {
				const $li = $(this);
				const $sub_ul = $li.next('ul'); // 子章節

				if ($sub_ul.length > 0) {
					$li.toggleClass('expanded'); // 如果有找到子章節
					$sub_ul.slideToggle('fast'); // 如果有找到子章節
				}
			})
			expanded_all_post_ids();

			// 恢復章節的展開狀態
			function expanded_all_post_ids() {
				$el.find('li').addClass('expanded');
				$el.find('ul').show();
				$el.show();
			}
		})
	})(jQuery)
</script>
