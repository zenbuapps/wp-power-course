import { simpleDecrypt } from 'antd-toolkit'

// @ts-ignore
const encryptedEnv = window?.power_course_data?.env

if (!encryptedEnv) {
	throw new Error('env is not found')
}

export const env = simpleDecrypt(encryptedEnv)
export const API_URL = env?.API_URL || '/wp-json'
export const APP1_SELECTOR = env?.APP1_SELECTOR || '#power_course'
export const APP2_SELECTOR = env?.APP2_SELECTOR || '.pc-vidstack'
/**
 * 當前使用者是否具備 manage_options（admin）權限。
 * Boolean() 包裹確保 undefined / null / 字串 'false' 等 falsy 值一律 fallback 為 false（fail-safe）。
 * 本旗標僅供 UI 層 gate 使用（如隱藏 AI Tab），非安全邊界（Issue #221）。
 * 真正的權限執行點：inc/classes/Api/Mcp/RestController.php::permission_callback()
 */
export const IS_ADMIN: boolean = Boolean(env?.IS_ADMIN)
export const DEFAULT_IMAGE = 'https://placehold.co/480x480?text=%3CIMG%20/%3E'
