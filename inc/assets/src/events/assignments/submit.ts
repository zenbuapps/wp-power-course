/**
 * 教室作業繳交（Issue #131 P8）
 *
 * POST power-course/my/assignments/{id}/submit，body 為 { text_body, attachment_ids }。
 * 成功後就地把狀態徽章改成「待批改」、按鈕文案改成「重新繳交」、清掉上一輪評語，
 * 不重新整理整頁（卡片 HTML 仍由 PHP 那一份負責）。
 */
import { __ } from '@wordpress/i18n'

import {
	clearFeedback,
	collectAttachmentIds,
	resolveErrorMessage,
	setCardStatus,
	setSubmitLabelToResubmit,
	showCardMessage,
} from './render'

/** 繳交成功的回應（api.yml AssignmentSubmission） */
type SubmissionResponse = {
	id?: unknown
}

/**
 * 綁定作業區塊內所有繳交按鈕（事件委派）
 */
export function bindAssignmentSubmit(
	section: HTMLElement,
	restUrl: string,
	nonce: string,
): void {
	section.addEventListener('click', (event) => {
		const target = event.target as HTMLElement | null
		const button = target?.closest<HTMLButtonElement>(
			'.pc-assignment-card__submit-btn',
		)
		if (!button || !section.contains(button) || button.disabled) return

		const card = button.closest<HTMLElement>('.pc-assignment-card')
		if (!card) return

		event.preventDefault()
		void submitAssignment(card, button, restUrl, nonce)
	})
}

/**
 * 送出繳交並更新卡片狀態
 */
async function submitAssignment(
	card: HTMLElement,
	button: HTMLButtonElement,
	restUrl: string,
	nonce: string,
): Promise<void> {
	const assignmentId = Number(card.dataset.assignmentId)
	if (!Number.isInteger(assignmentId) || assignmentId <= 0) return

	const textInput = card.querySelector<HTMLTextAreaElement>(
		'.pc-assignment-card__text-input',
	)

	const body = JSON.stringify({
		text_body: textInput ? textInput.value : '',
		attachment_ids: collectAttachmentIds(card),
	})

	const fallback = __('Failed to submit assignment', 'power-course')

	button.disabled = true
	showCardMessage(card, '', false)

	try {
		const response = await fetch(
			`${restUrl}my/assignments/${assignmentId}/submit`,
			{
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body,
			},
		)

		const payload = (await response.json().catch(() => null)) as
			| SubmissionResponse
			| null
		const submissionId = Number(payload?.id)

		if (!response.ok || !Number.isInteger(submissionId) || submissionId <= 0) {
			showCardMessage(card, resolveErrorMessage(payload, fallback), true)
			return
		}

		setCardStatus(card, 'pending')
		setSubmitLabelToResubmit(card)
		clearFeedback(card)
		showCardMessage(card, __('Assignment submitted', 'power-course'), false)
	} catch {
		showCardMessage(card, fallback, true)
	} finally {
		button.disabled = false
	}
}
