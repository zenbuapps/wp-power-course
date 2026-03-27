/* eslint-disable @typescript-eslint/ban-ts-comment */
import $ from 'jquery'
import { store, finishChapterAtom } from '../store'
import { site_url } from '../utils'

/**
 * 追蹤各章節是否已自動觸發完成，防止同一頁面週期內重複呼叫 API
 * key 為 chapterId（字串），value 為是否已觸發
 */
const hasAutoFinishedMap: Record<string, boolean> = {}

export function finishChapter() {
	const Dialog = $('#finish-chapter__dialog')
	const FinishButton = $('#finish-chapter__button')

	store.sub(finishChapterAtom, () => {
		const {
			isLoading,
			showDialog,
			isSuccess,
			chapter_id,
			isError,
			dialogMessage,
			isFinished,
			progress,
			icon_html
		} = store.get(finishChapterAtom)

		const ChapterIcon = $(`li[data-post-id="${chapter_id}"]`).find('.pc-chapter-icon')

		if (isLoading) {
			FinishButton.find('.pc-loading-spinner').removeClass('tw-hidden')
		} else {
			FinishButton.find('.pc-loading-spinner').addClass('tw-hidden')
		}

		if (isSuccess) {
			if (ChapterIcon?.length > 0) {
				ChapterIcon.html(icon_html)
			}
			Dialog.find('#finish-chapter__dialog__title').text('成功')
			Dialog.find('#finish-chapter__dialog__message').text(dialogMessage)

			// FinishButton.hide()
			if (isFinished === true) {
				FinishButton.removeClass('text-white').addClass('pc-btn-outline border-solid')
					.find('span:first-child')
					.text('標示為未完成')

				$('#classroom-chapter_title-badge')
					.removeClass('pc-badge-accent')
					.addClass('pc-badge-secondary')
					.text('已完成')
			}

			if (isFinished === false) {
				FinishButton.removeClass('pc-btn-outline border-solid').addClass('text-white')
					.find('span:first-child')
					.text('標示為已完成')

				$('#classroom-chapter_title-badge')
					.removeClass('pc-badge-secondary')
					.addClass('pc-badge-accent')
					.text('未完成')
			}

			// 調整進度條
			if (progress !== undefined) {
				$('progress').attr('value', progress).prev().text(`${progress}%`)
			}
		}

		if (isError) {
			Dialog.find('#finish-chapter__dialog__title').text('錯誤')
			Dialog.find('#finish-chapter__dialog__message').text(dialogMessage)
		}

		if (showDialog) {
			// @ts-ignore
			Dialog?.[0]?.showModal()
		} else {
			// @ts-ignore
			Dialog?.[0]?.close()
		}
	})

	// 監聽影片自動完成事件（由 Player.tsx dispatch 的 pc:auto-finish-chapter）
	document.addEventListener('pc:auto-finish-chapter', (event) => {
		const customEvent = event as CustomEvent<{ chapterId: number; courseId: number }>
		const { chapterId, courseId } = customEvent.detail

		// 確認事件的 chapterId 與目前頁面的按鈕 data-chapter-id 相符
		const buttonChapterId = Number(FinishButton.data('chapter-id'))
		if (buttonChapterId !== chapterId) return

		// 若章節已完成（按鈕為「標示為未完成」狀態），不重複呼叫
		const buttonText = FinishButton.find('span:first-child').text()
		if (buttonText === '標示為未完成') return

		// 防止同一頁面週期重複觸發
		const mapKey = String(chapterId)
		if (hasAutoFinishedMap[mapKey]) return
		hasAutoFinishedMap[mapKey] = true

		// 靜默呼叫 toggle API，不顯示對話框
		$.ajax({
			url: `${site_url}/wp-json/power-course/toggle-finish-chapters/${chapterId}`,
			type: 'post',
			data: {
				course_id: courseId,
			},
			headers: {
				'X-WP-Nonce': (window as any).pc_data?.nonce,
			},
			timeout: 30000,
			complete(xhr) {
				const is_this_chapter_finished =
					xhr?.responseJSON?.data?.is_this_chapter_finished
				const progress = xhr?.responseJSON?.data?.progress
				const icon_html = xhr?.responseJSON?.data?.icon_html
				const message = xhr?.responseJSON?.message || ''

				store.set(finishChapterAtom, (prev) => ({
					...prev,
					course_id: courseId,
					chapter_id: chapterId,
					isLoading: false,
					// 靜默模式：不彈出對話框
					showDialog: false,
					isSuccess: true,
					dialogMessage: message,
					isFinished: is_this_chapter_finished,
					progress,
					icon_html,
				}))
			},
			error(_xhr, _status, errorMsg) {
				console.warn('[PowerCourse] 自動完成章節 API 呼叫失敗：', errorMsg)
			},
		})
	})

	// 完成章節事件
	FinishButton.on('click', function (e) {
		const course_id = $(this).data('course-id')
		const chapter_id = $(this).data('chapter-id')

		store.set(finishChapterAtom, (prev) => ({
			...prev,
			course_id,
			chapter_id,
			isLoading: true,
		}))

		$.ajax({
			url: `${site_url}/wp-json/power-course/toggle-finish-chapters/${chapter_id}`,
			type: 'post',
			data: {
				course_id,
			},
			headers: {
				'X-WP-Nonce': (window as any).pc_data?.nonce,
			},
			timeout: 30000,
			success(response) {
				const { code } = response
				store.set(finishChapterAtom, (prev) => ({
					...prev,
					isSuccess: '200' === code,
				}))
			},
			error(error) {
				console.log('error', error)
				store.set(finishChapterAtom, (prev) => ({
					...prev,
					isSuccess: false,
					isError: true,
				}))
			},
			complete(xhr) {
				const message = xhr?.responseJSON?.message || '發生錯誤，請稍後再試'
				const is_this_chapter_finished =
					xhr?.responseJSON?.data?.is_this_chapter_finished
				const progress = xhr?.responseJSON?.data?.progress
				const icon_html = xhr?.responseJSON?.data?.icon_html

				// 手動取消完成（isFinished 變為 false）時，重置自動完成 flag，允許再次自動觸發
				if (is_this_chapter_finished === false) {
					const mapKey = String(chapter_id)
					delete hasAutoFinishedMap[mapKey]
				}

				store.set(finishChapterAtom, (prev) => ({
					...prev,
					isLoading: false,
					showDialog: true,
					dialogMessage: message,
					isFinished: is_this_chapter_finished,
					progress,
					icon_html,
				}))
			},
		})
	})
}
