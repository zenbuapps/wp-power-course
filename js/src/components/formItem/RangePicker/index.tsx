import { __ } from '@wordpress/i18n'
import { Form, DatePicker, GetProps, FormItemProps } from 'antd'
import { FC } from 'react'

import { parseRangePickerValue } from '@/utils'

const { Item } = Form
type RangePickerProps = GetProps<typeof DatePicker.RangePicker>
const { RangePicker: AntdRangePicker } = DatePicker

// @deprecated 改用 共用組件
export const RangePicker: FC<{
	formItemProps?: FormItemProps
	rangePickerProps?: RangePickerProps
}> = ({ formItemProps, rangePickerProps }) => {
	return (
		<Item
			getValueProps={(values) => ({
				value: parseRangePickerValue(values) as unknown as Record<
					string,
					unknown
				>,
			})}
			{...formItemProps}
		>
			<AntdRangePicker
				className="w-full"
				allowEmpty={[true, true]}
				format="YYYY-MM-DD HH:mm"
				placeholder={[
					__('Start date', 'power-course'),
					__('End date', 'power-course'),
				]}
				showTime
				{...rangePickerProps}
			/>
		</Item>
	)
}
