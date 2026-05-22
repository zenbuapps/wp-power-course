import { CrudFilters } from '@refinedev/core'
import { __ } from '@wordpress/i18n'

import { TStudentFilterValues } from '../Filter'

/**
 * 將 Filter Form values 轉成 Refine useTable filters
 *
 * Issue #227 — 課程學員 Tab Filter：
 * - search 欄位（單一輸入框，後端走 default search_field 多欄位匹配）
 * - progress_operator / progress_value 雙欄位（後端 SQL HAVING 過濾）
 *
 * Refine `onSearch` 的官方型別簽名為 `(data: unknown) => CrudFilters | Promise<CrudFilters>`，
 * 此處以 unknown 接住再窄化到 TStudentFilterValues。
 */
export const onSearch = (data: unknown): CrudFilters => {
	const values = (data || {}) as TStudentFilterValues
	const filters: CrudFilters = []

	if (values?.search) {
		filters.push({ field: 'search', operator: 'eq', value: values.search })
	}

	// progress 兩欄位必須同時有值才送出
	const hasOp = !!values?.progress_operator
	const hasValue =
		values?.progress_value !== undefined &&
		values?.progress_value !== null &&
		(values?.progress_value as unknown as string) !== ''

	if (hasOp && hasValue) {
		filters.push({
			field: 'progress_operator',
			operator: 'eq',
			value: values.progress_operator,
		})
		filters.push({
			field: 'progress_value',
			operator: 'eq',
			value: values.progress_value,
		})
	}

	return filters
}

/**
 * FilterTags 顯示用：將欄位 key 轉成人類可讀標籤
 */
export const keyLabelMapper = (key: string | number | symbol): string => {
	switch (key) {
		case 'search':
			return __('Keyword search', 'power-course')
		case 'progress_operator':
			return __('Operator', 'power-course')
		case 'progress_value':
			return __('Progress', 'power-course')
		default:
			return key as string
	}
}
