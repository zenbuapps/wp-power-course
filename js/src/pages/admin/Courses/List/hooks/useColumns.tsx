import { useNavigation } from '@refinedev/core'
import { useWindowSize } from '@uidotdev/usehooks'
import { Table, TableProps, Tag } from 'antd'
import { DateTime } from 'antd-toolkit'
import {
	ProductName,
	ProductPrice,
	ProductTotalSales,
	ProductCat,
	ProductType,
	ProductStock,
	POST_STATUS,
	isVariation,
} from 'antd-toolkit/wp'

import { SecondToStr } from '@/components/general'
import { ProductAction } from '@/components/product'
import useOptions from '@/components/product/ProductTable/hooks/useOptions'
import { TTerm } from '@/components/product/ProductTable/types'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'

/**
 * 課程列表欄位定義 Hook
 * 比照 Power Shop 的 ProductTable 欄位顯示方式，使用 antd-toolkit/wp 組件
 */
export const useColumns = () => {
	const { width } = useWindowSize()
	const { edit } = useNavigation()
	const { options } = useOptions({ endpoint: 'courses/options' })
	const { top_sales_products = [] } = options
	const max_sales = top_sales_products?.[0]?.total_sales || 0

	/**
	 * 將 {id, name} 格式的 TTerm 轉換為 antd-toolkit/wp 期望的 {value, label} 格式
	 */
	const mapTerms = (terms: TTerm[]) =>
		terms.map(({ id, name }) => ({ value: id, label: name }))

	const columns: TableProps<TCourseBaseRecord>['columns'] = [
		Table.SELECTION_COLUMN,
		{
			title: '商品名稱',
			dataIndex: 'name',
			width: 300,
			fixed: (width || 400) > 768 ? 'left' : undefined,
			render: (_, record) => (
				<ProductName<TCourseBaseRecord>
					record={record}
					onClick={() => edit('courses', record?.id)}
				/>
			),
		},
		{
			title: '商品類型',
			dataIndex: 'type',
			width: 180,
			render: (_, record) => <ProductType record={record} />,
		},
		{
			title: '狀態',
			dataIndex: 'status',
			width: 80,
			align: 'center',
			render: (_, record) => {
				const status = POST_STATUS.find((item) => item.value === record?.status)
				return <Tag color={status?.color}>{status?.label}</Tag>
			},
		},
		{
			title: '總銷量',
			dataIndex: 'total_sales',
			width: 80,
			align: 'center',
			render: (_, record) => (
				<ProductTotalSales record={record} max_sales={max_sales} />
			),
		},
		{
			title: '價格',
			dataIndex: 'price',
			width: 150,
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: '庫存',
			dataIndex: 'stock',
			width: 150,
			align: 'center',
			render: (_, record) => (
				<ProductStock<TCourseBaseRecord> record={record} />
			),
		},
		{
			title: '開課時間',
			dataIndex: 'course_schedule',
			width: 180,
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
			render: (course_length) => <SecondToStr second={course_length} />,
		},
		{
			title: '商品分類 / 商品標籤',
			dataIndex: 'category_ids',
			width: 220,
			render: (_, { categories = [], tags = [] }) => (
				<ProductCat categories={mapTerms(categories)} tags={mapTerms(tags)} />
			),
		},
		{
			title: '操作',
			dataIndex: '_actions',
			align: 'center',
			width: 100,
			render: (_, record) =>
				!isVariation(record?.type) && <ProductAction record={record} />,
		},
	]

	return columns
}

export default useColumns
