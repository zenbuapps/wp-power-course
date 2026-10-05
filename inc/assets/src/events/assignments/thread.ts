/**
 * 教室作業卡片的討論串（Issue #131 第二波 W4，DEC-W2-19）
 *
 * 區塊 HTML 由 PHP（inc/templates/components/assignment/thread.php）產生，這裡只負責：
 *   - 點「與老師討論」展開時才 GET power-course/my/assignment-submissions/{id}/messages
 *     （讀取 = 把老師訊息標為已讀，DEC-W2-04；頁面載入時不預先請求，R-W2-02）
 *   - 送出：POST 同一路徑，body 為 { body }，成功後就地附加新訊息
 *   - 頁內首次繳交成功後由 submit.ts 呼叫 revealThread() 顯示討論串
 *
 * 繳交 ID 一律在動作當下讀卡片的 data-submission-id，頁內首次繳交寫入後立即生效。
 *
 * DEC-15 / R-W2-09：訊息節點只由 createMessageNode() 從 PHP <template> clone，
 * 所有內容以 textContent 寫入，不得把任何字串當 HTML 解析（訊息本文原樣儲存，可能含 HTML）。
 */
import { __, _x } from '@wordpress/i18n'

import { resolveErrorMessage } from './render'

/** 討論訊息（api.yml AssignmentMessage） */
type ThreadMessage = {
	id?: unknown
	sender_type?: unknown
	body?: unknown
	read_at?: unknown
	created_at?: unknown
	mine?: unknown
}

/** 討論串請求的結果：HTTP 是否成功，與解析後的 JSON（解析失敗為 null） */
type ThreadResponse = {
	ok: boolean
	payload: unknown
}

/** 每個討論串目前有效的載入序號：快速收合再展開時，只採用最後一次請求的結果 */
const loadSequence = new WeakMap<HTMLElement, number>()

/**
 * 綁定作業區塊內所有討論串的行為（事件委派，卡片是 PHP 渲染的靜態 DOM）
 */
export function bindAssignmentThread(
	section: HTMLElement,
	restUrl: string,
	nonce: string,
): void {
	section.addEventListener('click', (event) => {
		const target = event.target as HTMLElement | null

		const toggle = target?.closest<HTMLButtonElement>(
			'.pc-assignment-card__thread-toggle',
		)
		if (toggle && section.contains(toggle)) {
			const thread = toggle.closest<HTMLElement>('.pc-assignment-card__thread')
			if (!thread) return

			event.preventDefault()
			toggleThread(thread, toggle, restUrl, nonce)
			return
		}

		const sendButton = target?.closest<HTMLButtonElement>(
			'.pc-assignment-card__thread-send',
		)
		if (sendButton && section.contains(sendButton) && !sendButton.disabled) {
			const thread = sendButton.closest<HTMLElement>(
				'.pc-assignment-card__thread',
			)
			if (!thread) return

			event.preventDefault()
			void sendMessage(thread, restUrl, nonce)
		}
	})

	// 輸入列按 Enter 送出；輸入法選字時的 Enter 只是確認選字，不送出
	section.addEventListener('keydown', (event) => {
		if (event.key !== 'Enter' || event.isComposing || event.shiftKey) return

		const target = event.target as HTMLElement | null
		if (!target?.classList.contains('pc-assignment-card__thread-input')) return

		const thread = target.closest<HTMLElement>('.pc-assignment-card__thread')
		if (!thread) return

		event.preventDefault()
		void sendMessage(thread, restUrl, nonce)
	})
}

/**
 * 頁內首次繳交成功後顯示討論串（由 submit.ts 在寫入 data-submission-id 之後呼叫）
 */
export function revealThread(card: HTMLElement): void {
	const thread = card.querySelector<HTMLElement>('.pc-assignment-card__thread')
	if (thread) {
		thread.hidden = false
	}
}

/**
 * 展開 / 收合；每次展開都重新讀取（與參考實作相同：展開才載入）
 */
