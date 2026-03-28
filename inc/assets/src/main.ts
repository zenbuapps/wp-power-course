/* eslint-disable lines-around-comment */
import jQuery from 'jquery'
import {
	finishChapter,
	dynamicWidth,
	tabs,
	coursesProduct,
	toggleContent,
	countdown,
	CommentApp,
	cart,
	HlsSupport,
	watermarkPDF,
} from './events'
	; (function ($) {
		$(document).ready(function () {
			// classroom 頁面，完成章節
			finishChapter()

			// 改變大小時設定 state
			dynamicWidth()

			// 添加 tabs 組件事件
			tabs()
			coursesProduct()
			toggleContent()
			countdown()
			HlsSupport()

			// PDF 浮水印下載
			watermarkPDF()

			new CommentApp('#review-app', {
				queryParams: {
					type: 'review',
				},
				ratingProps: {
					name: 'course-review',
				},
			})

			new CommentApp('#comment-app', {
				queryParams: {
					type: 'comment',
				},
			})

			// 加入購物車樣式調整
			cart()
		})
	})(jQuery)
