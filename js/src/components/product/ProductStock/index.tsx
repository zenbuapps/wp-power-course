import {
	FieldTimeOutlined,
	QuestionCircleOutlined,
	WarningOutlined,
	CloseCircleFilled,
	CheckCircleFilled,
} from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
import { Tag } from 'antd'
import { FC } from 'react'

import { TStockStatus } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

type TProductStockProps = {
	record: TCourseRecord
	type?: 'tag' | 'text'
}

export const ProductStock: FC<TProductStockProps> = ({
	record,
	type = 'text',
}) => {
	const { stock_status, stock_quantity, low_stock_amount = 0 } = record
	if (!stock_status) return null
	const { label, color, Icon } = getTagProps(
		stock_status,
		stock_quantity,
		low_stock_amount
	)

	if (type === 'tag') {
		return (
			<Tag bordered={false} className={`m-0 ${color}`} icon={<Icon />}>
				{label}
				{Number.isInteger(stock_quantity) && <> ({stock_quantity})</>}
			</Tag>
		)
	}

	return (
		<p className="m-0 text-gray-400 text-xs">
			<Icon className={`mr-2 ${color}`} />
			{label}
			{Number.isInteger(stock_quantity) && <> ({stock_quantity})</>}
		</p>
	)
}

function getTagProps(
	stock_status: TStockStatus,
	stock_quantity: number | null,
	low_stock_amount: number | null
) {
	switch (stock_status) {
		case 'instock':
			if (stock_quantity === null || low_stock_amount === null) {
				return {
					label: __('In stock', 'power-course'),
					color: 'text-green-500',
					Icon: ({ ...props }) => <CheckCircleFilled {...props} />,
				}
			}
			return stock_quantity > low_stock_amount
				? {
						label: __('Stock sufficient', 'power-course'),
						color: 'text-green-500',
						Icon: ({ ...props }) => <CheckCircleFilled {...props} />,
					}
				: {
						label: __('Low stock', 'power-course'),
						color: 'text-orange-500',
						Icon: ({ ...props }) => <WarningOutlined {...props} />,
					}
		case 'outofstock':
			return {
				label: __('Out of stock', 'power-course'),
				color: 'text-red-500',
				Icon: ({ ...props }) => <CloseCircleFilled {...props} />,
			}
		case 'onbackorder':
			return {
				label: __('On backorder (pre-order)', 'power-course'),
				color: 'text-purple-500',
				Icon: ({ ...props }) => <FieldTimeOutlined {...props} />,
			}
		default:
			return {
				label: __('Stock status unknown', 'power-course'),
				color: 'text-gray-400',
				Icon: ({ ...props }) => <QuestionCircleOutlined {...props} />,
			}
	}
}
