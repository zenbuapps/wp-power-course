import dayjs, { Dayjs } from 'dayjs'

/**
 * 格式化日期範圍選擇器的值
 *
 * @param {unknown} values                - 要格式化的值
 * @param {string}  [format='YYYY-MM-DD'] - 日期格式
 * @param {Array}   [fallback=[]]         - 回退值
 * @return {Array} 格式化後的日期陣列或回退值
 */
export function formatRangePickerValue(
	values: unknown,
	format = 'YYYY-MM-DD',
	fallback = []
) {
	if (!Array.isArray(values)) {
		return fallback
	}

	if (values.length !== 2) {
		return fallback
	}

	if (!values.every((value) => value instanceof dayjs)) {
		return fallback
	}

	return (values as [Dayjs, Dayjs]).map((value) => value.format(format))
}

/**
 * 解析日期範圍選擇器的值
 *
 * Issue #203：graceful 處理 [0, 0] / [null, null] / [undefined, undefined] / 含空字串 元素，
 * 避免 dayjs(0) 解讀為 1970-01-01。單側為 falsy 時僅該側回 undefined，另一側保留原值。
 *
 * @param {unknown} values - 要解析的值
 * @return {(Array<Dayjs | undefined>)} 格式化後的日期陣列或未定義
 */
export function parseRangePickerValue(values: unknown) {
	if (!Array.isArray(values)) {
		return [undefined, undefined]
	}

	if (values.length !== 2) {
		return [undefined, undefined]
	}

	// Issue #203: [0, 0] / [null, null] / [undefined, undefined] / ['', ''] 一律視為空
	const isFalsyElement = (v: unknown): boolean =>
		v === 0 || v === null || v === undefined || v === ''
	if (values.every(isFalsyElement)) {
		return [undefined, undefined]
	}

	if (values.every((value) => value instanceof dayjs)) {
		return values
	}

	if (
		values.every((value) => typeof value === 'number' || isFalsyElement(value))
	) {
		// 單側為 falsy 時，該側回 undefined；另一側依長度正確轉 Dayjs
		return values.map((value) => {
			if (isFalsyElement(value)) {
				return undefined
			}
			const numValue = value as number
			if (numValue.toString().length === 13) {
				return dayjs(numValue)
			}
			if (numValue.toString().length === 10) {
				return dayjs(numValue * 1000)
			}
			return undefined
		})
	}
	return [undefined, undefined]
}

/**
 * 格式化日期選擇器的值
 *
 * @param {unknown} value                 - 要格式化的值
 * @param {string}  [format='YYYY-MM-DD'] - 日期格式
 * @param {string}  [fallback='']         - 回退值
 * @return {string} 格式化後的日期或回退值
 */
export function formatDatePickerValue(
	value: unknown,
	format = 'YYYY-MM-DD',
	fallback = ''
) {
	if (!(value instanceof dayjs)) {
		return fallback
	}

	return (value as Dayjs).format(format)
}

/**
 * 解析日期選擇器的值
 *
 * Issue #222：避免 AntD DatePicker 顯示「Invalid date」字樣導致使用者必須按 ✕ 才能輸入。
 *
 * 設計準則（對齊 Issue #222 澄清 Q3-A / Q4-C）：
 * - 對 falsy（`null` / `undefined` / `''` / `0` / `'0'` / `NaN`）一律回 `undefined`
 *   讓 AntD DatePicker 視為「未設值」並顯示 placeholder
 * - 對 dayjs 物件補 `.isValid()` 守衛，無效 dayjs 回 `undefined`
 * - 對純秒/毫秒級數字字串（`'1735689600'` / `'1735689600000'`）轉 number 後走 number 分支
 * - fallback `dayjs(value)` 必須包 `.isValid()` 守衛，無效時回 `undefined`
 *   絕不可再有「直接 `dayjs(value)` 回 Invalid Date dayjs 物件」的路徑
 *
 * @param {unknown} value - 要解析的值
 * @return {(Dayjs | undefined)} 格式化後的日期或未定義
 */
export function parseDatePickerValue(value: unknown): Dayjs | undefined {
	try {
		// Issue #222: falsy 一律視為「未設定」
		// 注意：必須先處理 falsy，避免 dayjs(null) / dayjs(0) 走到 fallback 回 Invalid Date
		if (
			value === null
			|| value === undefined
			|| value === ''
			|| value === 0
			|| value === '0'
		) {
			return undefined
		}

		// dayjs 物件：補 isValid 守衛，避免把 Invalid dayjs 直接回給 AntD DatePicker
		if (value instanceof dayjs) {
			const d = value as Dayjs
			return d.isValid() ? d : undefined
		}

		// number 分支：保留 length 10/13 判斷，補 Number.isFinite 守衛
		if (typeof value === 'number') {
			if (!Number.isFinite(value)) {
				return undefined
			}
			if (value.toString().length === 13) {
				const d = dayjs(value)
				return d.isValid() ? d : undefined
			}
			if (value.toString().length === 10) {
				const d = dayjs(value * 1000)
				return d.isValid() ? d : undefined
			}
			return undefined
		}

		// 字串數字（10 / 13 位）：轉 number 走上面分支
		// 例：'1735689600' / '1735689600000'
		if (typeof value === 'string' && /^\d{10}$|^\d{13}$/.test(value)) {
			return parseDatePickerValue(Number(value))
		}

		// fallback：嘗試 dayjs 解析（ISO 8601 字串等），無效一律回 undefined
		// @ts-ignore
		const result = dayjs(value)
		return result.isValid() ? result : undefined
	} catch {
		return undefined
	}
}
