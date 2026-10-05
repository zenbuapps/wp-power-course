/**
 * 教室作業卡片的 DOM 更新輔助（Issue #131 P8）
 *
 * 卡片 HTML 只由 PHP（inc/templates/components/assignment/card.php）產生一份，
 * 這裡只負責在上傳 / 繳交成功後就地更新狀態欄位與附件連結。
 *
 * DEC-15：所有使用者輸入（檔名、伺服器錯誤訊息）一律以 textContent 寫入，
 * 全檔不得以 HTML 字串寫入 DOM。
 */
import { _x } from '@wordpress/i18n'

/** 繳交狀態（none 代表尚未繳交，其餘對齊 pc_assignment_submissions.status） */
export type AssignmentStatus = 'none' | 'pending' | 'pass' | 'fail'

/** 狀態 → 徽章樣式（與 card.php 的 $badge_class_map 對齊） */
const STATUS_BADGE_CLASS: Record<AssignmentStatus, string> = {
	none: 'pc-badge-ghost',
	pending: 'pc-badge-accent',
	pass: 'pc-badge-success',
	fail: 'pc-badge-error',
}

/**
 * 狀態的在地化標籤（與 PHP Utils\Utils::status_label() 同一組 msgid / msgctxt）
 */
export function statusLabel(status: AssignmentStatus): string {
	switch (status) {
		case 'pending':
			return _x('Pending', 'assignment submission status', 'power-course')
		case 'pass':
			return _x('Passed', 'assignment submission status', 'power-course')
		case 'fail':
			return _x('Failed', 'assignment submission status', 'power-course')
		default:
			return _x('Not submitted', 'assignment submission status', 'power-course')
	}
}

/**
 * 更新卡片的狀態徽章
 */
export function setCardStatus(card: HTMLElement, status: AssignmentStatus): void {
	card.dataset.status = status

	const badge = card.querySelector<HTMLElement>('.pc-assignment-card__status')
	if (badge) {
		Object.values(STATUS_BADGE_CLASS).forEach((className) =>
			badge.classList.remove(className),
		)
		badge.classList.add(STATUS_BADGE_CLASS[status])
		badge.textContent = statusLabel(status)
	}

	// 「已逾期」只針對「未繳交且已過截止」，繳交之後就撤掉警示
	if (status !== 'none') {
		const overdue = card.querySelector<HTMLElement>(
			'.pc-assignment-card__overdue',
		)
		if (overdue) {
			overdue.style.display = 'none'
		}
	}
}

/**
 * 已繳交後按鈕文案改為「重新繳交」
 */
export function setSubmitLabelToResubmit(card: HTMLElement): void {
	const button = card.querySelector<HTMLButtonElement>(
		'.pc-assignment-card__submit-btn',
	)
	if (button) {
		button.textContent = _x('Resubmit', 'assignment submission', 'power-course')
	}
}

/**
 * 重新繳交會把 status 歸零、feedback 清空（Service\Submission 的 UPSERT 行為），
 * 畫面上的舊評語同步撤掉，避免顯示上一輪的批改結果
 */
export function clearFeedback(card: HTMLElement): void {
	const feedback = card.querySelector<HTMLElement>(
		'.pc-assignment-card__feedback',
	)
	if (!feedback) return

	const text = feedback.querySelector<HTMLElement>(
		'.pc-assignment-card__feedback-text',
	)
	if (text) {
		text.textContent = ''
	}
	feedback.style.display = 'none'
}

/**
 * 卡片內的提示訊息（成功 / 失敗），空字串代表隱藏
 */
export function showCardMessage(
	card: HTMLElement,
	message: string,
	isError: boolean,
): void {
	const element = card.querySelector<HTMLElement>(
		'.pc-assignment-card__message',
	)
	if (!element) return

	element.textContent = message
	element.classList.toggle('pc-assignment-card__message--error', isError)
	element.style.display = message ? '' : 'none'
}

/**
 * 附件下載連結（只顯示檔名，URL 走驗權後串流的 REST 端點，DEC-09）
 */
export function appendAttachmentLink(
	card: HTMLElement,
	restUrl: string,
	nonce: string,
	attachmentId: number,
	fileName: string,
): void {
	const list = card.querySelector<HTMLElement>(
		'.pc-assignment-card__attachments',
	)
	if (!list) return

	if (
		list.querySelector(
			`.pc-assignment-card__attachment-link[data-attachment-id="${attachmentId}"]`,
		)
	) {
		return
	}

	const link = document.createElement('a')
	link.className = 'pc-assignment-card__attachment-link text-sm underline'
	link.dataset.attachmentId = String(attachmentId)
	link.href = `${restUrl}assignment-attachments/${attachmentId}/download?_wpnonce=${encodeURIComponent(
		nonce,
	)}`
	// 檔名是使用者輸入，只能用 textContent
	link.textContent = fileName
	list.appendChild(link)
}

/**
 * 繳交時要一起送出的附件 ID（既有的 + 本次剛上傳的）
 */
export function collectAttachmentIds(card: HTMLElement): number[] {
	const links = card.querySelectorAll<HTMLAnchorElement>(
		'.pc-assignment-card__attachment-link[data-attachment-id]',
	)

	return Array.from(links)
		.map((link) => Number(link.dataset.attachmentId))
		.filter((id) => Number.isInteger(id) && id > 0)
}

/**
 * 從 REST 錯誤回應取出可顯示的訊息（WP_Error 形狀為 { code, message, data }）
 */
export function resolveErrorMessage(
	payload: unknown,
	fallback: string,
): string {
	if (payload && typeof payload === 'object') {
		const message = (payload as { message?: unknown }).message
		if (typeof message === 'string' && message) {
			return message
		}
	}

	return fallback
}
