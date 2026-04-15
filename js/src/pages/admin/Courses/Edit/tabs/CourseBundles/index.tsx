import { SortableList, SortableListRef } from '@ant-design/pro-editor'
import { RenderItem } from '@ant-design/pro-editor/es/SortableList/type'
import {
	useList,
	useCreate,
	useParsed,
	useOne,
	useCustomMutation,
	useApiUrl,
} from '@refinedev/core'
import { Button, Empty, message } from 'antd'
import { toFormData } from 'antd-toolkit'
import React, { useRef, memo, useState } from 'react'

import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

import { EditBundle } from './Edit'
import ListItem from './ListItem'

export type TRenderItemOptions = Parameters<RenderItem<TBundleProductRecord>>[1]

const LoadingItems = ({ className }: { className?: string }) => (
	<div>
		{new Array(4).fill(null).map((_, index) => (
			<div
				key={index}
				className={`h-[2.5rem] ml-8 mr-4 mb-1 bg-gray-100 rounded-[0.25rem] animate-pulse ${className}`}
			/>
		))}
	</div>
)

const CourseBundlesComponent = () => {
	// 取得課程 record
	const { id: courseId } = useParsed()
	const { data: courseData } = useOne<TCourseRecord>({
		dataProviderName: 'power-course',
		resource: 'courses',
		id: courseId,
		meta: {
			// 為了避免重複 query，所以加入 meta 與列表的 queryKey 一樣
			label: '課程列表',
			id: courseId,
		},
	})
	const course = courseData?.data

	// 取得銷售方案列表
	const { data, isLoading } = useList<TBundleProductRecord>({
		dataProviderName: 'power-course',
		resource: 'bundle_products',
		filters: [
			{
				field: 'meta_key',
				operator: 'eq',
				value: 'link_course_ids',
			},
			{
				field: 'meta_value',
				operator: 'eq',
				value: courseId,
			},
			{
				field: 'type',
				operator: 'eq',
				value: ['simple', 'power_bundle_product', 'subscription'],
			},
		],
		sorters: [
			{
				field: 'menu_order',
				order: 'asc',
			},
			{
				field: 'date',
				order: 'desc',
			},
		],
		pagination: {
			pageSize: -1,
		},
		queryOptions: {
			enabled: !!courseId,
			staleTime: 0,
			cacheTime: 0,
		},
	})
	const bundleProducts = data?.data || []

	const ref = useRef<SortableListRef>(null)

	// 創建銷售方案
	const { mutate: create, isLoading: isCreating } = useCreate()
	const handleCreate = () => {
		const values = {
			status: 'publish',
			bundle_type: 'bundle',
			name: '銷售方案',
			link_course_ids: [courseId],
			pbp_product_ids: [courseId], // 預設加入當前課程
			pbp_product_quantities: JSON.stringify({ [String(courseId)]: 1 }), // 數量 1
		}

		const formData = toFormData(values)

		create({
			dataProviderName: 'power-course',
			resource: 'bundle_products',
			values: formData,
			invalidates: ['list'],
		})
	}

	// 選中的商品
	const [selectedProduct, setSelectedProduct] =
		useState<TBundleProductRecord | null>(null)

	// 批次變更，排序用
	const { mutate: sort } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')

	return (
		<>
			<div className="gap-6 p-6">
				<div className="mb-8">
					<Button type="primary" onClick={handleCreate} loading={isCreating}>
						新增
					</Button>
				</div>
				<div className="grid grid-cols-1 xl:grid-cols-[3fr_2fr] gap-6">
					{isLoading && <LoadingItems />}
					{!isLoading && (
						<SortableList<TBundleProductRecord>
							value={bundleProducts}
							ref={ref}
							onChange={(newList) => {
								const sort_list = newList.map(({ id }, index) => ({
									id,
									menu_order: index,
								}))

								sort(
									{
										url: `${apiUrl}/bundle_products/sort`,
										method: 'post',
										values: {
											sort_list,
										},
									},
									{
										onSuccess: () => {
											message.success({
												content: '排序儲存成功',
												key: 'bundle-sorting',
											})
										},
									}
								)
							}}
							getItemStyles={() => ({
								padding: '0.5rem 1rem',
							})}
							renderEmpty={() => <Empty description="目前沒有銷售方案" />}
							renderContent={(item: TBundleProductRecord, index: number) => (
								<ListItem
									record={item}
									index={index}
									setSelectedProduct={setSelectedProduct}
									selectedProduct={selectedProduct}
								/>
							)}
							hideRemove
						/>
					)}
					{selectedProduct && course && (
						<EditBundle record={selectedProduct} course={course} />
					)}
				</div>
			</div>
		</>
	)
}

export const CourseBundles = memo(CourseBundlesComponent)
