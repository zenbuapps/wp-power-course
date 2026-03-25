import $ from 'jquery'

// eslint-disable-next-line no-shadow
export enum SCREEN {
	SM = 576,
	MD = 810,
	LG = 1080,
	XL = 1280,
	XXL = 1440,
}

export const site_url = window.location.origin

export const header_offset = Number(window.pc_data?.header_offset) || 0
export const plugin_url = window.pc_data?.plugin_url
export const pdf_watermark = window.pc_data?.pdf_watermark

export const fix_video_and_tabs_mobile =
	(window.pc_data?.fix_video_and_tabs_mobile || 'no') === 'yes'

export const isMobile = (size = 'LG') => {
	return window.innerWidth < SCREEN?.[size]
}

// 判斷元素距離可視區域頂部的距離
export const getDistanceFromViewportTop = ($element) => {
	const elementTop = $element?.offset()?.top || 0
	const scrollTop = $(window).scrollTop()
	return elementTop - scrollTop
}
