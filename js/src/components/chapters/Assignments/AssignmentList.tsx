import { EditOutlined } from '@ant-design/icons'
import { SortableTree, TreeData } from '@ant-design/pro-editor'
import { __, _x, sprintf } from '@wordpress/i18n'
import { Button, Empty, Skeleton, Tag, Tooltip, Typography } from 'antd'
import dayjs from 'dayjs'
import { memo, useEffect, useState } from 'react'

import { PopconfirmDelete } from '@/components/general'

import { useAssignmentMutation } from './hooks'
import { TAssignment, YES_NO } from './types'

const { Text } = Typography

/** 作業截止時間顯示格式（站台時區） */
const DUE_AT_FORMAT = 'YYYY-MM-DD HH:mm'

/** 一秒的毫秒數（10 位 timestamp 轉 dayjs 用） */
const MS_PER_SECOND = 1000

/** 拖曳縮排寬度；作業是單層清單，實際巢狀由 sortableRule 擋掉（0 會讓套件內部除以 0） */
const INDENTATION_WIDTH = 24

type TAssignmentListProps = {
	/** 作業列表（後端已排序） */
	assignments: TAssignment[]
	/** 所屬章節 ID */
	chapterId: number
	/** 是否正在載入列表 */
	isLoading: boolean
	/** 點擊編輯時的回呼 */
	onEdit: (_assignment: TAssignment) => void
	/** 刪除 / 排序成功後重新取得列表 */
	onChanged: () => void
}

/**
 * 作業轉成 SortableTree 的節點（作業不支援巢狀，children 恆為空陣列）
 *
 * @param assignment 作業
 */
const toTreeNode = (assignment: TAssignment) => ({
	id: String(assignment.id),
	children: [],
	collapsed: false,
	content: assignment,
})

/**
 * 單一作業的題型摘要標籤
 * 依允許的繳交方式顯示「文字 / 檔案 / 必填 / 附件上限」
 */
const SubmissionTypeTags = ({ assignment }: { assignment: TAssignment }) => {
	const { allow_text, required_text, allow_file, required_file, max_files } =
		assignment

	return (
		<>
			{YES_NO.YES === allow_text && (
				<Tag color="blue" className="m-0">
					{YES_NO.YES === required_text
						? _x(
								'Text (required)',
								'assignment submission type',
								'power-course'
							)
						: _x('Text', 'assignment submission type', 'power-course')}
				</Tag>
			)}
			{YES_NO.YES === allow_file && (
				<>
					<Tag color="green" className="m-0">
						{YES_NO.YES === required_file
							? _x(
									'File (required)',
									'assignment submission type',
									'power-course'
								)
							: _x('File', 'assignment submission type', 'power-course')}
					</Tag>
					<Tag className="m-0">
						{sprintf(
							// translators: %s: 附件數量上限
							__('Max %s files', 'power-course'),
							max_files
						)}
					</Tag>
				</>
			)}
		</>
	)
}

/**
 * 章節作業列表
 *
 * 以 @ant-design/pro-editor 的 SortableTree 呈現（與章節排序同一套函式庫），
 * 拖曳結束後呼叫 POST assignments/sort 寫入新的 menu_order。
 *
 * 不用同套件的 SortableList：它會把 dnd-kit 的 `role="button"` 掛在每一列的外層，
 * 該外層的 accessible name 會把列內所有按鈕名稱吃進去（「編輯作業」「刪除作業」），
 * 讓 getByRole('button', { name }) 同時命中外層與真正的按鈕。
 */
