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
			title: '商品名稱',
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
			title: '商品類型',
			dataIndex: 'type',
			render: (_, record) => <ProductType record={record} />,
		},
		{
			title: '狀態',
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
			title: '總銷量',
			dataIndex: 'total_sales',
			width: 80,
			align: 'center',
			render: (_, record) => <ProductTotalSales record={record} />,
		},
		{
			title: '價格',
			dataIndex: 'price',
			width: 150,
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: '綁定的課程',
			dataIndex: 'bind_courses_data',
			width: 320,
			render: (_, record) => <ProductBoundCourses record={record} />,
		},
		{
			title: '商品分類 / 商品標籤',
			dataIndex: 'category_ids',
			render: (_, record) => <ProductCat record={record} />,
		},
	]

	return columns
}

export default useColumns
