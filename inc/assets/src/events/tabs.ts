import $ from 'jquery'
import { isMobile, getDistanceFromViewportTop, header_offset } from '../utils'

// 處理 TAB 組件的切換事件
export const tabs = () => {

	// 偏移 TAB 置頂
	$('div[id^="tab-nav-"]').on('click', function () {
		$(this).addClass('active').siblings().removeClass('active')
		$('#tab-content-' + $(this).attr('id').split('-')[2])
			.addClass('active')
			.siblings()
			.removeClass('active')
	})


	function scrollToTab() {
		// 切換 TAB 時滾到目標節點的位置
		const _isMobile = isMobile()
		const videoH = _isMobile
			? $('#courses-product__feature-video').height() || 0
			: 0

		const navH =
			$('#courses-product__tabs-nav').height() ||
			0
		const isNavFixed =
			$('#courses-product__tabs-nav').css('position') === 'fixed'

		// const offsetTop = _isMobile
		// 	? videoHeight + 52 // 52 是手機 header 的高度
		// 	: 64 // 64 是 header 的高度

		const offsetTop = videoH + navH + header_offset
		const scrollTop =
			$('div.active[id^="tab-content-"]').parent('div').offset().top -
			offsetTop +
			(isNavFixed ? 24 : -12) // 24 是 nav 的 margin bottom

		$('html, body').animate(
			{
				scrollTop,
			},
			500,
		)
	}
}
