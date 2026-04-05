import $ from 'jquery'

// 處理 Toggle Content 組件的切換事件
export const toggleContent = () => {
	$('.pc-toggle-content__main').each(function () {
		const mainH = $(this).height()
		const ToggleContent = $(this).closest('.pc-toggle-content')
		const initH = Number(ToggleContent.data('init-height'))
		if (mainH <= initH) {
			ToggleContent.height('auto').next('.pc-toggle-content__wrap').remove()
		}

		// 初始化，調整完高度後，才顯示內容
		$('#courses-product__feature-content').css('opacity', 1)
	})

	const expand = (Wrap, initBG) => () => {
		Wrap.addClass('expanded').removeClass(initBG).find('p').text('收合內容')
	}

	const collapse = (Wrap, initBG) => () => {
		Wrap.removeClass('expanded').addClass(initBG).find('p').text('展開內容')
	}

	$('.pc-toggle-content__wrap').on('click', function (e) {
		e.stopPropagation()

		const isExpanded = $(this).hasClass('expanded')
		const Wrap = $(this)
		const ToggleContent = $(this).prev('.pc-toggle-content')
		const Main = ToggleContent.find('.pc-toggle-content__main')
		const mainH = Main.height()
		const initH = Number(ToggleContent.data('init-height'))
		const initBG = ToggleContent.data('init-bg')

		ToggleContent.animate(
			{
				height: isExpanded ? initH : mainH + 40,
			},
			300,
			isExpanded ? collapse(Wrap, initBG) : expand(Wrap, initBG),
		)
	})
}
