/**
 * Issue #10：多影片試看 Swiper 輪播
 *
 * 條件式 enqueue，只在課程銷售頁存在 2~6 部試看影片時載入。
 * - 初始化所有 [data-pc-trial-videos-swiper] 容器
 * - 切換 slide 時，前一個 slide 內的影片自動暫停（VidStack / YouTube / Vimeo）
 * - autoplay: false（學員主動點擊才播）
 */

import Swiper from 'swiper'
import { Navigation, Pagination } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/navigation'
import 'swiper/css/pagination'

type SlideEl = HTMLElement

const pauseVidstack = (slide: SlideEl): void => {
	const players = slide.querySelectorAll<HTMLElement & { paused?: boolean }>(
		'media-player',
	)
	players.forEach((player) => {
		try {
			player.paused = true
		} catch {
			// VidStack 元素可能尚未 hydrate，忽略
		}
	})
}

const pauseYoutubeIframe = (slide: SlideEl): void => {
	const iframes = slide.querySelectorAll<HTMLIFrameElement>(
		'iframe[src*="youtube.com"], iframe[src*="youtu.be"], iframe[src*="youtube-nocookie.com"]',
	)
	iframes.forEach((iframe) => {
		try {
			iframe.contentWindow?.postMessage(
				JSON.stringify({ event: 'command', func: 'pauseVideo', args: [] }),
				'*',
			)
		} catch {
			// iframe 跨域受限或尚未載入時忽略
		}
	})
}

const pauseVimeoIframe = (slide: SlideEl): void => {
	const iframes = slide.querySelectorAll<HTMLIFrameElement>(
		'iframe[src*="vimeo.com"]',
	)
	iframes.forEach((iframe) => {
		try {
			iframe.contentWindow?.postMessage(
				JSON.stringify({ method: 'pause' }),
				'*',
			)
		} catch {
			// 同上
		}
	})
}

const pauseSlide = (slide: SlideEl): void => {
	pauseVidstack(slide)
	pauseYoutubeIframe(slide)
	pauseVimeoIframe(slide)
}

const initSwiper = (container: HTMLElement): void => {
	try {
		const swiper = new Swiper(container, {
			modules: [
				Navigation,
				Pagination,
			],
			autoplay: false,
			loop: false,
			pagination: {
				el: container.querySelector<HTMLElement>('.swiper-pagination'),
				clickable: true,
			},
			navigation: {
				nextEl: container.querySelector<HTMLElement>('.swiper-button-next'),
				prevEl: container.querySelector<HTMLElement>('.swiper-button-prev'),
			},
		})

		swiper.on('slideChange', () => {
			const slides = Array.from(
				container.querySelectorAll<SlideEl>('.swiper-slide'),
			)
			slides.forEach((slide, index) => {
				if (index === swiper.activeIndex) return
				pauseSlide(slide)
			})
		})
	} catch (err) {
		// eslint-disable-next-line no-console
		console.error('[power-course] trial-videos-swiper init failed', err)
	}
}

const bootstrap = (): void => {
	const containers = document.querySelectorAll<HTMLElement>(
		'[data-pc-trial-videos-swiper]',
	)
	containers.forEach((container) => initSwiper(container))
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', bootstrap)
} else {
	bootstrap()
}
