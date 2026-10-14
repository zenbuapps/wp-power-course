import { PlusOutlined } from '@ant-design/icons'
import { useParsed } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Button, Typography } from 'antd'
import { memo, useState } from 'react'

import { AssignmentFormModal } from './AssignmentFormModal'
import { AssignmentList } from './AssignmentList'
import { useChapterAssignments } from './hooks'
import { TAssignment } from './types'

const { Text } = Typography

type TChapterAssignmentsProps = {
	/** 章節 ID */
	chapterId: number
}

/** 表單 Modal 的開啟狀態：assignment 為 null 代表新增，否則為編輯該筆作業 */
type TEditingState = {
	assignment: TAssignment | null
}

/**
 * 章節作業管理區塊
 *
 * 嵌在章節編輯區，提供該章節作業的新增 / 編輯 / 刪除 / 拖拉排序。
 * 對應 specs/ui/章節作業管理.md。
 */
const ChapterAssignmentsComponent = ({
	chapterId,
}: TChapterAssignmentsProps) => {
	// 原封不動沿用 useParsed().id，與 SortableChapters 共用 chapters 的 query cache
	// （轉成 number 會讓 react-query 的 queryKey 不一致，多打一次 API）
	const { id: courseId } = useParsed()

	const { assignments, isLoading, refetch } = useChapterAssignments(chapterId)
	const [editing, setEditing] = useState<TEditingState | null>(null)

	return (
		<div className="pc-chapter-assignments mt-8">
			<div className="flex items-end justify-between mb-3 gap-x-4">
				<div className="flex flex-col gap-y-1">
					<span className="font-bold">
						{__('Assignment management', 'power-course')}
					</span>
					<Text type="secondary" className="text-xs">
						{__('Hold the handle and drag to reorder', 'power-course')}
					</Text>
				</div>
				<Button
					type="primary"
					icon={<PlusOutlined />}
					onClick={() => setEditing({ assignment: null })}
				>
					{__('Add assignment', 'power-course')}
				</Button>
			</div>

			<AssignmentList
				assignments={assignments}
				chapterId={chapterId}
				isLoading={isLoading}
				onEdit={(assignment) => setEditing({ assignment })}
				onChanged={() => {
					refetch()
				}}
			/>

			{!!editing && (
				<AssignmentFormModal
					assignment={editing.assignment}
					chapterId={chapterId}
					courseId={courseId}
					onClose={() => setEditing(null)}
					onSaved={() => {
						setEditing(null)
						refetch()
					}}
				/>
			)}
		</div>
	)
}

export const ChapterAssignments = memo(ChapterAssignmentsComponent)
