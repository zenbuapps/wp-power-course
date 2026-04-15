/**
 * @wordpress/i18n 的 shim —— 把 import 導向 window.wp.i18n，讓 React bundle 與 Bootstrap.php
 * 的 inject_locale_data_to_handle() 所 setLocaleData 的 locale store 共用同一份。
 *
 * 不走 shim 的話 Vite 會把 @wordpress/i18n 當成獨立 npm 套件打包進 bundle，bundle 內部的 __()
 * 會走自家的私有 store，永遠讀不到 setLocaleData 寫入 window.wp.i18n 的翻譯資料，導致
 * React 介面全部 fallback 回英文 msgid。
 *
 * 參考：.claude/rules/i18n.rule.md
 */

type WpI18nFn = {
	__: (text: string, domain?: string) => string
	sprintf: (format: string, ...args: unknown[]) => string
}

declare global {
	interface Window {
		wp?: {
			i18n?: WpI18nFn & Record<string, unknown>
		}
	}
}

const fallbackIdentity = (text: string): string => text
const fallbackSprintf = (format: string, ...args: unknown[]): string => {
	// 最小可用 fallback：按位置以 %s 取代
	let i = 0
	return format.replace(/%s/g, () => String(args[i++] ?? ''))
}

export const __ = (text: string, domain?: string): string => {
	const wpI18n = window.wp?.i18n
	return wpI18n ? wpI18n.__(text, domain) : fallbackIdentity(text)
}

export const sprintf = (format: string, ...args: unknown[]): string => {
	const wpI18n = window.wp?.i18n
	return wpI18n ? wpI18n.sprintf(format, ...args) : fallbackSprintf(format, ...args)
}
