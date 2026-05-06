/**
 * Issue #10：多影片試看 Swiper 輪播
 *
 * 條件式 enqueue，只在課程銷售頁存在 2~6 部試看影片時載入。
 * - 初始化所有 [data-pc-trial-videos-swiper] 容器
 * - 切換 slide 時，前一個 slide 內的影片自動暫停（VidStack / YouTube / Vimeo）
 * - autoplay: false（學員主動點擊才播）
 */

import Swiper from 'swiper'
import { Navigation, Scrollbar, EffectCoverflow } from 'swiper/modules'
import 'swiper/css'
import 'swiper/css/navigation'
import 'swiper/css/scrollbar'
import 'swiper/css/effect-coverflow'
import './styles/trial-videos-swiper.css'

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
		// Swiper v11 的 loop 模式在 3D effect (coverflow) + slidesPerView > 1 時，
		// 需要至少 (slidesPerView * 2 + 1) 張 slide 才能正確 reposition，否則
		// 會在尾端卡住（isEnd: true 永久）。slidesPerView 1.4 → 至少 4 張。
		// 不足時 fallback 用 rewind（滑到底跳回第一張，雖有跳轉但不卡住）。
		const slideCount = container.querySelectorAll('.swiper-slide').length
		const enableLoop = slideCount >= 4

		const swiper = new Swiper(container, {
			modules: [
				Navigation,
				Scrollbar,
				EffectCoverflow,
			],
			autoplay: false,
			loop: enableLoop,
			rewind: !enableLoop,
			loopAdditionalSlides: enableLoop ? 2 : 0,
			effect: 'coverflow',
			grabCursor: true,
			centeredSlides: true,
			slidesPerView: 1.4,
			coverflowEffect: {
				rotate: 50,
				stretch: 0,
				depth: 100,
				modifier: 1,
				slideShadows: true,
			},
			scrollbar: {
				el: container.querySelector<HTMLElement>('.swiper-scrollbar'),
				hide: true,
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
