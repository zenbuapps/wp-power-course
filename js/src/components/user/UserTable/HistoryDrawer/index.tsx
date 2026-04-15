import { LoadingOutlined, UserOutlined } from '@ant-design/icons'
import { useList } from '@refinedev/core'
import { Drawer, Timeline, Empty, Pagination, PaginationProps } from 'antd'
import { useAtom } from 'jotai'
import React, { useState, memo } from 'react'
import { __ } from '@wordpress/i18n'

import { historyDrawerAtom } from '../atom'

import { TimelineItemAdapter } from './adapter'
import { TimelineLogType, TStudentLog } from './types'

export { defaultHistoryDrawerProps } from './types'

const loadingItems = new Array(8).fill(null).map((_, index) => ({
	dot: <LoadingOutlined />,
	children: (
		<>
			<div
				className={
					'h-[1.5rem] w-[20rem] mr-4 mb-1 bg-gray-100 rounded-[0.25rem] animate-pulse'
				}
			/>
			<div
				className={
					'h-[1rem] w-28 mr-4 mb-1 bg-gray-100 rounded-[0.25rem] animate-pulse'
				}
			/>
		</>
	),
}))

const HistoryDrawer = () => {
	const [historyDrawerProps, setHistoryDrawerProps] = useAtom(historyDrawerAtom)
	const { user_id, course_id, drawerProps, user_name, course_name } =
		historyDrawerProps

	const [pagination, setPagination] = useState<PaginationProps>({
		current: 1,
		pageSize: 20,
	})

	const { data, isFetching } = useList<TStudentLog>({
		resource: 'courses/student-logs',
		dataProviderName: 'power-course',
		filters: [
			{
				field: 'user_id',
				operator: 'eq',
				value: user_id,
			},
			{
				field: 'course_id',
				operator: 'eq',
				value: course_id,
			},
		],
		pagination: {
			pageSize: pagination.pageSize,
			current: pagination.current,
		},
		queryOptions: {
			enabled: !!user_id && !!course_id,
		},
	})

	const logs = data?.data || []
	const total = data?.total || 1

	const items = logs.map(({ log_type, title, created_at }) => {
		return new TimelineItemAdapter(
			log_type as TimelineLogType,
			title,
			created_at
		).itemProps
	})

	return (
		<Drawer
			width={560}
			title={
				<>
					<p className="mt-0 mb-1">
						{__('Learning history', 'power-course')} - {course_name}{' '}
						<span className="text-gray-400 text-xs">#{course_id}</span>
					</p>
					<p className="my-0 text-sm text-gray-400">
						<UserOutlined className="mr-2" /> {user_name}{' '}
						<span className="text-gray-400 text-xs">#{user_id}</span>
					</p>
				</>
			}
			onClose={() =>
				setHistoryDrawerProps((prev) => ({
					...prev,
					drawerProps: {
						...prev.drawerProps,
						open: false,
					},
				}))
			}
			{...drawerProps}
		>
			{isFetching && <Timeline items={loadingItems} />}
			{!isFetching && items.length === 0 && (
				<Empty
					className="mt-[10rem]"
					description={__('No records currently', 'power-course')}
				/>
			)}
			{!isFetching && items.length !== 0 && (
				<>
					<Timeline items={items} />
					<Pagination
						{...pagination}
						total={total}
						align="center"
						showSizeChanger
						showTitle
						hideOnSinglePage
						onChange={(page, pageSize) => {
							setPagination({
								current: page,
								pageSize,
							})
						}}
					/>
				</>
			)}
		</Drawer>
	)
}

export default memo(HistoryDrawer)
