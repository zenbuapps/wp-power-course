import $, { JQuery } from 'jquery'
import { Rating } from './Rating'
import { CommentApp } from '../index'
import { site_url } from '../../../utils/'

export type TCommentItemProps = {
	id: string
	depth: number
	user: {
		id: string
		name: string
		avatar_url: string
		email: string
	}
	rating: number
	comment_content: string
	comment_date: string
	comment_approved: '0' | '1' | 'spam' | 'trash'
	comment_author_IP: string
	can_reply: boolean
	can_delete: boolean
	children: TCommentItemProps[]
}

export class CommentItem {
	$element: JQuery<HTMLElement>
	_props: TCommentItemProps
	rating: Rating
	appInstance: CommentApp
	_isLoading: boolean

	constructor(element, props) {
		this.$element = $(element)
		this._props = props
		this.appInstance = props.appInstance
		this._isLoading = false
		this.render()
	}

	set isLoading(value: boolean) {
		this._isLoading = value

		this.appInstance.$element
			.find(`.pc-comment-item[data-comment_id="${this._props.id}"]`)
			.toggleClass('animate-pulse-2', value)
	}

	// 綁定隱藏事件
	bindHideEvents() {
		this.$element.on('click', '.pc-comment-item__hide-button:first', (e) => {
			e.stopPropagation()
			this.isLoading = true

			// const commentId = $(e.currentTarget)
			// 	.closest('.pc-comment-item')
			// 	.data('comment_id')
			const commentId = this._props.id

			const CommentItemContentNode = this.$element.find(
				'.pc-comment-item__content',
			)

			$.ajax({
				url: `${site_url}/wp-json/power-course/comments/${commentId}/toggle-approved`,
				type: 'post',
				data: null,
				headers: {
					'X-WP-Nonce': (window as any).pc_data?.nonce,
				},
				timeout: 30000,
				success: (response, textStatus, jqXHR) => {
					const { code, message } = response

					if (200 === code) {
						this.appInstance.getComments({}, false)
					} else {
						CommentItemContentNode.find('.pc-comment-item__reply-form')
							.text(message)
							.removeClass('text-success')
							.addClass('text-error')
					}
				},
				error: (error) => {
					console.log('⭐  error:', error)
					const message = error?.responseJSON?.message || '發生錯誤'
					CommentItemContentNode.find('.pc-comment-item__reply-form')
						.text(message)
						.removeClass('text-success')
						.addClass('text-error')
				},
				complete: (xhr) => {
					this.isLoading = false
				},
			})
		})
	}

	// 綁定移動到垃圾桶事件
	bindTrashEvents() {
		this.$element.on('click', '.pc-comment-item__trash-button:first', (e) => {
			e.stopPropagation()
			this.isLoading = true

			// const commentId = $(e.currentTarget)
			// 	.closest('.pc-comment-item')
			// 	.data('comment_id')
			const commentId = this._props.id

			const CommentItemContentNode = this.$element.find(
				'.pc-comment-item__content',
			)

			$.ajax({
				url: `${site_url}/wp-json/power-course/comments/${commentId}`,
				type: 'DELETE',
				data: null,
				headers: {
					'X-WP-Nonce': (window as any).pc_data?.nonce,
				},
				timeout: 30000,
				success: (response, textStatus, jqXHR) => {
					const { code, message } = response

					if (200 === code) {
						this.appInstance.getComments({}, false)
					} else {
						CommentItemContentNode.find('.pc-comment-item__reply-form')
							.text(message)
							.removeClass('text-success')
							.addClass('text-error')
					}
				},
				error: (error) => {
					console.log('⭐  error:', error)
					const message = error?.responseJSON?.message || '發生錯誤'
					CommentItemContentNode.find('.pc-comment-item__reply-form')
						.text(message)
						.removeClass('text-success')
						.addClass('text-error')
				},
				complete: (xhr) => {
					this.isLoading = false
				},
			})
		})
	}

	render() {
		const {
			user,
			comment_content,
			comment_date,
			children,
			depth,
			id,
			comment_author_IP,
		} = this._props
		const childrenHTML = children
			.map(
				({ id: childId }) =>
					`<div data-comment_id="${childId}" class="pc-comment-item"></div>`,
			)
			.join('')
		const bgColor = depth % 2 === 0 ? 'bg-base-200' : 'bg-base-100'

		const comment_approved = this._props.comment_approved === '1'
		const is_trash = this._props.comment_approved === 'trash'
		const user_role = this.appInstance.user_role
		const comment_type = this.appInstance.comment_type
		const can_reply = !(comment_type === 'review' && user_role === 'user')

		this.$element.html(/*html*/ `
			<div class="relative p-6 mt-2 rounded ${bgColor} ${this._props.comment_approved === '0' ? 'border-2 border-dashed border-base-300' : ''} ${is_trash ? 'border-2 border-dashed border-error' : ''}">
				<div class="flex gap-4">
					<div class="size-10 rounded-full overflow-hidden relative">
						<img src="${user.avatar_url}" loading="lazy" class="w-full h-full object-cover relative z-20">
						<div class="absolute top-0 left-0 w-full h-full bg-base-300 animate-pulse z-10"></div>
					</div>
					<div class="flex-1">
						<div class="flex justify-between text-sm">
							<div>${user.name}</div>
							<div class="pc-comment-item__rating"></div>
						</div>
						<p class="text-gray-400 text-content text-xs mb-4">${comment_date}${comment_approved ? '' : '  留言已隱藏'}</p>
						<div class="pc-comment-item__content text-sm [&_p]:mb-0">
							${comment_content}
							<div class="mt-2 flex gap-x-2 text-xs text-primary [&_span]:cursor-pointer">
								${can_reply ? '<span class="pc-comment-item__reply-button">回覆</span>' : ''}
								${user_role === 'admin' ? `<span class="pc-comment-item__hide-button">${comment_approved ? '隱藏' : '顯示'}</span>` : ''}
								${user_role === 'admin' && !is_trash ? '<span class="pc-comment-item__trash-button text-error">移動到垃圾桶</span>' : ''}
							</div>
							<div class="pc-comment-item__reply-form"></div>
						</div>
						${childrenHTML}
					</div>
				</div>
				<span class="absolute top-2 right-2 text-xs text-gray-400">${user_role === 'admin' ? `IP: ${comment_author_IP}  #${id}` : ''}</span>
			</div>
		`)

		this.createSubcomponents()
		this.bindHideEvents()
		this.bindTrashEvents()
	}

	createSubcomponents() {
		const { rating, children } = this._props
		if (rating) {
			this.rating = new Rating(this.$element.find('.pc-comment-item__rating'), {
				value: rating,
				disabled: true,
				name: 'rating-10',
			})
		}

		children.forEach((child) => {
			this.appInstance.commentItems.push(
				new CommentItem(
					this.$element.find(`.pc-comment-item[data-comment_id="${child.id}"]`),
					{
						...child,
						appInstance: this.appInstance,
					},
				),
			)
		})
	}
}
