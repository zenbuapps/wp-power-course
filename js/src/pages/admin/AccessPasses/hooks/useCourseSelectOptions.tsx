import { useSelect } from '@refinedev/antd'
import { __ } from '@wordpress/i18n'
import { SelectProps } from 'antd'
import { defaultSelectProps } from 'antd-toolkit'

import { TCourseRecord } from '@/pages/admin/Courses/List/types'
import { ellipsisTagRender } from '@/utils'

/**
 * 課程多選 Select 的 Hook（供 specific 範圍使用）
 *
 * 與 `@/hooks/useCourseSelect` 不同：本 hook **不**在內部以 state 控制 value，
 * 而是回傳純 selectProps（options + server 搜尋），交由外層 antd Form.Item 受控，
 * 使表單能正確帶入 initialValues（course_ids）並隨表單提交。
 *
 * @return selectProps 可直接 spread 到 `<Select mode="multiple" {...selectProps} />`
 */
export const useCourseSelectOptions = (): { selectProps: SelectProps } => {
	const { selectProps: refineSelectProps } = useSelect<TCourseRecord>({
		resource: 'courses',
		dataProviderName: 'power-course',
		debounce: 500,
		pagination: {
			pageSize: 20,
			mode: 'server',
		},
		onSearch: (value) => [
			{
				field: 's',
				operator: 'contains',
				value,
			},
		],
	})

	const selectProps: SelectProps = {
		...defaultSelectProps,
		placeholder: __('Search course keyword', 'power-course'),
		...refineSelectProps,
		// 限制每個已選 tag 的最大寬度，避免長課程名稱撐爆欄位（放在所有 spread 之後確保不被覆蓋）
		tagRender: ellipsisTagRender,
	}

	return { selectProps }
}
