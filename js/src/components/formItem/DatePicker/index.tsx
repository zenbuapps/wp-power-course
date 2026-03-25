import {
	Form,
	DatePicker as AntdDatePicker,
	DatePickerProps,
	FormItemProps,
} from 'antd'
import dayjs from 'dayjs'
import { FC } from 'react'

import { parseDatePickerValue } from '@/utils'

const { Item } = Form

export const DatePicker: FC<{
	formItemProps?: FormItemProps
	datePickerProps?: DatePickerProps
}> = ({ formItemProps, datePickerProps }) => {
	return (
		<Item
			getValueProps={(value) => ({
				value: parseDatePickerValue(value) as unknown as Record<
					string,
					unknown
				>,
			})}
			normalize={(value) => value?.unix()}
			{...formItemProps}
		>
			<AntdDatePicker
				placeholder="選擇日期"
				className="w-full"
				showTime={{ defaultValue: dayjs() }}
				format="YYYY-MM-DD HH:mm"
				{...datePickerProps}
			/>
		</Item>
	)
}
