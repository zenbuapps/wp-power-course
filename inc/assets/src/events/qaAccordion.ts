import $ from 'jquery'

/**
 * 課程銷售頁「問與答」排他展開（Issue #242）
 *
 * 模板（collapse/qa.php）輸出結構：
 *   <div class="pc-qa-list" data-pc-qa-exclusive>
 *     <div class="pc-collapse pc-collapse-arrow ...">
 *       <input type="checkbox" class="pc-qa-item__toggle" [checked] />
 *       <div class="pc-collapse-title ...">...</div>
 *       <div class="pc-collapse-content ...">...</div>
 *     </div>
 *     ...
 *   </div>
 *
 * 設計取捨（為什麼用 checkbox + JS，而非 radio）：
 *   - DaisyUI 的 pc-collapse 以 <input> 的 :checked 狀態驅動 CSS 展開／收合動畫，
 *     沿用 checkbox 即可保留原生平滑動畫，且 checkbox 點擊「已開項目」會自然收合，
 *     滿足「全部收合」需求。
 *   - radio（同 name）雖能做到「只開一個」，但原生無法點擊收合，不符驗收標準。
 *   - 因此保留 checkbox，僅以 JS 強制排他：某項展開時，將同組其餘項目收合。
 *
 * 本模組負責：
 * 1. 監聽 .pc-qa-list[data-pc-qa-exclusive] 內 checkbox 的 change 事件（事件委派）。
 * 2. 當某項變為展開（checked）時，將同組其餘 checkbox 取消勾選 → 觸發 DaisyUI 收合動畫。
 *
 * 注意：使用事件委派（$(document).on）綁定，Q&A 位於可隱藏的 tab 面板內，
 *       DOM ready 即存在，委派可確保切換 tab 後仍正常運作。
 */
export const qaAccordion = () => {
	const GROUP_SELECTOR = '.pc-qa-list[data-pc-qa-exclusive]'
	const TOGGLE_SELECTOR = '.pc-qa-item__toggle'
	const EVENT_NS = '.pcQaAccordion'

	/**
	 * 某項展開時，收合同組其餘項目（排他）。
	 */
	const onToggleChange = (e: JQuery.ChangeEvent): void => {
		const changed = e.currentTarget as HTMLInputElement
		// 僅在「展開」時才需要收合其他項目；收合自身不影響其他項目。
		if (!changed.checked) return

		const $group = $(changed).closest(GROUP_SELECTOR)
		if (!$group.length) return

		$group
			.find(TOGGLE_SELECTOR)
			.not(changed)
			.each(function () {
				const other = this as HTMLInputElement
				if (other.checked) {
					other.checked = false
				}
			})
	}

	// 解綁先前可能的 binding（防止 hot reload / 重複呼叫造成重複觸發）
	$(document).off(`change${EVENT_NS}`, `${GROUP_SELECTOR} ${TOGGLE_SELECTOR}`)
	$(document).on(
		`change${EVENT_NS}`,
		`${GROUP_SELECTOR} ${TOGGLE_SELECTOR}`,
		onToggleChange,
	)
}
