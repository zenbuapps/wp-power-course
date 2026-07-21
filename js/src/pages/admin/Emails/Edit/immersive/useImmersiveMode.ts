import { useAtom } from 'jotai'
import { useCallback, useEffect } from 'react'

import { immersiveAtom } from './atom'

/** 沉浸模式掛在 `document.body` 上的 class，CSS 覆寫規則以此為 scope */
const IMMERSIVE_BODY_CLASS = 'pc-email-immersive'

/**
 * 沉浸式版面模式 Hook
 *
 * 讀寫 {@link immersiveAtom}（localStorage 持久化），並依 immersive 狀態同步
 * `document.body` 的 `pc-email-immersive` class，讓 scope 在 React root 之外的
 * WordPress DOM（`#adminmenumain`、`#wpadminbar` 等）也能被 CSS 覆寫。
 *
 * ⚠️ 卸載（離開郵件編輯頁）時務必移除 class，否則 WP 主選單 / 工具列會被 CSS
 * 永久隱藏，污染其他後台頁面。
 *
 * @return immersive 目前是否為沉浸模式；toggle 切換；setImmersive 直接設定
 */
export const useImmersiveMode = () => {
	const [immersive, setImmersive] = useAtom(immersiveAtom)

	// 依 immersive 狀態 add/remove body class；卸載時強制移除避免殘留污染其他頁
	useEffect(() => {
		const { body } = document
		if (immersive) {
			body.classList.add(IMMERSIVE_BODY_CLASS)
		} else {
			body.classList.remove(IMMERSIVE_BODY_CLASS)
		}

		return () => {
			body.classList.remove(IMMERSIVE_BODY_CLASS)
		}
	}, [immersive])

	// 首次造訪時 atomWithStorage 預設值不會自動寫入 localStorage，主動持久化一次，
	// 確保「偏好記憶」語意（下次進入依 storage 決定，而非每次都套預設）成立
	useEffect(() => {
		setImmersive((prev) => prev)
	}, [setImmersive])

	const toggle = useCallback(() => {
		setImmersive((prev) => !prev)
	}, [setImmersive])

	return { immersive, toggle, setImmersive }
}
