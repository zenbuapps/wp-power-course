import { __ } from '@wordpress/i18n'
import $ from 'jquery'
import { debounce } from 'lodash-es'

/**
 * 課程銷售頁公告卡片內文折疊（Issue #224）
 *
 * 模板（announcement.php）已輸出每張卡片的內文 + toggle 容器：
 *   <div class="pc-announcement-content" id="..." aria-expanded="false">...</div>
 *   <div class="pc-announcement-toggle" hidden data-target="...">
 *     <div class="pc-announcement-toggle__mask"></div>
 *     <button type="button" aria-controls="..." aria-expanded="false">Expand content</button>
 *   </div>
 *
 * 本模組負責的工作：
 * 1. DOM ready 後遍歷 .pc-announcement-content，量測 scrollHeight > clientHeight + 1
 *    - 超出 → 顯示對應 .pc-announcement-toggle（移除 hidden）
 *    - 未超出 → 將 content 的 aria-expanded 改為 "true" 使 CSS 解除 line-clamp，
 *               toggle 保持 hidden（節省版面）
 * 2. 點擊 .pc-announcement-toggle 內的 <button> →
 *    切換 content + button 的 aria-expanded、更新按鈕文字（Expand content ↔ Collapse）、
 *    並切換 .is-expanded class 觸發漸層遮罩淡出
 * 3. window.resize debounced 200ms 重評：避免轉向 / 縮放後行數變化造成 false positive/negative
 *
 * 注意：使用事件委派（$(document).on）綁定 click，避免 reevaluate 時的 hidden 切換
 *       讓 handler 重新失效。
 */
export const announcementToggle = () => {
	const SELECTOR_CONTENT = '.pc-announcement-content'
	const SELECTOR_TOGGLE = '.pc-announcement-toggle'
	const SELECTOR_BTN = '.pc-announcement-toggle__btn'
	const EVENT_NS = '.pcAnnouncement'

	/**
	 * 重新量測每張卡片是否超出 N 行，並決定 toggle 容器顯示與否。
	 */
	const reevaluate = (): void => {
		$(SELECTOR_CONTENT).each(function () {
			const content = this as HTMLElement
			const targetId = content.id
			if (!targetId) return

			const toggle = document.querySelector<HTMLElement>(
				`${SELECTOR_TOGGLE}[data-target="${targetId}"]`,
			)
			if (!toggle) return

			// 量測前先強制以折疊狀態檢查（避免目前展開時 scrollHeight === clientHeight 造成 false negative）。
			const wasExpanded = content.getAttribute('aria-expanded') === 'true'
			if (wasExpanded) content.setAttribute('aria-expanded', 'false')

			const overflows = content.scrollHeight > content.clientHeight + 1

			if (overflows) {
				toggle.removeAttribute('hidden')
				// 還原使用者展開狀態（若先前是展開的）
				if (wasExpanded) {
					content.setAttribute('aria-expanded', 'true')
					toggle.classList.add('is-expanded')
				}
			} else {
				toggle.setAttribute('hidden', '')
				toggle.classList.remove('is-expanded')
				// 內文不超出 → 解除 line-clamp 讓整段顯示
				content.setAttribute('aria-expanded', 'true')
			}
		})
	}

	/**
	 * Click handler：切換 aria-expanded、按鈕文字、is-expanded class。
	 */
	const onToggleClick = (e: JQuery.ClickEvent): void => {
		e.preventDefault()
		const $btn = $(e.currentTarget as HTMLElement)
		const $toggle = $btn.closest(SELECTOR_TOGGLE)
		const targetId = ($toggle.attr('data-target') ?? '').trim()
		if (!targetId) return
		const $content = $(document.getElementById(targetId) as HTMLElement)
		if (!$content.length) return

		const isExpanded = $content.attr('aria-expanded') === 'true'
		const next = !isExpanded
		const nextValue = next ? 'true' : 'false'

		$content.attr('aria-expanded', nextValue)
		$btn.attr('aria-expanded', nextValue)
		$btn.text(
			next
				? __('Collapse', 'power-course')
				: __('Expand content', 'power-course'),
		)
		$toggle.toggleClass('is-expanded', next)
	}

	// 解綁先前可能的 binding（防止 hot reload / 重複呼叫造成重複觸發）
	$(document).off(`click${EVENT_NS}`, SELECTOR_BTN)
	$(window).off(`resize${EVENT_NS}`)

	$(document).on(`click${EVENT_NS}`, SELECTOR_BTN, onToggleClick)

	reevaluate()
	$(window).on(`resize${EVENT_NS}`, debounce(reevaluate, 200))
}
