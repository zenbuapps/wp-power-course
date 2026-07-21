import { useNavigation } from '@refinedev/core'
import { useWindowSize } from '@uidotdev/usehooks'
import { __, _x } from '@wordpress/i18n'
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
			title: __('Product name', 'power-course'),
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
			title: __('Product type', 'power-course'),
			dataIndex: 'type',
			width: 180,
			// Issue #237: TCourseBaseRecord.virtual 改為 'yes' | 'no' 字串，
			// antd-toolkit 的 ProductType 期望 boolean，傳入前先 normalize。
			render: (_, record) => (
				<ProductType
					record={{ ...record, virtual: record.virtual === 'yes' }}
				/>
			),
		},
		{
			title: __('Status', 'power-course'),
			dataIndex: 'status',
			width: 80,
			align: 'center',
			render: (_, record) => {
				// Issue #256：排程課程（future）special-case——不依賴 POST_STATUS 是否含 future，
				// 以橘色 Tag 顯示「排程中」與「已發佈」的藍色區隔；
				// 預計上架時間改由「課程開始時間」欄位呈現，此欄只留狀態本身
				if (record?.status === 'future') {
					return (
						<Tag color="orange">
							{_x('Scheduled', 'post status', 'power-course')}
						</Tag>
					)
				}
				const status = POST_STATUS.find((item) => item.value === record?.status)
				return <Tag color={status?.color}>{status?.label}</Tag>
			},
		},
		{
			title: __('Total sales', 'power-course'),
			dataIndex: 'total_sales',
			width: 80,
			align: 'center',
			render: (_, record) => (
				<ProductTotalSales record={record} max_sales={max_sales} />
			),
		},
		{
			title: __('Price', 'power-course'),
			dataIndex: 'price',
			width: 150,
			render: (_, record) => <ProductPrice record={record} />,
		},
		{
			title: __('Stock', 'power-course'),
			dataIndex: 'stock',
			width: 150,
			align: 'center',
			render: (_, record) => (
				<ProductStock<TCourseBaseRecord> record={record} />
			),
		},
		{
			title: __('Course start time', 'power-course'),
			dataIndex: 'course_schedule',
			width: 180,
			render: (course_schedule: number, record) => {
				if (course_schedule) {
					return (
						<DateTime
							date={course_schedule * 1000}
							timeProps={{
								format: 'HH:mm',
							}}
						/>
					)
				}

				// Issue #256：排程課程尚未設定開課時間時，改顯示預計上架時間，
				// 加註標籤與開課時間區隔語意（date_publish 為站台本地時間字串）
				if (record?.status === 'future' && record?.date_publish) {
					return (
						<div className="flex flex-col gap-1">
							<span className="text-xs text-gray-500">
								{_x(
									'Scheduled publish',
									'course start time column',
									'power-course'
								)}
							</span>
							<DateTime
								date={new Date(record.date_publish.replace(' ', 'T')).getTime()}
								timeProps={{
									format: 'HH:mm',
								}}
							/>
						</div>
					)
				}

				return '-'
			},
		},
		{
			title: __('Duration', 'power-course'),
			dataIndex: 'course_length',
			width: 180,
			render: (course_length) => <SecondToStr second={course_length} />,
		},
		{
			title: __('Product categories / Tags', 'power-course'),
			dataIndex: 'category_ids',
			width: 220,
			render: (_, { categories = [], tags = [] }) => (
				<ProductCat categories={mapTerms(categories)} tags={mapTerms(tags)} />
			),
		},
		{
			title: __('Actions', 'power-course'),
			dataIndex: '_actions',
			align: 'center',
			width: 180,
			fixed: 'right',
			render: (_, record) =>
				!isVariation(record?.type) && <ProductAction record={record} />,
		},
	]

	return columns
}

export default useColumns
