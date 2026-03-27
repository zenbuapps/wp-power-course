import $, { JQuery } from 'jquery'
import { site_url } from '../../utils/'
import {
	CommentForm,
	CommentItem,
	TCommentItemProps,
	Pagination,
	TPaginationProps,
} from './components'

export type TCommentAppProps = {
	queryParams: { [key: string]: any } | undefined
	navElement: string
	ratingProps?:
	| {
		name: string
	}
	| undefined
}

export class CommentApp {
	$element: JQuery<HTMLElement>
	props?: TCommentAppProps
	_queryParams: { [key: string]: any } | undefined
	_pagination: TPaginationProps | undefined
	ratingProps:
		| {
			name: string
		}
		| undefined
	commentForm: CommentForm
	showForm: boolean
	showList: boolean
	_list: TCommentItemProps[]
	post_id: string // 商品 ID
	user_id: number // 使用者 ID
	user_role: 'admin' | 'user' // 使用者角色
	comment_type: string // comment 類型 'comment' | 'review'
	_isLoading: boolean
	isSuccess: boolean
	isError: boolean
	total: number
	totalPages: number
	isInit: boolean
	commentItems: CommentItem[]
	captchaModal: HTMLDialogElement | null

	constructor(element: string, props: TCommentAppProps) {
		this.$element = $(element)
		this.props = props
		this._queryParams = props?.queryParams
		this.ratingProps = props?.ratingProps
		this.showForm = this.$element.data('show_form') === 'yes'
		this.showList = this.$element.data('show_list') === 'yes'
		this.post_id = this.$element.data('post_id')
		this.user_id = Number(this.$element.data('user_id'))
		this.user_role = this.$element.data('user_role')
		this.comment_type = this.$element.data('comment_type')
		this.isInit = true
		this.commentItems = []
		this._isLoading = false
		this.captchaModal = null
		this.render()
		this.createSubcomponents()
		this.bindEvents()
	}

	bindEvents() {
		// 初始化
		if (this.showList) {
			if (this.isInit) {
				this.getComments()
			}
		}
	}

	// 綁定換頁事件
	bindPaginationEvents(pagination: TPaginationProps) {
		const { current, totalPages, pageSize, total } = pagination
		this.$element
			.find('.pc-comment-pagination')
			.on('click', '.pc-pagination__pages', (e) => {
				e.stopPropagation()
				const paged = Number($(e.currentTarget).data('page'))
				this.queryParams = {
					...this._queryParams,
					paged,
				}
			})

		this.$element
			.find('.pc-comment-pagination')
			.on('click', '.pc-pagination__prev', (e) => {
				e.stopPropagation()
				if (current === 1) {
					return
				}
				const paged = current - 1
				this.queryParams = {
					...this._queryParams,
					paged,
				}
			})

		this.$element
			.find('.pc-comment-pagination')
			.on('click', '.pc-pagination__next', (e) => {
				e.stopPropagation()
				if (current === totalPages) {
					return
				}
				const paged = current + 1
				this.queryParams = {
					...this._queryParams,
					paged,
				}
			})
	}

	// 綁定回覆事件
	bindReplyEvents() {
		this.$element.find('.pc-comment-item__reply-button').on('click', (e) => {
			e.stopPropagation()
			const commentId = $(e.currentTarget)
				.closest('.pc-comment-item')
				.data('comment_id')

			const CommentItemContentNode = $(e.currentTarget).closest(
				'.pc-comment-item__content',
			)

			new CommentForm(
				CommentItemContentNode.find('.pc-comment-item__reply-form'),
				{
					appInstance: this,
					reply_comment_type: 'comment',
					reply_comment_parent: commentId,
				},
			)
		})
	}

