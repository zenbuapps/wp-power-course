import $, { JQuery } from 'jquery'
import { Rating, TRatingProps } from './Rating'
import { site_url } from '../../../utils/'
import { CommentApp } from '../index'
import { SliderCaptcha } from './SliderCaptcha'

export type TCommentFormProps = {
	ratingProps: Partial<TRatingProps>
	appInstance: CommentApp
}

export class CommentForm {
	$element: JQuery<HTMLElement>
	props: {
		ratingProps?: Partial<TRatingProps>
		appInstance: CommentApp
		reply_comment_parent?: string
		reply_comment_type?: 'comment'
	}
	post_id: string // 商品 ID
	rating: Rating
	sliderCaptcha: SliderCaptcha | null

	constructor(element, props) {
		this.$element = $(element)
		this.props = props
		this.post_id = props.appInstance.post_id
		this.sliderCaptcha = null
		this.render()
		this.createSubcomponents()
		this.bindEvents()
	}

	bindEvents() {
		this.$element.on('click', '.pc-comment-form__submit', (e) => {
			e.stopPropagation()
			e.preventDefault()

			const user_id = this.props.appInstance.user_id
			const is_user_logged_in = user_id !== 0

			if (is_user_logged_in) {
				this.add()
				return
			}

			const captchaModal = this.props.appInstance.captchaModal

			const captchaModalBox = captchaModal?.querySelector(
				'.pc-comment__captcha-container',
			)

			// 每次點擊清除舊的 captcha
			if (captchaModalBox?.firstElementChild) {
				captchaModalBox.innerHTML = ''
			}

			if (captchaModal) {
				captchaModal.showModal()

				this.sliderCaptcha = new SliderCaptcha(captchaModalBox, {
					onSuccess: () => {
						captchaModal.close()
						this.add()
					},
				})
			}
		})
	}

	validate_email(value) {
		const user_id = this.props.appInstance.user_id
		const is_user_logged_in = user_id !== 0
		if (is_user_logged_in) {
			return true
		}

		if (!value) {
			this.$element
				.find('.pc-comment-form__message')
				.text('請輸入您的電子郵件')
				.removeClass('text-success')
				.addClass('text-error')
			return false
		}

		const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
		if (!regex.test(value)) {
			this.$element
				.find('.pc-comment-form__message')
				.text('請輸入有效的電子郵件')
				.removeClass('text-success')
				.addClass('text-error')
			return false
		}

		return true
	}

	add() {
		const rating = this.$element
			.find(`input[name="${this?.props?.ratingProps?.name}"]:checked`)
			.val()
		const comment_content = this.$element.find('textarea').val()
		const comment_post_ID = this.post_id
		const comment_type = this.props.appInstance.comment_type
		const comment_author_email = this.$element
			.find('input[name="comment_author_email"]')
			.val()

		// reply params
		const { reply_comment_type, reply_comment_parent } = this.props

		const is_valid_email = this.validate_email(comment_author_email)

		if (!is_valid_email) {
			return
		}

		this.setLoading(true)

		$.ajax({
			url: `${site_url}/wp-json/power-course/comments`,
			type: 'post',
			data: {
				comment_post_ID,
				rating,
				comment_content,
				comment_type: reply_comment_type ?? comment_type,
				comment_author_email,
				comment_parent: reply_comment_parent ?? 0,
			},
			headers: {
				'X-WP-Nonce': (window as any).pc_data?.nonce,
			},
			timeout: 30000,
			success: (response, textStatus, jqXHR) => {
				const { code, message } = response

				if (200 === code) {
					this.$element
						.find('.pc-comment-form__message')
						.text(message)
						.removeClass('text-error')
						.addClass('text-success')

					this.$element.find('textarea').val('')

					this.props.appInstance.getComments()
				} else {
					this.$element
						.find('.pc-comment-form__message')
						.text(message)
						.removeClass('text-success')
						.addClass('text-error')
				}
			},
			error: (error) => {
				console.log('⭐  error:', error)
				const message = error?.responseJSON?.message || '發生錯誤'
				this.$element
					.find('.pc-comment-form__message')
					.text(message)
					.removeClass('text-success')
					.addClass('text-error')
			},
			complete: (xhr) => {
				this.setLoading(false)
			},
		})
	}

	setLoading(isLoading: boolean) {
		if (isLoading) {
			this.$element.find('.pc-comment-form__submit').addClass('pc-btn-disabled')
			this.$element.find('textarea').attr('disabled', 'disabled')
			this.$element
				.find('.pc-comment-form__submit .pc-loading')
				.removeClass('tw-hidden')
		} else {
			this.$element
				.find('.pc-comment-form__submit')
				.removeClass('pc-btn-disabled')
			this.$element.find('textarea').removeAttr('disabled')
			this.$element
				.find('.pc-comment-form__submit .pc-loading')
				.addClass('tw-hidden')
		}
	}

	destroy() {
		this.$element.remove()
	}

	render() {
		const { appInstance, reply_comment_type } = this.props
		const user_id = appInstance.user_id
		const is_user_logged_in = user_id !== 0

		// 未登入要留 email
		const email_field = is_user_logged_in
			? ''
			: '<input type="email" placeholder="請輸入您的電子郵件" class="mb-2 rounded h-10 bg-white focus:bg-white" name="comment_author_email" required />'
		const comment_type = reply_comment_type || appInstance.comment_type

		let label = 'review' === comment_type ? '評價' : '留言'
		if (reply_comment_type) {
			label = '回覆'
		}

		this.$element.html(/*html*/ `
			<div class="mb-2 rounded ${reply_comment_type ? 'py-4' : 'p-6 bg-base-200'}">
				<p class="text-base-content text-base font-bold mb-0">新增${label}</p>
				<div class="pc-comment-item__rating mb-2"></div>
				${email_field}
				<textarea placeholder="請輸入您的想法" class="mb-2 rounded h-24 w-full p-3 border border-solid border-base-300 focus:border-primary bg-base-100" name="comment_content" rows="4"></textarea>
				<div class="flex justify-end gap-4 items-center">
					<p class="pc-comment-form__message text-sm m-0"></p>
					<button type="button" class="pc-comment-form__submit pc-btn px-4 pc-btn-primary text-white pc-btn-sm"><span class="pc-loading pc-loading-spinner size-4 tw-hidden"></span>送出</button>
				</div>
			</div>
		`)
	}

	createSubcomponents() {
		const comment_type = this.props.appInstance.comment_type
		if ('review' === comment_type && this.props.ratingProps) {
			this.rating = new Rating(this.$element.find('.pc-comment-item__rating'), {
				value: 5,
				disabled: false,
				name: 'rating',
				...this.props.ratingProps,
			})
		}
	}
}
