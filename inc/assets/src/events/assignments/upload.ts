/**
 * 教室作業附件上傳（Issue #131 P8）
 *
 * 選檔後立刻以 multipart 上傳到 POST power-course/my/assignment-attachments，
 * 成功後只把「檔名」掛成下載連結（回應刻意不含 url / stored_path，DEC-09 / R-04）。
 * 附件與繳交是兩段式：這裡只拿到 attachment_id，真正引用是在按下繳交時。
 */
import { __ } from '@wordpress/i18n'

import {
	appendAttachmentLink,
	resolveErrorMessage,
	showCardMessage,
} from './render'

/** 上傳成功的回應（api.yml AssignmentAttachmentMeta） */
type UploadedAttachment = {
	attachment_id?: unknown
	file_name?: unknown
}

/**
 * 綁定作業區塊內所有 file input 的上傳行為（事件委派，卡片是 PHP 渲染的靜態 DOM）
 */
export function bindAssignmentUpload(
	section: HTMLElement,
	restUrl: string,
	nonce: string,
): void {
	section.addEventListener('change', (event) => {
		const target = event.target as HTMLElement | null
		if (
			!target ||
			!target.classList.contains('pc-assignment-card__file-input')
		) {
			return
		}

		const input = target as HTMLInputElement
		const card = input.closest<HTMLElement>('.pc-assignment-card')
		if (!card || card.dataset.locked === 'true') return

		void uploadSelectedFiles(card, input, restUrl, nonce)
	})
}

/**
 * 逐一上傳使用者這次選的檔案
 */
async function uploadSelectedFiles(
	card: HTMLElement,
	input: HTMLInputElement,
	restUrl: string,
	nonce: string,
): Promise<void> {
	const files = Array.from(input.files || [])
	if (!files.length) return

	showCardMessage(card, '', false)

	for (const file of files) {
		const succeeded = await uploadOne(card, file, restUrl, nonce)
		if (!succeeded) break
	}

	// 清空以便同一個檔案可以重新選取
	input.value = ''
}

/**
 * 上傳單一檔案，成功時把檔名掛成下載連結
 */
async function uploadOne(
	card: HTMLElement,
	file: File,
	restUrl: string,
	nonce: string,
): Promise<boolean> {
	const formData = new FormData()
	formData.append('file', file, file.name)

	const fallback = __('Failed to upload attachment', 'power-course')

	try {
		const response = await fetch(`${restUrl}my/assignment-attachments`, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': nonce },
			body: formData,
		})

		const payload = (await response.json().catch(() => null)) as
			| UploadedAttachment
			| null
		const attachmentId = Number(payload?.attachment_id)

		if (!response.ok || !Number.isInteger(attachmentId) || attachmentId <= 0) {
			showCardMessage(card, resolveErrorMessage(payload, fallback), true)
			return false
		}

		const fileName =
			typeof payload?.file_name === 'string' && payload.file_name
				? payload.file_name
				: file.name

		appendAttachmentLink(card, restUrl, nonce, attachmentId, fileName)
		return true
	} catch {
		showCardMessage(card, fallback, true)
		return false
	}
}
