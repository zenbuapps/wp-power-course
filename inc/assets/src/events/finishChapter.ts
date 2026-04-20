/* eslint-disable @typescript-eslint/ban-ts-comment */
import { __ } from '@wordpress/i18n'
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
			icon_html,
		} = store.get(finishChapterAtom)

		const ChapterIcon = $(`li[data-post-id="${chapter_id}"]`).find(
			'.pc-chapter-icon',
		)

		if (isLoading) {
			FinishButton.find('.pc-loading-spinner').removeClass('tw-hidden')
		} else {
			FinishButton.find('.pc-loading-spinner').addClass('tw-hidden')
		}

		if (isSuccess) {
			if (ChapterIcon?.length > 0) {
				ChapterIcon.html(icon_html)
			}
			Dialog.find('#finish-chapter__dialog__title').text(
				__('Success', 'power-course'),
			)
			Dialog.find('#finish-chapter__dialog__message').text(dialogMessage)

			// 線性觀看：即時更新鎖定/解鎖狀態
			const { unlocked_chapter_ids, locked_chapter_ids } =
				store.get(finishChapterAtom)
			if (unlocked_chapter_ids !== null && unlocked_chapter_ids !== undefined) {
				updateChapterLockStatus(
					unlocked_chapter_ids as number[],
					(locked_chapter_ids || []) as number[],
				)
			}

			// FinishButton.hide()
			if (isFinished === true) {
				FinishButton.removeClass('text-white')
					.addClass('pc-btn-outline border-solid')
					.attr('data-finished', 'true')
					.find('span:first-child')
					.text(__('Mark as unfinished', 'power-course'))

				$('#classroom-chapter_title-badge')
					.removeClass('pc-badge-accent')
					.addClass('pc-badge-secondary')
					.text(__('Finished', 'power-course'))
			}

			if (isFinished === false) {
				FinishButton.removeClass('pc-btn-outline border-solid')
					.addClass('text-white')
					.attr('data-finished', 'false')
					.find('span:first-child')
					.text(__('Mark as finished', 'power-course'))

				$('#classroom-chapter_title-badge')
					.removeClass('pc-badge-secondary')
					.addClass('pc-badge-accent')
					.text(__('Unfinished', 'power-course'))
			}

			// 調整進度條
			if (progress !== undefined) {
				$('progress').attr('value', progress).prev().text(`${progress}%`)
			}
		}

		if (isError) {
			Dialog.find('#finish-chapter__dialog__title').text(
				__('Error', 'power-course'),
			)
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
		const customEvent = event as CustomEvent<{
			chapterId: number
			courseId: number
		}>
		const { chapterId, courseId } = customEvent.detail

		// 確認事件的 chapterId 與目前頁面的按鈕 data-chapter-id 相符
		const buttonChapterId = Number(FinishButton.data('chapter-id'))
		if (buttonChapterId !== chapterId) return

		// 若章節已完成（按鈕 data-finished="true"），不重複呼叫
		// 使用 data attribute 而非字串比較，以避免依賴 i18n 翻譯結果
		const isAlreadyFinished = FinishButton.attr('data-finished') === 'true'
		if (isAlreadyFinished) return

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
				const unlocked_chapter_ids =
					xhr?.responseJSON?.data?.unlocked_chapter_ids ?? null
				const locked_chapter_ids =
					xhr?.responseJSON?.data?.locked_chapter_ids ?? null

				// 自動完成成功後更新 pc_data.next_chapter_locked
				if (is_this_chapter_finished && unlocked_chapter_ids) {
					const nextBtn = document.querySelector('.pc-next-post')
					if (nextBtn) {
						// 透過 DOM 取得下一章的 post-id
						const nextPostId = Number(
							nextBtn
								.closest('[data-next-post-id]')
								?.getAttribute('data-next-post-id'),
						)
						if (
							nextPostId &&
							(unlocked_chapter_ids as number[]).includes(nextPostId)
						) {
							;(window as any).pc_data.next_chapter_locked = false
						}
					}
				}

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
					unlocked_chapter_ids,
					locked_chapter_ids,
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
				const message =
					xhr?.responseJSON?.message ||
					__('An error occurred. Please try again later', 'power-course')
				const is_this_chapter_finished =
					xhr?.responseJSON?.data?.is_this_chapter_finished
				const progress = xhr?.responseJSON?.data?.progress
				const icon_html = xhr?.responseJSON?.data?.icon_html
				const unlocked_chapter_ids =
					xhr?.responseJSON?.data?.unlocked_chapter_ids ?? null
				const locked_chapter_ids =
					xhr?.responseJSON?.data?.locked_chapter_ids ?? null

				// 手動取消完成（isFinished 變為 false）時，重置自動完成 flag，允許再次自動觸發
				if (is_this_chapter_finished === false) {
					const mapKey = String(chapter_id)
					delete hasAutoFinishedMap[mapKey]
				}

				// 更新 pc_data.next_chapter_locked
				if (unlocked_chapter_ids) {
					;(window as any).pc_data.next_chapter_locked =
						is_this_chapter_finished === false
				}

				store.set(finishChapterAtom, (prev) => ({
					...prev,
					isLoading: false,
					showDialog: true,
					dialogMessage: message,
					isFinished: is_this_chapter_finished,
					progress,
					icon_html,
					unlocked_chapter_ids,
					locked_chapter_ids,
				}))
			},
		})
	})
}

