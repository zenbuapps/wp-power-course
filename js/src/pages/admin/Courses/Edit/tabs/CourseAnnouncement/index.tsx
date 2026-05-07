import {
	useList,
	useDelete,
	useCustomMutation,
	useApiUrl,
} from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import {
	Button,
	Table,
	Space,
	Popconfirm,
	Empty,
	message,
	Typography,
} from 'antd'
import { ColumnsType } from 'antd/es/table'
import dayjs from 'dayjs'
import React, { useContext, useMemo, useState } from 'react'

import { RecordContext } from '@/pages/admin/Courses/Edit/hooks'
import { TCourseRecord } from '@/pages/admin/Courses/List/types'

import { AnnouncementForm } from './AnnouncementForm'
import { StatusTag } from './StatusTag'
import { TAnnouncement } from './types'

const { Text } = Typography

/**
 * 列表頁可見的公告 post_status 集合。
 * 排除 auto-draft / inherit 等系統內部狀態，只保留使用者語意明確的狀態。
 */
const VISIBLE_ANNOUNCEMENT_STATUSES = [
	'publish',
	'future',
	'draft',
	'trash',
] as const

export const CourseAnnouncement = () => {
	const record = useContext(RecordContext) as TCourseRecord | undefined
	const courseId = record?.id ? Number(record.id) : 0

	const [drawerOpen, setDrawerOpen] = useState(false)
	const [editingRecord, setEditingRecord] = useState<TAnnouncement | null>(null)

	const apiUrl = useApiUrl('power-course')
	const { mutate: doDelete } = useDelete()
	const { mutate: doRestore } = useCustomMutation()

	const { data, refetch, isLoading } = useList<TAnnouncement>({
		resource: 'announcements',
		dataProviderName: 'power-course',
		filters: [
			{
				field: 'parent_course_id',
				operator: 'eq',
				value: courseId,
			},
			{
				field: 'post_status',
				operator: 'eq',
				value: VISIBLE_ANNOUNCEMENT_STATUSES.join(','),
			},
		],
		queryOptions: {
			enabled: courseId > 0,
		},
		pagination: {
			mode: 'off',
		},
	})

	const announcements = useMemo<TAnnouncement[]>(() => {
		const list = data?.data
		if (Array.isArray(list)) {
			return list as TAnnouncement[]
		}
		return []
	}, [data])

	const handleAdd = () => {
		setEditingRecord(null)
		setDrawerOpen(true)
	}

	const handleEdit = (target: TAnnouncement) => {
		setEditingRecord(target)
		setDrawerOpen(true)
	}

	const handleDelete = (target: TAnnouncement, force = false) => {
		doDelete(
			{
				resource: 'announcements',
				id: target.id,
				dataProviderName: 'power-course',
				meta: { force },
				successNotification: false,
			},
			{
				onSuccess: () => {
					message.success(
						force
							? __('Announcement permanently deleted', 'power-course')
							: __('Announcement moved to trash', 'power-course')
					)
					refetch()
				},
				onError: (err) => {
					message.error(
						err?.message || __('Failed to delete announcement', 'power-course')
					)
				},
			}
		)
	}

	const handleRestore = (target: TAnnouncement) => {
		doRestore(
			{
				url: `${apiUrl}/announcements/${target.id}/restore`,
				method: 'post',
				values: {},
				successNotification: false,
			},
			{
				onSuccess: () => {
					message.success(__('Announcement restored', 'power-course'))
					refetch()
				},
				onError: (err) => {
					message.error(
						err?.message || __('Failed to restore announcement', 'power-course')
					)
				},
			}
		)
	}

	const columns: ColumnsType<TAnnouncement> = [
		{
			title: __('Status', 'power-course'),
			dataIndex: 'status_label',
			width: 120,
			render: (_status, row) => {
				if (row.post_status === 'trash') {
					return <StatusTag status="expired" />
				}
				// 草稿狀態獨立渲染，避免被後端推導出的 status_label 覆蓋
				if (row.post_status === 'draft') {
					return <StatusTag status="draft" />
				}
				return <StatusTag status={row.status_label} />
			},
		},
		{
			title: __('Title', 'power-course'),
			dataIndex: 'post_title',
			render: (title: string) => <Text strong>{title}</Text>,
		},
		{
			title: __('Visibility', 'power-course'),
			dataIndex: 'visibility',
			width: 140,
			render: (visibility: string) =>
				visibility === 'enrolled'
					? __('Enrolled students only', 'power-course')
					: __('Public (everyone)', 'power-course'),
		},
		{
			title: __('Publish start time', 'power-course'),
			dataIndex: 'post_date',
			width: 180,
			render: (post_date: string) =>
				post_date ? dayjs(post_date).format('YYYY-MM-DD HH:mm') : '—',
		},
		{
			title: __('Publish end time', 'power-course'),
			dataIndex: 'end_at',
			width: 180,
			render: (end_at: TAnnouncement['end_at']) =>
				typeof end_at === 'number' && end_at > 0
					? dayjs.unix(end_at).format('YYYY-MM-DD HH:mm')
					: '—',
		},
		{
			title: __('Actions', 'power-course'),
			width: 220,
			fixed: 'right',
			render: (_text, row) => {
				const isTrashed = row.post_status === 'trash'
				return (
					<Space>
						{!isTrashed && (
							<Button size="small" onClick={() => handleEdit(row)}>
								{__('Edit', 'power-course')}
							</Button>
						)}
						{isTrashed ? (
							<>
								<Button
									size="small"
									type="primary"
									ghost
									onClick={() => handleRestore(row)}
								>
									{__('Restore', 'power-course')}
								</Button>
								<Popconfirm
									title={__(
										'Permanently delete this announcement?',
										'power-course'
									)}
									okText={__('Confirm delete', 'power-course')}
									cancelText={__('Cancel', 'power-course')}
									onConfirm={() => handleDelete(row, true)}
								>
									<Button size="small" danger>
										{__('Delete permanently', 'power-course')}
									</Button>
								</Popconfirm>
							</>
						) : (
							<Popconfirm
								title={__('Move this announcement to trash?', 'power-course')}
								okText={__('Confirm delete', 'power-course')}
								cancelText={__('Cancel', 'power-course')}
								onConfirm={() => handleDelete(row, false)}
							>
								<Button size="small" danger>
									{__('Delete', 'power-course')}
								</Button>
							</Popconfirm>
						)}
					</Space>
				)
			},
		},
	]

	if (!courseId) {
		return (
			<div className="p-6">
				<Empty
					description={__(
						'Please save the course before adding announcements',
						'power-course'
					)}
				/>
			</div>
		)
	}

	return (
		<div className="p-6">
			<div className="flex items-center justify-between mb-4">
				<div>
					<Text className="text-base text-gray-500">
						{__(
							'Show timely announcements above the course tabs on the sales page.',
							'power-course'
						)}
					</Text>
				</div>
				<Button type="primary" onClick={handleAdd}>
					{__('Add announcement', 'power-course')}
				</Button>
			</div>

			<Table<TAnnouncement>
				rowKey="id"
				size="middle"
				loading={isLoading}
				dataSource={announcements}
				columns={columns}
				locale={{
					emptyText: (
						<Empty
							description={__(
								'No announcements yet. Click "Add announcement" to create one.',
								'power-course'
							)}
						/>
					),
				}}
				pagination={false}
				scroll={{ x: 1100 }}
			/>

			<AnnouncementForm
				open={drawerOpen}
				onClose={() => setDrawerOpen(false)}
				courseId={courseId}
				record={editingRecord}
				onSaved={() => refetch()}
			/>
		</div>
	)
}
