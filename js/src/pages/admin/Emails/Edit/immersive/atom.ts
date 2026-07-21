import { atomWithStorage } from 'jotai/utils'

/**
 * 郵件編輯器沉浸式版面偏好 atom
 *
 * 以 localStorage 持久化管理員上次選擇的版面模式（沉浸全螢幕 / 一般後台版面）。
 * - 預設 `true`：首次造訪自動進入沉浸模式（對應 Feature Rule「自動進入」）。
 * - localStorage 不可用（隱私模式 / 停用）時，`atomWithStorage` 會自動 fallback
 *   回此預設值，不拋錯。
 *
 * key 使用連字號 `pc-email-editor-immersive`，對齊專案命名慣例；
 * 儲存值為 JSON boolean（`'true'` / `'false'`）。
 */
export const immersiveAtom = atomWithStorage<boolean>(
	'pc-email-editor-immersive',
	true,
)
