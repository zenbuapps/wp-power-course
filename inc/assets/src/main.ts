/* eslint-disable lines-around-comment */
import jQuery from 'jquery'
import {
	finishChapter,
	dynamicWidth,
	tabs,
	coursesProduct,
	courses,
	toggleContent,
	countdown,
	CommentApp,
	cart,
	HlsSupport,
	watermarkPDF,
	linearViewing,
	announcementToggle,
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

			// Issue #236：課程列表 [pc_courses] 純 AJAX 傳統頁碼分頁
			courses()

			toggleContent()
			countdown()
			HlsSupport()

			// Issue #224：銷售頁公告卡片內文 > 3 行折疊
			announcementToggle()

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
