import $, { JQuery } from 'jquery'
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

		this.renderPagination()
		this.bindEvents()
	}

	// 渲染分頁導航（只有 1 頁時不渲染）
	renderPagination() {
		const $pagination = this.$element.find('.pc-courses__pagination')
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

	// 顯示卡片區 loading skeleton
	setLoading() {
		const loadingHtml = /*html*/ `
			<div class="grid gap-x-5 gap-y-14 grid-cols-2 lg:grid-cols-3">
				<div class="h-64 rounded bg-base-200 animate-pulse"></div>
				<div class="h-64 rounded bg-base-200 animate-pulse"></div>
				<div class="h-64 rounded bg-base-200 animate-pulse"></div>
			</div>
		`
		this.$element.find('.pc-courses__list').html(loadingHtml)
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
			error: (error) => {
				console.log('error', error)
				this.renderPagination()
			},
		})
	}
}

/**
 * 初始化頁面上所有 [pc_courses] 列表
 */
export const courses = () => {
	$('.pc-courses').each((_, el) => new CoursesListApp(el))
}
