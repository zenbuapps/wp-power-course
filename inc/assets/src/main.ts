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
	linearViewing,
	announcementToggle,
	qaAccordion,
} from './events'
	; (function ($) {
		$(document).ready(function () {
			// classroom 頁面，完成章節
			finishChapter()

			// 線性觀看互動（鎖定章節點擊攔截、toast 提示）
			linearViewing()

			// 改變大小時設定 state
			dynamicWidth()

			// 添加 tabs 組件事件
			tabs()
			coursesProduct()
			toggleContent()
			countdown()
			HlsSupport()

			// Issue #224：銷售頁公告卡片內文 > 3 行折疊
			announcementToggle()

			// Issue #242：銷售頁問與答排他展開（同時只開一個，可全部收合）
			qaAccordion()

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
