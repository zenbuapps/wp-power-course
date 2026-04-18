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
 * @param {unknown} value - 要解析的值
 * @return {(Dayjs | undefined)} 格式化後的日期或未定義
 */
export function parseDatePickerValue(value: unknown) {
	try {
		if (value instanceof dayjs) {
			return value
		}

		if (typeof value === 'number') {
			if (value.toString().length === 13) {
				return dayjs(value)
			}
			if (value.toString().length === 10) {
				return dayjs(value * 1000)
			}
			return undefined
		}

		// @ts-ignore
		return dayjs(value)
	} catch {
		return undefined
	}
}
