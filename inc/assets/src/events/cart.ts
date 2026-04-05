import $ from 'jquery'

// 加入購物車樣式調整
export const cart = () => {
	$(document.body).on(
		'added_to_cart',
		function (event, fragments, cart_hash, Button) {
			//檢查 Button 這個 jQuery 元素是否包含 .pc-btn class
			if (!Button.hasClass('pc-btn')) {
				return
			}

			const isIcon = Button.hasClass('pc-btn-square')
			if (isIcon) {
				const svgClasses = Button.find('svg').attr('class')
				const svgColor = Button.find('svg').attr('fill')
				Button.addClass(
					'pc-btn-outline border-solid text-primary hover:text-white',
				)
					.removeClass('text-white')
					.html(
						/*html*/
						`<svg xmlns="http://www.w3.org/2000/svg" class="${svgClasses} [&_path]:group-hover:fill-white" viewBox="0 0 24 24" fill="none">
							<path fill-rule="evenodd" clip-rule="evenodd" d="M22 12C22 17.5228 17.5228 22 12 22C6.47715 22 2 17.5228 2 12C2 6.47715 6.47715 2 12 2C17.5228 2 22 6.47715 22 12ZM16.0303 8.96967C16.3232 9.26256 16.3232 9.73744 16.0303 10.0303L11.0303 15.0303C10.7374 15.3232 10.2626 15.3232 9.96967 15.0303L7.96967 13.0303C7.67678 12.7374 7.67678 12.2626 7.96967 11.9697C8.26256 11.6768 8.73744 11.6768 9.03033 11.9697L10.5 13.4393L12.7348 11.2045L14.9697 8.96967C15.2626 8.67678 15.7374 8.67678 16.0303 8.96967Z" fill="${svgColor}"/>
						</svg>`,
					)
			} else {
				Button.addClass(
					'pc-btn-outline border-solid text-primary hover:text-white',
				)
					.removeClass('text-white')
					.html('已加入購物車')
			}
		},
	)

	// 針對已買過的學員，跳出 modal 確認是否要加入購物車
	const is_avl = !!window?.pc_data?.is_avl

	// 避免觸發加入購物車
	$('.pc-add-to-cart:not(.pc-no-modal) > a').removeClass(
		'add_to_cart_button ajax_add_to_cart',
	)

	// 添加 MODAL 關閉事件
	const modal = document.querySelector('.pc-already-bought-modal')
	$('.pc-already-bought-modal .pc-already-bought-modal__cancel').on(
		'click',
		function () {
			modal?.close()
		},
	)

	$(document.body).on(
		'click',
		'.pc-add-to-cart:not(.pc-no-modal)',
		function (e) {
			e.preventDefault()
			e.stopPropagation()
			$('.pc-already-bought-modal .pc-already-bought-modal__confirm').off()

			// 避免觸發加入購物車
			$(this).find('a').removeClass('add_to_cart_button ajax_add_to_cart')

			const $add_to_cart_button = $(e.currentTarget).find('a')

			if (is_avl) {
				if (modal) {
					modal?.showModal()
				}
				$('.pc-already-bought-modal .pc-already-bought-modal__confirm').on(
					'click',
					() => {
						modal?.close()
						$(this).addClass('pc-no-modal').off()
						$add_to_cart_button
							.addClass('add_to_cart_button ajax_add_to_cart')
							.click()
					},
				)
			} else {
				$(this).addClass('pc-no-modal').off()
				$add_to_cart_button
					.addClass('add_to_cart_button ajax_add_to_cart')
					.click()
			}
		},
	)

	$('.pc-add-to-cart-link:not(.pc-btn-disabled)').on('click', function (e) {
		e.preventDefault()
		e.stopPropagation()
		$('.pc-already-bought-modal .pc-already-bought-modal__confirm').off()
		const href = $(e.currentTarget).attr('href')

		if (is_avl) {
			if (modal) {
				modal?.showModal()
			}
			$('.pc-already-bought-modal .pc-already-bought-modal__confirm').on(
				'click',
				() => {
					modal?.close()
					window.location.href = href
				},
			)
		} else {
			window.location.href = href
		}
	})
}
