import $ from 'jquery'
import { getTabContentScrollTop } from './coursesProduct'

// 處理 TAB 組件的切換事件
export const tabs = () => {
	// 偏移 TAB 置頂
	$('div[id^="tab-nav-"]').on('click', function () {
		$(this).addClass('active').siblings().removeClass('active')
		$('#tab-content-' + $(this).attr('id').split('-')[2])
			.addClass('active')
			.siblings()
			.removeClass('active')

		// active class 套用後再計算位置
		scrollToTab()
	})

	/**
	 * 切換 TAB 時，若分頁列正處於吸附狀態，捲到新分頁內容頂端，避免被吸附列遮住（Issue #279）
	 * 未吸附（含桌機寬度）時不捲動，維持原本行為
	 */
	function scrollToTab() {
		const scrollTop = getTabContentScrollTop()
		if (null === scrollTop) return

		$('html, body').animate(
			{
				scrollTop,
			},
			500,
		)
	}
}