const AssignmentListComponent = ({
	assignments,
	chapterId,
	isLoading,
	onEdit,
	onChanged,
}: TAssignmentListProps) => {
	const { deleteAssignment, sortAssignments, isMutating } =
		useAssignmentMutation()

	const [treeData, setTreeData] = useState<TreeData<TAssignment>>([])
	// 排序成功 / 失敗後遞增，強制 SortableTree unmount/remount，
	// 讓套件內部 state 從最新的 React state 重新初始化（比照 SortableChapters）
	const [treeVersion, setTreeVersion] = useState(0)

	useEffect(() => {
		setTreeData(assignments.map(toTreeNode))
	}, [assignments])

	/**
	 * 拖曳結束：只有「順序真的變了」才送排序 API
	 *
	 * @param data 拖曳後的樹狀資料
	 */
	const handleTreeDataChange = (data: TreeData<TAssignment>) => {
		setTreeData(data)

		// sortableRule 已擋掉巢狀，這裡仍防禦性攤平，避免子節點被漏掉
		const nextIds = data.flatMap((node) => [
			Number(node.id),
			...(node?.children ?? []).map((child) => Number(child.id)),
		])
		const currentIds = assignments.map(({ id }) => id)
		if (nextIds.join(',') === currentIds.join(',')) {
			return
		}

		sortAssignments(chapterId, nextIds, () => {
			onChanged()
			setTreeVersion((version) => version + 1)
		})
	}

	if (isLoading) {
		return <Skeleton active paragraph={{ rows: 2 }} />
	}

	if (!assignments.length) {
		return (
			<Empty
				image={Empty.PRESENTED_IMAGE_SIMPLE}
				description={__('No assignments yet', 'power-course')}
			/>
		)
	}

	return (
		<div
			className={`pc-assignment-sortable ${
				isMutating ? 'pointer-events-none opacity-60' : ''
			}`}
		>
			{/* 比照 SortableChapters：讓拖曳 handle 常駐顯示，提升可發現性 */}
			<style>{`
				.pc-assignment-sortable .ant-editor-sortable-tree-node-handle {
					opacity: 1;
				}
			`}</style>
			<SortableTree<TAssignment>
				key={treeVersion}
				hideAdd
				hideRemove
				indentationWidth={INDENTATION_WIDTH}
				treeData={treeData}
				onTreeDataChange={handleTreeDataChange}
				sortableRule={({ projected }) => 0 === projected?.depth}
				renderContent={(node) => {
					const assignment = node?.content
					if (!assignment) {
						return null
					}

					return (
						<div className="pc-assignment-list-item flex items-center justify-between gap-x-4 py-1">
							<div className="flex flex-col gap-y-1 overflow-hidden">
								<div>
									<span className="font-medium">{assignment.title}</span>
								</div>
								<div className="flex items-center gap-2 flex-wrap">
									<SubmissionTypeTags assignment={assignment} />
									<Text type="secondary" className="text-xs">
										{assignment.due_at > 0
											? sprintf(
													// translators: %s: 截止時間
													__('Due: %s', 'power-course'),
													dayjs(assignment.due_at * MS_PER_SECOND).format(
														DUE_AT_FORMAT
													)
												)
											: __('No due date', 'power-course')}
									</Text>
								</div>
							</div>
							<div className="flex items-center gap-x-1 shrink-0">
								<Tooltip title={__('Edit assignment', 'power-course')}>
									<Button
										type="text"
										size="small"
										aria-label={__('Edit assignment', 'power-course')}
										icon={<EditOutlined />}
										onClick={() => onEdit(assignment)}
									/>
								</Tooltip>
								<PopconfirmDelete
									tooltipProps={{
										title: __('Delete assignment', 'power-course'),
									}}
									popconfirmProps={{
										title: __('Delete this assignment?', 'power-course'),
										description: __(
											'The assignment will be moved to trash. Student submissions will be kept.',
											'power-course'
										),
										onConfirm: () => deleteAssignment(assignment.id, onChanged),
									}}
									buttonProps={{
										size: 'small',
										'aria-label': __('Delete assignment', 'power-course'),
									}}
								/>
							</div>
						</div>
					)
				}}
			/>
		</div>
	)
}

export const AssignmentList = memo(AssignmentListComponent)
