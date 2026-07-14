import $, { JQuery } from 'jquery'
import { __ } from '@wordpress/i18n'
import { site_url } from '../../utils'
import { Pagination } from '../comment/components/Pagination'

/**
 * 課程列表 AJAX 分頁回應的 data 結構
 */
export type TCoursesPageData = {
	html: string
	total: number
	total_pages: number
	current_page: number
}

/**
 * 課程列表 AJAX 分頁回應
 */
export type TCoursesPageResponse = {
	code: string
	message: string
	data: TCoursesPageData
}

/**
 * 課程列表 query 參數（沿用 [pc_courses] 短代碼參數）
 */
export type TCoursesQuery = {
	limit: string
	columns: string
	orderby: string
	order: string
	category: string
	tag: string
	include: string
	exclude: string
	exclude_avl_courses: string
}

/**
 * 課程列表分頁應用（單一 .pc-courses 容器實例）
 *
 * 以 class selector 支援同頁多實例，每個實例各自維護獨立的目前頁（current），
 * 事件綁定在各自容器內的 .pc-courses__pagination，避免多實例互相干擾。
 */
export class CoursesListApp {
	$element: JQuery<HTMLElement>
	query: TCoursesQuery
	current: number
	totalPages: number
	// 換頁前的列表 HTML。AJAX 失敗時用它還原畫面，避免使用者永遠卡在骨架屏。
	previousListHtml: string

	constructor(element: HTMLElement) {
		this.$element = $(element)
		this.query = {
			limit: String(this.$element.data('limit') ?? '12'),
			columns: String(this.$element.data('columns') ?? '3'),
			orderby: String(this.$element.data('orderby') ?? 'date'),
			order: String(this.$element.data('order') ?? 'DESC'),
			category: String(this.$element.data('category') ?? ''),
			tag: String(this.$element.data('tag') ?? ''),
			include: String(this.$element.data('include') ?? ''),
			exclude: String(this.$element.data('exclude') ?? ''),
			exclude_avl_courses: String(this.$element.data('exclude_avl_courses') ?? 'false'),
		}
		this.current = Number(this.$element.data('current-page') ?? 1) || 1
		this.totalPages = Number(this.$element.data('total-pages') ?? 1) || 1
		this.previousListHtml = ''

		this.renderPagination()
		this.bindEvents()
	}

	// 渲染分頁導航（只有 1 頁時不渲染）
	renderPagination() {
		const $pagination = this.$element.find('.pc-courses__pagination')
		// setLoading() 換頁時會隱藏分頁，重建時一律恢復顯示。
		$pagination.show()
		if (this.totalPages <= 1) {
			$pagination.empty()
			return
		}
		new Pagination($pagination, {
			total: 0,
			totalPages: this.totalPages,
			current: this.current,
			pageSize: Number(this.query.limit) || 12,
		})
	}

	// 綁定分頁點擊事件（scope 在各自容器內）
	bindEvents() {
		const $pagination = this.$element.find('.pc-courses__pagination')

		$pagination.on('click', '.pc-pagination__pages', (e) => {
			e.stopPropagation()
			const page = Number($(e.currentTarget).data('page'))
			if (page && page !== this.current) {
				this.loadPage(page)
			}
		})

		$pagination.on('click', '.pc-pagination__prev', (e) => {
			e.stopPropagation()
			if (this.current <= 1) {
				return
			}
			this.loadPage(this.current - 1)
		})

		$pagination.on('click', '.pc-pagination__next', (e) => {
			e.stopPropagation()
			if (this.current >= this.totalPages) {
				return
			}
			this.loadPage(this.current + 1)
		})
	}