	render() {
		this.$element.html(/*html*/ `
			<div class="pc-comment-form"></div>
			<div class="pc-comment-list"></div>
			<div class="pc-comment-pagination"></div>
			<dialog class="pc-comment__captcha-modal pc-modal">
				<div class="pc-modal-box !w-[326px] !h-[260px] overflow-hidden">
					<div class="captcha-container relative"></div>
				</div>
				<form method="dialog" class="pc-modal-backdrop">
					<button class="opacity-0">close</button>
				</form>
			</dialog>
		`)

		this.captchaModal = this.$element.find('.pc-comment__captcha-modal')[0]
	}

	createSubcomponents() {
		if (this.showForm) {
			// render comment form
			this.commentForm = new CommentForm(
				this.$element.find('.pc-comment-form'),
				{
					ratingProps: this.ratingProps,
					appInstance: this,
				},
			)
		} else {
			// 如果不能留言要顯示原因
			const reason = this.$element.data('show_form')
			this.$element.find('.pc-comment-form').html(reason)
		}
	}

	// queryParams 改變時觸發
	set queryParams(value: { [key: string]: any }) {
		this._queryParams = value
		this.getComments()
	}

	// pagination 改變時觸發
	set pagination(value: TPaginationProps) {
		this._pagination = value

		// render pagination
		const { totalPages = 1 } = value
		if (totalPages <= 1) {
			return
		}
		new Pagination(this.$element.find('.pc-comment-pagination'), value)

		this.bindPaginationEvents(value)
	}

	set isLoading(value: boolean) {
		this._isLoading = value
		if (value) {
			const loadingHtml = /*html*/ `
						<div class="h-[8.5rem] mt-2 rounded bg-base-200 animate-pulse"></div>
						<div class="h-[8.5rem] mt-2 rounded bg-base-200 animate-pulse"></div>
						<div class="h-[8.5rem] mt-2 rounded bg-base-200 animate-pulse"></div>
						`

			this.$element.find('.pc-comment-list').html(loadingHtml)
		}
	}

	// list 改變時觸發
	set list(value: TCommentItemProps[]) {
		this._list = value

		if (!value.length) {
			this.$element.find('.pc-comment-list').html('目前沒有評價')
			return
		}

		// render comment items
		const nodes = value
			.map(
				({ id: comment_id }) =>
					`<div data-comment_id="${comment_id}" class="pc-comment-item"></div>`,
			)
			.join('')
		this.$element.find('.pc-comment-list').html(nodes)
		value.forEach((commentItem) => {
			this.commentItems.push(
				new CommentItem(
					this.$element.find(
						`.pc-comment-item[data-comment_id="${commentItem.id}"]`,
					),
					{
						...commentItem,
						appInstance: this,
					},
				),
			)
		})

		this.bindReplyEvents()
	}

	getComments(data?: { [key: string]: any }, showLoading = true) {
		if (showLoading) {
			this.isLoading = true
		}
		$.ajax({
			url: `${site_url}/wp-json/power-course/comments`,
			type: 'get',
			data: {
				post_id: this.post_id,
				...this._queryParams,
				...data,
			},
			headers: {
				'X-WP-Nonce': (window as any).pc_data?.nonce,
			},
			timeout: 30000,
			success: (response: TCommentItemProps[], textStatus, jqXHR) => {
				const total = Number(jqXHR.getResponseHeader('X-WP-Total'))
				const totalPages = Number(jqXHR.getResponseHeader('X-WP-TotalPages'))
				const current = Number(jqXHR.getResponseHeader('X-WP-CurrentPage'))
				const pageSize = Number(jqXHR.getResponseHeader('X-WP-PageSize'))
				const pagination = {
					total,
					totalPages,
					current,
					pageSize,
				}

				this.isSuccess = 'success' === textStatus
				this.total = total
				this.totalPages = totalPages

				this.list = response ?? []
				this.pagination = pagination
			},
			error: (error) => {
				console.log('error', error)
				this.isSuccess = false
				this.isError = true
			},
			complete: (xhr) => {
				if (showLoading) {
					this.isLoading = false
				}
				this.isInit = false
			},
		})
	}
}