/**
 * 線性觀看：即時更新側邊欄章節的鎖定/解鎖狀態
 */
const LOCK_ICON_SVG =
	'<svg class="size-6" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 14.5V16.5M7 10.0288C7.47142 10 8.05259 10 8.8 10H15.2C15.9474 10 16.5286 10 17 10.0288M7 10.0288C6.41168 10.0647 5.99429 10.1455 5.63803 10.327C5.07354 10.6146 4.6146 11.0735 4.32698 11.638C4 12.2798 4 13.1198 4 14.8V16.2C4 17.8802 4 18.7202 4.32698 19.362C4.6146 19.9265 5.07354 20.3854 5.63803 20.673C6.27976 21 7.11984 21 8.8 21H15.2C16.8802 21 17.7202 21 18.362 20.673C18.9265 20.3854 19.3854 19.9265 19.673 19.362C20 18.7202 20 17.8802 20 16.2V14.8C20 13.1198 20 12.2798 19.673 11.638C19.3854 11.0735 18.9265 10.6146 18.362 10.327C18.0057 10.1455 17.5883 10.0647 17 10.0288M7 10.0288V8C7 5.23858 9.23858 3 12 3C14.7614 3 17 5.23858 17 8V10.0288" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>'

function updateChapterLockStatus(unlockedIds: number[], lockedIds: number[]) {
	// 解鎖章節
	for (const id of unlockedIds) {
		const $li = $(`li[data-post-id="${id}"]`)
		if ($li.length === 0) continue
		$li.removeClass('pc-chapter-locked')
		$li.removeAttr('data-locked')
		$li.removeAttr('data-lock-message')
		$li.find('span').css('opacity', '')
	}

	// 鎖定章節（取消完成時）
	for (const id of lockedIds) {
		const $li = $(`li[data-post-id="${id}"]`)
		if ($li.length === 0) continue
		$li.addClass('pc-chapter-locked')
		$li.attr('data-locked', 'true')
		$li.find('span').css('opacity', '0.5')
		// 替換 icon 為鎖頭
		const $icon = $li.find('.pc-chapter-icon')
		if ($icon.length > 0) {
			$icon.html(
				`<div class="pc-tooltip pc-tooltip-right h-6">${LOCK_ICON_SVG}</div>`,
			)
		}
	}

	// 更新底部「下一個」按鈕
	updateNextButton(unlockedIds)
}

/**
 * 更新底部「下一個」按鈕的鎖定/解鎖狀態
 */
function updateNextButton(unlockedIds: number[]) {
	const $nextBtn = $('.pc-next-post')
	if ($nextBtn.length === 0) return
	const nextLocked = $nextBtn.attr('data-next-locked')
	if (nextLocked === 'true') {
		// 檢查下一章是否在解鎖列表中（若是，則解鎖按鈕）
		// 簡化處理：直接根據 pc_data.next_chapter_locked 更新
		if (!(window as any).pc_data?.next_chapter_locked) {
			$nextBtn
				.removeClass('pc-btn-disabled pointer-events-none opacity-50')
				.removeAttr('aria-disabled')
				.attr('data-next-locked', 'false')
		}
	}
}