	// 顯示卡片區 loading skeleton（骨架屏）
	//
	// 重點：高度 / 底色 / 圓角一律用 inline style，不依賴 powerhouse 的靜態
	// Tailwind build（front.min.css）。該 build 不掃本 plugin 原始碼，未含
	// `h-64` 等 class，純靠 class 會讓骨架塌成 0 高度而呈現「空白」。
	setLoading() {
		// 先把目前的列表存起來，AJAX 失敗時才有東西可以還原（否則骨架屏會永遠留在畫面上）。
		this.previousListHtml = this.$element.find('.pc-courses__list').html() ?? ''

		const columns = Number(this.query.columns) || 3
		// 與 list/pricing.php 的欄數對照表一致，維持載入前後版面一致。
		const gridClassMap: Record<number, string> = {
			1: 'grid-cols-1',
			2: 'grid-cols-2',
			3: 'grid-cols-2 lg:grid-cols-3',
			4: 'grid-cols-2 lg:grid-cols-4',
		}
		const gridClass = gridClassMap[columns] ?? 'grid-cols-2 lg:grid-cols-3'

		const card = /*html*/ `
			<div class="pc-courses__skeleton">
				<div class="animate-pulse" style="height:10rem;border-radius:0.5rem;background-color:#e5e7eb"></div>
				<div class="animate-pulse" style="height:1rem;width:80%;margin-top:0.75rem;border-radius:0.25rem;background-color:#e5e7eb"></div>
				<div class="animate-pulse" style="height:1rem;width:50%;margin-top:0.5rem;border-radius:0.25rem;background-color:#e5e7eb"></div>
			</div>
		`
		// 至少鋪 3 張，base 兩欄時填滿 1.5 列、大螢幕三欄時填滿 1 列。
		const count = Math.max(columns, 3)
		const cards = Array.from({ length: count }, () => card).join('')
		const loadingHtml = /*html*/ `<div class="grid gap-x-5 gap-y-14 ${gridClass}">${cards}</div>`
		this.$element.find('.pc-courses__list').html(loadingHtml)
		// 換頁載入期間只顯示骨架屏，隱藏分頁（載入完成後 renderPagination 會復原）。
		this.$element.find('.pc-courses__pagination').hide()
	}

	// AJAX 載入指定頁
	loadPage(page: number) {
		this.setLoading()
		$.ajax({
			url: `${site_url}/wp-json/power-course/courses-shortcode-page`,
			type: 'get',
			data: {
				...this.query,
				page,
			},
			headers: {
				'X-WP-Nonce': (window as any).pc_data?.nonce,
			},
			timeout: 30000,
			success: (res: TCoursesPageResponse) => {
				const data = res?.data
				if (!data) {
					return
				}
				this.$element.find('.pc-courses__list').html(data.html)
				this.current = Number(data.current_page) || page
				this.totalPages = Number(data.total_pages) || this.totalPages
				this.renderPagination()
			},
			error: (jqXHR) => {
				console.error('[pc_courses] 載入課程失敗', jqXHR)
				this.renderError()
			},
		})
	}

	// AJAX 失敗時還原畫面：把換頁前的列表放回去，並在上方顯示可讀的錯誤訊息。
	//
	// 修復前這裡只呼叫 renderPagination()，沒有動 .pc-courses__list——
	// setLoading() 塞進去的骨架屏就這樣永遠留在畫面上，使用者看到的是「一直在載入」，
	// 零錯誤提示、也無從重試（Issue #254 驗收缺口 D2）。
	renderError() {
		const $list = this.$element.find('.pc-courses__list')

		// 還原換頁前的內容（第一次載入就失敗時 previousListHtml 為空，清掉骨架屏即可）。
		$list.html(this.previousListHtml)

		// 錯誤訊息用 DOM API 建立，不用字串拼接，避免任何注入疑慮。
		const notice = document.createElement('p')
		notice.className = 'pc-courses__error'
		notice.setAttribute('role', 'alert')
		notice.style.color = '#dc2626'
		notice.style.margin = '0 0 1rem'
		notice.textContent = __(
			'Failed to load courses. Please try again.',
			'power-course',
		)

		$list.prepend(notice)

		// 還原分頁導航，讓使用者可以重試（setLoading() 換頁時會把它隱藏）。
		this.renderPagination()
	}
}

/**
 * 初始化頁面上所有 [pc_courses] 列表
 */
export const courses = () => {
	$('.pc-courses').each((_, el) => new CoursesListApp(el))
}
