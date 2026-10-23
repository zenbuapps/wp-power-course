import $ from 'jquery'
import {
	header_offset,
	fix_video_and_tabs_mobile,
	fix_video_and_tabs_mobile_target,
	isStickyViewport,
} from '../utils'
import { throttle } from 'lodash-es'

const VIDEO_SELECTOR = '#courses-product__feature-video'
const TABS_NAV_SELECTOR = '#courses-product__tabs-nav'

// 依吸附對象決定影片／分頁是否吸附（Issue #278）
const isVideoSticky = fix_video_and_tabs_mobile_target !== 'tabs_only'
const isTabsSticky = fix_video_and_tabs_mobile_target !== 'video_only'

// video／tabsNav 在文件流中的頂部位置（文件座標），由 measureOffsets() 更新
let videoOffset = 0
let tabsOffset = 0

const isFixed = (el: JQuery<HTMLElement>) => 'fixed' === el.css('position')

/**
 * 量測 video／tabsNav 在文件流中的位置
 *
 * 只能在兩者都沒有 fixed（都在文件流中）時量，量到的才是真實位置，否則沿用上一次的值。
 * 不能只在初始化量一次：toggleContent() 會在之後收掉課程簡介的預留高度、使用者會點「展開」、
 * 圖片會延遲載入，分頁列上方的高度都會變，門檻過期會讓分頁列晚吸附、吸附瞬間跳位。
 *
 * 用 offset()（文件座標）而非 offsetTop（相對 offsetParent）：門檻要跟 scrollTop 比，
 * 佈景主題有 position: relative 的祖先時 offsetTop 會少算祖先的位移。
 */
const measureOffsets = (
	video: JQuery<HTMLElement>,
	tabsNav: JQuery<HTMLElement>,
) => {
	if (isFixed(video) || isFixed(tabsNav)) return
	videoOffset = video.offset()?.top || 0
	tabsOffset = tabsNav.offset()?.top || 0
}

/**
 * 分頁列開始吸附的捲動門檻
 * 影片有吸附時要扣掉 video 2倍的高度，因為1個是原本 video 佔住的空間，另一個是 fixed 之後的空間
 * 只吸附分頁時，影片仍在文件流中，分頁列頂端碰到 header_offset 即吸附
 */
const getTabsThreshold = (videoH: number) =>
	isVideoSticky ? tabsOffset - videoH * 2 - 48 : tabsOffset - header_offset

/** 分頁列吸附時的 top：影片有吸附就疊在影片下方，否則直接貼在 header_offset 下方 */
const getTabsTop = (videoH: number) =>
	isVideoSticky ? videoH + header_offset : header_offset

const resetVideo = (video: JQuery<HTMLElement>) => {
	video.css({
		position: 'relative',
		top: 'unset',
		left: 'unset',
	})
}

const resetTabsNav = (tabsNav: JQuery<HTMLElement>) => {
	tabsNav.css({
		position: 'relative',
		top: 'unset',
		left: 'unset',
		padding: 'unset',
		'background-color': 'unset',
	})
}

/**
 * 點分頁後要捲到的位置，讓新分頁內容頂端不被吸附列遮住（Issue #279）
 * 只有分頁列「目前正處於吸附」時內容才會被遮住；不需補償時回傳 null（桌機維持不捲動）
 */
export const getTabContentScrollTop = (): number | null => {
	if (!fix_video_and_tabs_mobile || !isTabsSticky || !isStickyViewport()) {
		return null
	}
	const tabsNav = $(TABS_NAV_SELECTOR)
	if (!isFixed(tabsNav)) return null

	// 分頁列 fixed 時已脫離文件流，緊接在後的內容容器就佔在分頁列原本的位置
	const contentTop = tabsNav.next().offset()?.top
	if (undefined === contentTop) return null

	const video = $(VIDEO_SELECTOR)
	const videoH = video.outerHeight() || 0
	const fixedVideoH = isVideoSticky && isFixed(video) ? videoH : 0

	// 捲到分頁列回到文件流後剛好貼在吸附位置（影片／header 下緣），內容緊接在分頁列下方
	const idealScrollTop = contentTop - header_offset - fixedVideoH

	// 不可超過吸附門檻，否則分頁列又會 fixed、脫離文件流，內容上移被它蓋住
	// 無條件捨去：影片高度可能有小數，捲動位置進位後可能剛好越過門檻
	return Math.max(
		0,
		Math.floor(Math.min(idealScrollTop, getTabsThreshold(videoH))),
	)
}

// 處理 courses product 銷售業手機板的事件
export const coursesProduct = () => {
	if (!fix_video_and_tabs_mobile) return
	const video = $(VIDEO_SELECTOR)
	const tabsNav = $(TABS_NAV_SELECTOR)
	measureOffsets(video, tabsNav)

	const update = () => {
		measureOffsets(video, tabsNav)
		const scrollTop = $(window).scrollTop() || 0 // 獲取當前滾動位置
		if (isVideoSticky) {
			if (scrollTop > videoOffset - header_offset) {
				// 如果滾動位置超過 video 頂部
				video.css({
					position: 'fixed',
					top: `${header_offset}px`,
					left: '0',
				})
			} else {
				// 如果滾動位置還沒超過 video 頂部
				resetVideo(video)
			}
		}

		if (isTabsSticky) {
			const videoH = video.outerHeight() || 0
			if (scrollTop > getTabsThreshold(videoH)) {
				// 如果滾動位置超過 tabsNav 頂部
				tabsNav.css({
					position: 'fixed',
					top: `${getTabsTop(videoH)}px`,
					left: '0',
					padding: '0',
				})
			} else {
				// 如果滾動位置還沒超過 tabsNav 頂部
				resetTabsNav(tabsNav)
			}
		}
	}

	$(window).scroll(
		throttle(() => {
			if (!isStickyViewport()) return
			update()
		}, 150),
	)

	$(window).resize(() => {
		if (!isStickyViewport()) {
			// 只重設本模式會吸附的元素，不吸附的元素維持不碰
			if (isVideoSticky) resetVideo(video)
			if (isTabsSticky) resetTabsNav(tabsNav)
			return
		}
		update()
	})
}
