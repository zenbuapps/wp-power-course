/**
 * 批改狀態篩選（Issue #131 P7）
 *
 * 全部 / 待批改 / 通過 / 未通過，只影響列表範圍，不影響統計卡
 * （統計卡的範圍由課程分頁決定，`features/assignment/查詢作業繳交列表.feature`）。
 */
import { _x, __ } from '@wordpress/i18n'
import { Radio, type RadioChangeEvent } from 'antd'
import { memo } from 'react'

import {
	STATUS_ALL,
	SUBMISSION_STATUS,
	type TStatusFilter,
	type TStatusFilterProps,
} from '../types'

/**
 * 狀態篩選元件
 */
const StatusFilterComponent = ({ value, onChange }: TStatusFilterProps) => {
	const options = [
		{ value: STATUS_ALL, label: __('All', 'power-course') },
		{
			value: SUBMISSION_STATUS.PENDING,
			label: _x('Pending', 'assignment submission status', 'power-course'),
		},
		{
			value: SUBMISSION_STATUS.PASS,
			label: _x('Passed', 'assignment submission status', 'power-course'),
		},
		{
			value: SUBMISSION_STATUS.FAIL,
			label: _x('Failed', 'assignment submission status', 'power-course'),
		},
	]

	return (
		<div className="pc-assignment-status-filter">
			<Radio.Group
				value={value}
				onChange={(event: RadioChangeEvent) =>
					onChange(event.target.value as TStatusFilter)
				}
			>
				{options.map((option) => (
					<Radio.Button key={option.value} value={option.value}>
						{option.label}
					</Radio.Button>
				))}
			</Radio.Group>
		</div>
	)
}

export const StatusFilter = memo(StatusFilterComponent)
