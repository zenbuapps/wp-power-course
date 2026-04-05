import { Form, InputNumber, FormItemProps } from 'antd'
import React, { memo } from 'react'

import { RangePicker } from '@/components/formItem'

const { Item } = Form

const Simple = ({
	regularPriceItemProps,
	salePriceItemProps,
	saleDateRangeItemProps,
}: {
	regularPriceItemProps?: FormItemProps
	salePriceItemProps?: FormItemProps
	saleDateRangeItemProps?: FormItemProps
}) => {
	return (
		<>
			<Item name={['regular_price']} label="原價" {...regularPriceItemProps}>
				<InputNumber
					addonBefore="NT$"
					className="w-full [&_input]:text-right [&_.ant-input-number]:bg-white [&_.ant-input-number-group-addon]:bg-[#fafafa]  [&_.ant-input-number-group-addon]:text-[#1f1f1f]"
					min={0}
					controls={false}
				/>
			</Item>
			<Item name={['sale_price']} label="折扣價" {...salePriceItemProps}>
				<InputNumber
					addonBefore="NT$"
					className="w-full [&_input]:text-right"
					min={0}
					controls={false}
				/>
			</Item>

			<RangePicker
				formItemProps={{
					name: ['sale_date_range'],
					label: '折扣期間',
					...saleDateRangeItemProps,
				}}
			/>
		</>
	)
}

export default memo(Simple)
