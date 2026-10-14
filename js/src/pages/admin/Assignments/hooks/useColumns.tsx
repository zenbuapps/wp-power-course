/**
 * 作業批改列表的欄位定義（Issue #131 P7）
 *
 * 欄位對齊 specs/ui/作業批改頁.md「列表欄位」：
 * 學員姓名與 Email、課程、作業、繳交時間、批改狀態、附件大類圖示、討論未讀徽章。
 * 排序一律由後端 `submitted_at DESC, id DESC` 決定，不提供 client-side 排序。
 */
import { __ } from '@wordpress/i18n'
import { Badge, Button, Tag, type TableProps } from 'antd'
import { useMemo } from 'react'

import { type TSubmissionRecord } from '../types'
import {
	getAttachmentKindIcon,
	getAttachmentKindLabel,
	getStatusColor,
	getStatusLabel,
} from '../utils'

/**
 * 取得批改列表欄位
 *
 * @param onSelect 點擊「查看詳情」時的回呼
 */
const useColumns = (
	onSelect: (record: TSubmissionRecord) => void
): TableProps<TSubmissionRecord>['columns'] =>
	useMemo(
		() => [
			{
				title: __('Student', 'power-course'),
				dataIndex: 'customer_name',
				render: (_value: string | null, record: TSubmissionRecord) => (
					<div>
						<div>{record.customer_name}</div>
						<div className="text-xs text-gray-400">{record.customer_email}</div>
					</div>
				),
			},
			{
				title: __('Course', 'power-course'),
				dataIndex: 'course_title',
			},
			{
				title: __('Assignment', 'power-course'),
				dataIndex: 'assignment_title',
			},
			{
				title: __('Submitted at', 'power-course'),
				dataIndex: 'submitted_at',
			},
			{
				title: __('Status', 'power-course'),
				dataIndex: 'status',
				render: (_value: string, record: TSubmissionRecord) => (
					<Tag color={getStatusColor(record.status)}>
						{getStatusLabel(record.status)}
					</Tag>
				),
			},
			{
				title: __('Attachments', 'power-course'),
				dataIndex: 'attachments',
				render: (_value: unknown, record: TSubmissionRecord) => {
					// 同一大類只顯示一個圖示，避免一次繳交 10 張圖把欄位撐爆
					const kinds = Array.from(
						new Set(record.attachments.map((attachment) => attachment.kind))
					)

					if (!kinds.length) {
						return <span className="text-gray-300">-</span>
					}

					return (
						<span className="flex gap-1">
							{kinds.map((kind) => (
								<span
									key={kind}
									className="pc-attachment-kind-icon"
									title={getAttachmentKindLabel(kind)}
								>
									{getAttachmentKindIcon(kind)}
								</span>
							))}
						</span>
					)
				},
			},
			{
				title: __('Discussion', 'power-course'),
				dataIndex: 'unread_count',
				render: (_value: number, record: TSubmissionRecord) => (
					<Badge
						className="pc-assignment-discuss-badge"
						count={record.unread_count}
						showZero
						color={record.unread_count > 0 ? undefined : '#d9d9d9'}
					/>
				),
			},
			{
				title: __('Details', 'power-course'),
				dataIndex: 'id',
				render: (_value: number, record: TSubmissionRecord) => (
					<Button size="small" onClick={() => onSelect(record)}>
						{__('View details', 'power-course')}
					</Button>
				),
			},
		],
		[onSelect]
	)

export default useColumns
