import $ from 'jquery'
import { SCREEN, header_offset, fix_video_and_tabs_mobile } from '../utils'
import { throttle } from 'lodash-es'

// 處理 courses product 銷售業手機板的事件
export const coursesProduct = () => {
	if (!fix_video_and_tabs_mobile) return
	const video = $('#courses-product__feature-video')
	const tabsNav = $('#courses-product__tabs-nav')
	const tabsOffset = tabsNav?.[0]?.offsetTop || 0 // 獲取 tabsNav 元素的初始頂部位置
	const videoOffset = video?.offset()?.top || 0 // 獲取 video 元素的初始頂部位置

	$(window).scroll(
		throttle(() => {
			if (window.innerWidth > SCREEN.MD) return
			const scrollTop = $(window).scrollTop() // 獲取當前滾動位置
			if (scrollTop > videoOffset - header_offset) {
				// 如果滾動位置超過 video 頂部
				video.css({
					position: 'fixed',
					top: `${header_offset}px`,
					left: '0',
				})
			} else {
				// 如果滾動位置還沒超過 video 頂部
				video.css({
					position: 'relative',
					top: 'unset',
					left: 'unset',
				})
			}

			// 要扣掉 video 2倍的高度，因為1個是原本 video 佔住的空間，另一個是 fixed 之後的空間
			const videoH = video.outerHeight()
			if (scrollTop > tabsOffset - videoH * 2 - 48) {
				// 如果滾動位置超過 tabsNav 頂部
				tabsNav.css({
					position: 'fixed',
					top: `${videoH + header_offset}px`,
					left: '0',
					padding: '0',
				})
			} else {
				// 如果滾動位置還沒超過 tabsNav 頂部
				tabsNav.css({
					position: 'relative',
					top: 'unset',
					left: 'unset',
					padding: 'unset',
					'background-color': 'unset',
				})
			}
		}, 150),
	)

	$(window).resize(() => {
		if (window.innerWidth > SCREEN.MD) {
			video.css({
				position: 'relative',
				top: 'unset',
				left: 'unset',
			})
			tabsNav.css({
				position: 'relative',
				top: 'unset',
				left: 'unset',
				padding: 'unset',
				'background-color': 'unset',
			})
		} else {
			const scrollTop = $(window).scrollTop() // 獲取當前滾動位置
			const videoH = video.outerHeight()
			const tabsOffset_2 = tabsNav?.[0]?.offsetTop || 0 // 獲取 tabsNav 元素的初始頂部位置
			const videoOffset_2 = video?.offset()?.top || 0 // 獲取 video 元素的初始頂部位置
			if (scrollTop > tabsOffset_2 - videoH * 2 - 48) {
				// 如果滾動位置超過 tabsNav 頂部
				tabsNav.css({
					position: 'fixed',
					top: `${videoH + header_offset}px`,
					left: '0',
					padding: '0',
				})
			}
		}
	})
}
