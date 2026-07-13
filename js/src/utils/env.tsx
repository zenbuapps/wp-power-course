import { simpleDecrypt } from 'antd-toolkit'

// @ts-ignore
const encryptedEnv = window?.power_course_data?.env

if (!encryptedEnv) {
	throw new Error('env is not found')
}

export const env = simpleDecrypt(encryptedEnv)
export const API_URL = env?.API_URL || '/wp-json'
/**
 * 站台網址（不含結尾斜線），由 Bootstrap 注入（site_url()）。
 * 供 MCP Token 快速設定範本組出對外 endpoint（Issue #230）。
 * 為空時範本退回相對路徑 `/wp-json/...`。
 */
export const SITE_URL: string = env?.SITE_URL || ''
export const APP1_SELECTOR = env?.APP1_SELECTOR || '#power_course'
export const APP2_SELECTOR = env?.APP2_SELECTOR || '.pc-vidstack'
/**
 * 當前使用者是否具備 administrator 角色（由 Bootstrap.php 注入，檢查 wp_get_current_user()->roles）。
 * Boolean() 包裹確保 undefined / null / 字串 'false' 等 falsy 值一律 fallback 為 false（fail-safe）。
 * 本旗標僅供 UI 層 gate 使用（如隱藏 AI Tab），非安全邊界（Issue #221）。
 * 真正的權限執行點：inc/classes/Api/Mcp/RestController.php::permission_callback()
 */
export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)
/**
 * 當前使用者的 WordPress locale 字串（由 Bootstrap.php 注入，determine_locale() 結果，
 * user profile locale 優先於 site locale），例如 'zh_TW' / 'en_US' / 'ja'。
 * 供前端 antd-toolkit LocaleProvider 對應顯示語言（見 getAntdToolkitLocale）。
 * 為空時 fallback 'zh_TW'（專案主要介面語言）。
 */
export const LOCALE: string = env?.LOCALE || 'zh_TW'
export const DEFAULT_IMAGE = 'https://placehold.co/480x480?text=%3CIMG%20/%3E'
