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

/**
 * 行動版吸附對象（Issue #278）
 * both：影片與分頁都吸附（預設，既有行為）／tabs_only：只吸附分頁／video_only：只吸附影片
 */
export type TStickyTarget = 'both' | 'tabs_only' | 'video_only'

const STICKY_TARGETS: TStickyTarget[] = ['both', 'tabs_only', 'video_only']

export const fix_video_and_tabs_mobile_target: TStickyTarget =
	STICKY_TARGETS.find(
		(target) => target === window.pc_data?.fix_video_and_tabs_mobile_target,
	) || 'both'

export const isMobile = (size = 'LG') => {
	return window.innerWidth < SCREEN?.[size]
}

/**
 * 是否處於「行動版吸附」的寬度，影片／分頁吸附與點分頁的捲動補償共用此判斷（Issue #277）
 * 必須與 Tailwind md:（min-width: 810px）互補：寬度 >= 810px 已是桌機兩欄版面，不吸附
 */
export const isStickyViewport = () => isMobile('MD')

// 判斷元素距離可視區域頂部的距離
export const getDistanceFromViewportTop = ($element) => {
	const elementTop = $element?.offset()?.top || 0
	const scrollTop = $(window).scrollTop()
	return elementTop - scrollTop
}
