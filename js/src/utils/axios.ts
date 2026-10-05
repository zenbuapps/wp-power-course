import type { AxiosInstance, InternalAxiosRequestConfig } from 'axios'

/**
 * 是否為一般物件（物件字面量或 Object.create(null)）
 *
 * FormData / Blob / URLSearchParams / 字串 / 陣列等都不算，維持 axios 依型別的原本處理
 */
const isPlainObject = (value: unknown): value is Record<string, unknown> => {
	if (Object.prototype.toString.call(value) !== '[object Object]') {
		return false
	}
	const proto = Object.getPrototypeOf(value)
	return proto === null || proto === Object.prototype
}

/**
 * 讓非 POST 請求的物件 payload 不被誤送成 multipart
 *
 * 為什麼需要：
 * 1. antd-toolkit 的 dataProvider `custom()` 會把呼叫端傳入的 headers 寫進共用 axios instance 的
 *    `defaults.headers`，不只影響那一次請求。後台多處 useCustomMutation 帶
 *    `'Content-Type': 'multipart/form-data;'`，做過一次之後，同一個頁面生命週期的請求全部沿用
 * 2. axios 看到 multipart Content-Type + 物件 payload，會自動把 payload 轉成 FormData
 * 3. PHP 只替 POST 解析 multipart，WordPress REST 讀不到 PATCH / PUT / DELETE 的 multipart 本文
 *    （例如作業批改的 PATCH 收不到 status 而回 400）
 *
 * 只把「非 POST + 一般物件 payload + Content-Type 為 multipart」的請求改回 application/json：
 * - POST 一律不動：既有 POST 端點不少以 `get_body_params()` 讀 multipart，改成 JSON 會壞
 * - FormData / Blob / URLSearchParams / 字串等 payload 不動
 * - 未被污染的請求本來就是 JSON（instance 預設 application/json），不受影響
 *
 * @param instance 共用的 axios instance（antd-toolkit EnvProvider 建立）
 * @return 移除這個 interceptor 的 cleanup 函式
 */
export const ensureJsonForNonPostRequests = (instance: AxiosInstance) => {
	const interceptorId = instance.interceptors.request.use(
		(config: InternalAxiosRequestConfig) => {
			const method = (config.method || 'get').toLowerCase()
			const contentType = String(config.headers.getContentType() || '')

			if (
				method !== 'post' &&
				isPlainObject(config.data) &&
				contentType.includes('multipart/form-data')
			) {
				config.headers.setContentType('application/json')
			}

			return config
		}
	)

	return () => {
		instance.interceptors.request.eject(interceptorId)
	}
}
