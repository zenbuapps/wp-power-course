import type { Locale as TAntdLocale } from 'antd/es/locale'
import antdEnUS from 'antd/locale/en_US'
import antdJaJP from 'antd/locale/ja_JP'
import antdZhTW from 'antd/locale/zh_TW'
import { en_US, ja_JP, zh_TW, type TLocale } from 'antd-toolkit'

/**
 * WordPress locale 字串 → antd-toolkit TLocale 物件的對照表。
 *
 * 注意：WordPress 與 antd-toolkit 的 locale key 命名不完全一致，
 * WP 的日文 locale 是 `ja`（無 `_JP` 後綴），需在此顯式對應到 antd-toolkit 的 `ja_JP`，
 * 無法單純以同名字串比對。
 */
const WP_LOCALE_TO_ANTD_TOOLKIT: Record<string, TLocale> = {
	zh_TW,
	en_US,
	ja: ja_JP,
}

/**
 * 將 WordPress locale 字串映射為 antd-toolkit 的 TLocale 物件，
 * 供 antd-toolkit LocaleProvider 決定內部元件（SecondToStr、WatchStatusTag、
 * VideoInput、各 modal 文案等）的顯示語言。
 * 找不到對應時 fallback 為 zh_TW（專案主要介面語言）。
 *
 * @param wpLocale WordPress locale 字串（如 'zh_TW' / 'en_US' / 'ja'）
 * @return 對應的 antd-toolkit TLocale 物件
 */
export const getAntdToolkitLocale = (wpLocale: string): TLocale =>
	WP_LOCALE_TO_ANTD_TOOLKIT[wpLocale] ?? zh_TW

/**
 * WordPress locale 字串 → antd 原生 Locale 物件的對照表。
 *
 * 與 WP_LOCALE_TO_ANTD_TOOLKIT 是兩套獨立系統：
 * antd-toolkit 的 TLocale 管 toolkit 自家元件文案，
 * 此表管 antd 內建元件文案（DatePicker 月份名、Table 空狀態、
 * Pagination「每頁筆數」、Popconfirm 預設按鈕等）。
 * 兩者的 key 命名同樣有 WP `ja` vs antd `ja_JP` 的落差。
 */
const WP_LOCALE_TO_ANTD: Record<string, TAntdLocale> = {
	zh_TW: antdZhTW,
	en_US: antdEnUS,
	ja: antdJaJP,
}

/**
 * 將 WordPress locale 字串映射為 antd 原生 Locale 物件，
 * 供 antd ConfigProvider 決定內建元件的顯示語言。
 * 找不到對應時 fallback 為 zh_TW（專案主要介面語言）。
 *
 * @param wpLocale WordPress locale 字串（如 'zh_TW' / 'en_US' / 'ja'）
 * @return 對應的 antd Locale 物件
 */
export const getAntdLocale = (wpLocale: string): TAntdLocale =>
	WP_LOCALE_TO_ANTD[wpLocale] ?? antdZhTW