function toggleThread(
	thread: HTMLElement,
	toggle: HTMLButtonElement,
	restUrl: string,
	nonce: string,
): void {
	const panel = thread.querySelector<HTMLElement>(
		'.pc-assignment-card__thread-panel',
	)
	if (!panel) return

	const willExpand = panel.hidden
	panel.hidden = !willExpand
	toggle.setAttribute('aria-expanded', willExpand ? 'true' : 'false')
	toggle.textContent = willExpand
		? __('Collapse discussion', 'power-course')
		: __('Discuss with teacher', 'power-course')

	if (willExpand) {
		void loadMessages(thread, restUrl, nonce)
	}
}

/**
 * 讀取討論串並重畫訊息清單
 */
async function loadMessages(
	thread: HTMLElement,
	restUrl: string,
	nonce: string,
): Promise<void> {
	const submissionId = getSubmissionId(thread)
	const list = thread.querySelector<HTMLElement>(
		'.pc-assignment-card__thread-list',
	)
	if (!submissionId || !list) return

	const sequence = (loadSequence.get(thread) ?? 0) + 1
	loadSequence.set(thread, sequence)

	list.replaceChildren()
	setThreadStatus(thread, __('Loading discussion...', 'power-course'))

	const fallback = __('Failed to load discussion', 'power-course')

	try {
		const { ok, payload } = await requestMessages(
			restUrl,
			nonce,
			submissionId,
			{ method: 'GET' },
		)

		// 期間又收合再展開過：交給最新那次請求處理
		if (loadSequence.get(thread) !== sequence) return

		if (!ok || !Array.isArray(payload)) {
			setThreadStatus(thread, resolveErrorMessage(payload, fallback))
			return
		}

		for (const message of payload as ThreadMessage[]) {
			appendMessage(thread, message)
		}
		setThreadStatus(
			thread,
			payload.length ? '' : __('No messages yet', 'power-course'),
		)
		scrollToBottom(list)
	} catch {
		if (loadSequence.get(thread) === sequence) {
			setThreadStatus(thread, fallback)
		}
	}
}

/**
 * 送出輸入列的內容，成功後就地附加回應中的新訊息
 */
async function sendMessage(
	thread: HTMLElement,
	restUrl: string,
	nonce: string,
): Promise<void> {
	const submissionId = getSubmissionId(thread)
	const input = thread.querySelector<HTMLInputElement>(
		'.pc-assignment-card__thread-input',
	)
	const button = thread.querySelector<HTMLButtonElement>(
		'.pc-assignment-card__thread-send',
	)
	const list = thread.querySelector<HTMLElement>(
		'.pc-assignment-card__thread-list',
	)
	if (!submissionId || !input || !button || !list || button.disabled) return

	// 只有空白時不送（與參考實作、後台 SubmissionThread 一致）
	const body = input.value.trim()
	if (!body) return

	const fallback = __('Failed to send message', 'power-course')

	button.disabled = true

	try {
		const { ok, payload } = await requestMessages(
			restUrl,
			nonce,
			submissionId,
			{
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ body }),
			},
		)

		if (!ok || !payload || typeof payload !== 'object') {
			setThreadStatus(thread, resolveErrorMessage(payload, fallback))
			return
		}

		appendMessage(thread, payload as ThreadMessage)
		setThreadStatus(thread, '')
		input.value = ''
		scrollToBottom(list)
	} catch {
		setThreadStatus(thread, fallback)
	} finally {
		button.disabled = false
	}
}

/**
 * 呼叫學員端討論串端點（讀取 GET 與送出 POST 共用）：一律帶 X-WP-Nonce 與同源 cookie
 */
