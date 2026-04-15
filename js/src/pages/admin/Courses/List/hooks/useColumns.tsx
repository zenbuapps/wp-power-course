import { useNavigation } from '@refinedev/core'
import { Table, TableProps, Tag } from 'antd'
import { DateTime } from 'antd-toolkit'
import React from 'react'

import { SecondToStr } from '@/components/general'
import {
	ProductName,
	ProductPrice,
	ProductTotalSales,
	ProductCat,
	ProductAction,
} from '@/components/product'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'
import { getPostStatus } from '@/utils'

const useColumns = () => {
	const { edit } = useNavigation()
	const onClick = (record: TCourseBaseRecord) => () => {
		edit('courses', record.id)
	}
	const columns: TableProps<TCourseBaseRecord>['columns'] = [
		Table.SELECTION_COLUMN,
		{
			title: '商品名稱',
			dataIndex: 'name',
			width: 300,
			key: 'name',
			render: (_, record) => (
				<ProductName<TCourseBaseRecord>
					record={record}
					onClick={onClick(record)}
				/>
			),
		},
		{
			title: '狀態',
			dataIndex: 'status',
			width: 80,
			key: 'status',
			render: (_, record) => (
				<Tag color={getPostStatus(record?.status)?.color}>
					{getPostStatus(record?.status)?.label}
				</Tag>
			),
		},
		{
			title: '總銷量',
			dataIndex: 'total_sales',
			width: 150,
			key: 'total_sales',
			render: (_, record) => (
				<ProductTotalSales
					record={record}
					optionParams={{
						endpoint: 'courses/options',
					}}
				/>
			),
		},
		{
			title: '價格',
			dataIndex: 'price',
			width: 150,
			key: 'price',
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: '開課時間',
			dataIndex: 'course_schedule',
			width: 180,
			key: 'type',
			render: (course_schedule: number) =>
				course_schedule ? (
					<DateTime
						date={course_schedule * 1000}
						timeProps={{
							format: 'HH:mm',
						}}
					/>
				) : (
					'-'
				),
		},
		{
			title: '時數',
			dataIndex: 'course_length',
			width: 180,
			key: 'course_length',
			render: (course_length) => <SecondToStr second={course_length} />,
		},
		{
			title: '商品分類 / 商品標籤',
			dataIndex: 'category_ids',
			key: 'category_ids',
			render: (_, record) => <ProductCat record={record} />,
		},
		{
			title: '操作',
			dataIndex: '_actions',
			key: '_actions',
			render: (_, record) => <ProductAction record={record} />,
		},
	]

	return columns
}

export default useColumns
