import { __ } from '@wordpress/i18n'
import { Table, TableProps, Tag } from 'antd'
import React from 'react'

import {
	ProductName,
	ProductPrice,
	ProductTotalSales,
	ProductCat,
	ProductBoundCourses,
	ProductType,
} from '@/components/product'
import { TProductRecord } from '@/components/product/ProductTable/types'
import { getPostStatus } from '@/utils'

const useColumns = () => {
	const columns: TableProps<TProductRecord>['columns'] = [
		Table.SELECTION_COLUMN,
		Table.EXPAND_COLUMN,
		{
			title: __('Product name', 'power-course'),
			dataIndex: 'name',
			width: 300,
			render: (_, record) => (
				<ProductName<TProductRecord>
					record={record}
					onClick={() => {
						window.open(record?.permalink, '_blank')
					}}
				/>
			),
		},
		{
			title: __('Product type', 'power-course'),
			dataIndex: 'type',
			render: (_, record) => <ProductType record={record} />,
		},
		{
			title: __('Status', 'power-course'),
			dataIndex: 'status',
			width: 80,
			align: 'center',
			render: (_, record) => (
				<Tag color={getPostStatus(record?.status)?.color}>
					{getPostStatus(record?.status)?.label}
				</Tag>
			),
		},
		{
			title: __('Total sales', 'power-course'),
			dataIndex: 'total_sales',
			width: 80,
			align: 'center',
			render: (_, record) => <ProductTotalSales record={record} />,
		},
		{
			title: __('Price', 'power-course'),
			dataIndex: 'price',
			width: 150,
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: __('Bound courses', 'power-course'),
			dataIndex: 'bind_courses_data',
			width: 320,
			render: (_, record) => <ProductBoundCourses record={record} />,
		},
		{
			title: __('Product category / Product tag', 'power-course'),
			dataIndex: 'category_ids',
			render: (_, record) => <ProductCat record={record} />,
		},
	]

	return columns
}

export default useColumns