async function requestMessages(
	restUrl: string,
	nonce: string,
	submissionId: number,
	init: {
		method: 'GET' | 'POST'
		headers?: Record<string, string>
		body?: string
	},
): Promise<ThreadResponse> {
	const response = await fetch(
		`${restUrl}my/assignment-submissions/${submissionId}/messages`,
		{
			...init,
			credentials: 'same-origin',
			headers: { ...init.headers, 'X-WP-Nonce': nonce },
		},
	)

	return {
		ok: response.ok,
		payload: (await response.json().catch(() => null)) as unknown,
	}
}

/**
 * 把一則訊息附加到清單（同一則訊息不重複附加）
 */
function appendMessage(thread: HTMLElement, message: ThreadMessage): void {
	const list = thread.querySelector<HTMLElement>(
		'.pc-assignment-card__thread-list',
	)
	if (!list) return

	const messageId = toPositiveId(message.id)
	if (
		messageId &&
		list.querySelector(
			`.pc-assignment-card__thread-message[data-message-id="${messageId}"]`,
		)
	) {
		return
	}

	const node = createMessageNode(thread, message)
	if (node) {
		list.appendChild(node)
	}
}

/**
 * 訊息節點唯一的產生來源：clone PHP 輸出的 <template>，再以 textContent 填值
 * （DEC-15、R-W2-09：不拼 HTML 字串，訊息本文即使含 HTML 也只會被當成文字）
 */
function createMessageNode(
	thread: HTMLElement,
	message: ThreadMessage,
): HTMLElement | null {
	const template = thread.querySelector<HTMLTemplateElement>(
		'.pc-assignment-card__thread-template',
	)
	const prototype = template?.content.firstElementChild
	if (!prototype) return null

	const node = prototype.cloneNode(true) as HTMLElement
	const messageId = toPositiveId(message.id)
	const mine = message.mine === true

	if (messageId) {
		node.dataset.messageId = String(messageId)
	}
	node.dataset.senderType = String(message.sender_type ?? '')
	node.classList.add(mine ? 'pc-chat-end' : 'pc-chat-start')

	setText(
		node,
		'.pc-assignment-card__thread-sender',
		mine
			? __('Me', 'power-course')
			: _x('Teacher', 'assignment thread sender', 'power-course'),
	)
	// created_at 是站台時區的 Y-m-d H:i:s，只去掉秒，不做瀏覽器時區換算
	setText(
		node,
		'.pc-assignment-card__thread-time',
		String(message.created_at ?? '').slice(0, 16),
	)
	// 訊息本文原樣儲存，可能含 HTML：只能用 textContent
	setText(node, '.pc-assignment-card__thread-body', String(message.body ?? ''))

	// 「已讀」只標在自己的訊息、且對方已讀過時
	if (!mine || !message.read_at) {
		node.querySelector('.pc-assignment-card__thread-read')?.remove()
	}

	return node
}

/**
 * 狀態提示（載入中 / 尚無訊息 / 錯誤），空字串代表隱藏
 */
function setThreadStatus(thread: HTMLElement, message: string): void {
	const status = thread.querySelector<HTMLElement>(
		'.pc-assignment-card__thread-status',
	)
	if (!status) return

	status.textContent = message
	status.hidden = !message
}

/**
 * 以 textContent 寫入 root 內的子元素（不解析 HTML）
 */
function setText(root: HTMLElement, selector: string, text: string): void {
	const element = root.querySelector<HTMLElement>(selector)
	if (element) {
		element.textContent = text
	}
}

function scrollToBottom(list: HTMLElement): void {
	list.scrollTop = list.scrollHeight
}

/**
 * 卡片目前的繳交 ID（data-submission-id；空字串代表尚未繳交）
 */
function getSubmissionId(thread: HTMLElement): number {
	const card = thread.closest<HTMLElement>('.pc-assignment-card')

	return toPositiveId(card?.dataset.submissionId)
}

/**
 * 轉成正整數 ID；不是正整數（空字串、undefined、null、小數、負數…）一律回 0
 */
function toPositiveId(value: unknown): number {
	const id = Number(value)

	return Number.isInteger(id) && id > 0 ? id : 0
}
