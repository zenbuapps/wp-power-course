import {
	useDelete,
	useCustomMutation,
	useApiUrl,
	useInvalidate,
} from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Tag, Tooltip, Typography, message } from 'antd'
import { ProductName } from 'antd-toolkit/wp'
import dayjs from 'dayjs'
import React, { memo } from 'react'

import { DuplicateButton, PopconfirmDelete } from '@/components/general'
import { ProductPrice, ProductTotalSales } from '@/components/product'
import { TBundleProductRecord } from '@/components/product/ProductTable/types'
import { getPostStatus, productTypes } from '@/utils'

const { Text } = Typography

const ListItem = ({
	record,
	index,
	setSelectedProduct,
	selectedProduct,
}: {
	record: TBundleProductRecord
	index: number
	setSelectedProduct: React.Dispatch<
		React.SetStateAction<TBundleProductRecord | null>
	>
	selectedProduct: TBundleProductRecord | null
}) => {
	const {
		id,
		name,
		status,
		type,
		bundle_schedule_online,
		bundle_schedule_offline,
		bundle_schedule_online_done_at,
		bundle_schedule_offline_done_at,
	} = record
	const tag = productTypes.find((productType) => productType.value === type)
	const { mutate: deleteProduct } = useDelete()

	const { bind_courses_data = [] } = record
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	// Issue #249: 移除單筆綁定課程。重用既有 products/unbind-courses 端點
	// （同時清 bind_course_ids 與 bind_courses_data），不做樂觀更新，失敗保留原狀。
	const { mutate: unbindCourse, isLoading: isUnbinding } = useCustomMutation()

	const handleUnbindCourse = (courseId: string) => () => {
		unbindCourse(
			{
				url: `${apiUrl}/products/unbind-courses`,
				method: 'post',
				values: {
					product_ids: [id],
					course_ids: [courseId],
				},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Course removed from bundle', 'power-course'),
						key: 'unbind-bundle-course',
					})
					invalidate({
						resource: 'bundle_products',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to remove course from bundle', 'power-course'),
						key: 'unbind-bundle-course',
					})
				},
			}
		)
	}

	// 排程狀態 Tag：已執行優先於未到點，下線優先於上線
	const formatScheduleTime = (ts: number) =>
		dayjs.unix(ts).format('YYYY-MM-DD HH:mm')

	const getScheduleTag = () => {
		if (bundle_schedule_offline_done_at) {
			return (
				<Tag bordered={false} color="default" className="m-0">
					{sprintf(
						// translators: %s: 自動下線時間
						__('Automatically went offline at %s', 'power-course'),
						formatScheduleTime(bundle_schedule_offline_done_at)
					)}
				</Tag>
			)
		}
		if (bundle_schedule_online_done_at) {
			return (
				<Tag bordered={false} color="success" className="m-0">
					{sprintf(
						// translators: %s: 自動上線時間
						__('Automatically went online at %s', 'power-course'),
						formatScheduleTime(bundle_schedule_online_done_at)
					)}
				</Tag>
			)
		}
		if (bundle_schedule_offline) {
			return (
				<Tag bordered={false} color="orange" className="m-0">
					{sprintf(
						// translators: %s: 預計自動下線時間
						__('Scheduled to go offline at %s', 'power-course'),
						formatScheduleTime(bundle_schedule_offline)
					)}
				</Tag>
			)
		}
		if (bundle_schedule_online) {
			return (
				<Tag bordered={false} color="blue" className="m-0">
					{sprintf(
						// translators: %s: 預計自動上線時間
						__('Scheduled to go online at %s', 'power-course'),
						formatScheduleTime(bundle_schedule_online)
					)}
				</Tag>
			)
		}
		return null
	}

	const scheduleTag = getScheduleTag()

	return (
		<div
			className="grid gap-x-2 w-full pl-2"
			style={{
				// 此元件獨有的 arbitrary 值改為 inline，避免依賴 Powerhouse tailwind build
				// 掃描後才生成 css（power-course 本身不出 css）。標準工具類仍走 className。
				gridTemplateColumns: 'minmax(0,1fr) 10rem 4rem 3rem 2rem 6rem 4rem',
				borderRadius: '0.25rem',
				backgroundColor:
					id === selectedProduct?.id ? '#e6f4ff' : 'rgba(0,0,0,0.02)',
			}}
		>
			{/* <div className="self-center">
				<HolderOutlined
					className="cursor-grab hover:bg-gray-200 rounded-lg py-3 px-0.5"
					{...listeners}
				/>
			</div> */}

			<div className="self-center min-w-0">
				<ProductName
					record={record as any}
					onClick={() => setSelectedProduct(record)}
					hideImage={false}
					renderTitle={
						// 以 Typography.Text 自渲染標題，限制單行 + ellipsis，hover 顯示完整名稱
						// 取代 antd-toolkit 內建標題（其 truncate 僅作用於子元素，純文字節點無效）
						<Text
							className="text-primary text-base cursor-pointer"
							ellipsis={{
								tooltip: (
									<>
										{name} <span className="text-gray-400 text-xs">#{id}</span>
									</>
								),
							}}
						>
							{name}{' '}
							<span className="text-gray-400 text-xs font-light">#{id}</span>
						</Text>
					}
				/>
				{scheduleTag && <div className="mt-1">{scheduleTag}</div>}
			</div>

			<div className="self-center justify-self-end">
				{/*
					Issue #249: 唯讀的 ProductBoundCourses 為多處共用元件，不改成可互動。
					改在 CourseBundles 的 ListItem 容器層為每筆綁定課程加「移除」入口。
				*/}
				{bind_courses_data.map(
					({ id: boundCourseId, name: boundCourseName }) => (
						<div
							key={boundCourseId}
							className="flex items-center justify-end gap-1 my-1"
						>
							<Tooltip
								title={
									boundCourseName || __('Unknown course name', 'power-course')
								}
							>
								<span className="text-gray-400 text-xs">#{boundCourseId}</span>
							</Tooltip>
							<PopconfirmDelete
								type="icon"
								tooltipProps={{
									title: __('Remove this course from bundle', 'power-course'),
								}}
								popconfirmProps={{
									title: __(
										'Are you sure you want to remove this course from the bundle?',
										'power-course'
									),
									okButtonProps: { loading: isUnbinding },
									onConfirm: handleUnbindCourse(boundCourseId),
								}}
							/>
						</div>
					)
				)}
			</div>

			<div className="self-center">
				<Tag bordered={false} color={tag?.color} className="m-0">
					{tag?.label}
				</Tag>
			</div>

			<div className="self-center">
				<Tag color={getPostStatus(status)?.color}>
					{getPostStatus(status)?.label}
				</Tag>
			</div>

			<div className="self-center place-self-center">
				<ProductTotalSales record={record} />
			</div>

			<div className="self-center whitespace-normal">
				<ProductPrice record={record} />
			</div>

			<div className="self-center flex gap-x-2">
				<DuplicateButton
					id={id}
					invalidateProps={{ resource: 'bundle_products' }}
					tooltipProps={{ title: __('Duplicate bundle', 'power-course') }}
				/>
				<PopconfirmDelete
					type="icon"
					tooltipProps={{ title: __('Delete', 'power-course') }}
					popconfirmProps={{
						title: __(
							'Are you sure you want to delete this bundle?',
							'power-course'
						),
						onConfirm: () =>
							deleteProduct({
								dataProviderName: 'power-course',
								resource: 'bundle_products',
								id,
								mutationMode: 'optimistic',
							}),
					}}
				/>
			</div>
		</div>
	)
}

export default memo(ListItem)
