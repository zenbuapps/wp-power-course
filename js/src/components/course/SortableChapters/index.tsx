import { SortableTree, TreeData } from '@ant-design/pro-editor'
import {
	useCustomMutation,
	useApiUrl,
	useInvalidate,
	useList,
	HttpError,
	useDeleteMany,
	useParsed,
} from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { Form, message, Button } from 'antd'
import { cn } from 'antd-toolkit'
import { isEqual as _isEqual } from 'lodash-es'
import { useState, useEffect, memo } from 'react'

import { ChapterEdit } from '@/components/chapters'
import { PopconfirmDelete } from '@/components/general'
import { TChapterRecord } from '@/pages/admin/Courses/List/types'

import AddChapters from './AddChapters'
import NodeRender from './NodeRender'
import { chapterToTreeNode, treeToParams } from './utils'

// 定義最大深度
export const MAX_DEPTH = 5

const LoadingChapters = () => (
	<div className="pl-3">
		{new Array(10).fill(0).map((_, index) => (
			<div
				key={index}
				className=" bg-gray-100 h-7 rounded-sm mb-1 animate-pulse"
			/>
		))}
	</div>
)

const SortableChaptersComponent = () => {
	const form = Form.useFormInstance()
	const { id: courseId } = useParsed()
	const {
		data: chaptersData,
		isFetching: isListFetching,
		isLoading: isListLoading,
	} = useList<TChapterRecord, HttpError>({
		resource: 'chapters',
		dataProviderName: 'power-course',
		filters: [
			{
				field: 'meta_key',
				operator: 'eq',
				value: 'parent_course_id',
			},
			{
				field: 'meta_value',
				operator: 'eq',
				value: courseId,
			},
		],
		pagination: {
			current: 1,
			pageSize: -1,
		},
	})

	const chapters = chaptersData?.data || []

	const [treeData, setTreeData] = useState<TreeData<TChapterRecord>>([])
	const [originTree, setOriginTree] = useState<TreeData<TChapterRecord>>([])
	// 【Issue #216 Bug #1a / #1c】SortableTree 的 key 版本號，
	// 每次排序成功 / 失敗後遞增以強制 unmount/remount，
	// 讓 @ant-design/pro-editor 的內部 state 從最新 React state 重新初始化，
	// 避免下次拖曳用 stale 的 from_tree 計算 diff 失效。
	const [treeVersion, setTreeVersion] = useState(0)
	const invalidate = useInvalidate()

	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading: isSorting } = useCustomMutation()

	// 每次更新 List 狀態，會算出當次的展開節點 id
	const openedNodeIds = getOpenedNodeIds(treeData)

	useEffect(() => {
		if (!isListFetching) {
			const chapterTree = chapters?.map(chapterToTreeNode)

			// 【Issue #216 Q5】編輯子章節儲存後，自動把父章節加入展開列表，
			// 給予使用者「子章節仍在原位」的視覺確認。
			// 若使用者剛剛展開父章節 → collapse → 點到子章節 → 編輯 → 儲存，
			// 此補強會把父章節重新展開，避免子章節「視覺上消失」。
			const extraOpenedIds: string[] = []
			const selectedParentId = selectedChapter?.parent_id
			if (
				selectedParentId &&
				String(selectedParentId) !== String(courseId) &&
				String(selectedParentId) !== '0'
			) {
				extraOpenedIds.push(String(selectedParentId))
			}

			setTreeData((prev) => {
				// 恢復原本的 collapsed 狀態（含編輯子章節後自動展開的父章節）
				const newChapterTree = restoreOriginCollapsedState(chapterTree, [
					...openedNodeIds,
					...extraOpenedIds,
				])

				return newChapterTree
			})
			setOriginTree(chapterTree)

			// 每次重新排序後，重新取得章節後，重新 set 選擇的章節
			const flattenChapters = chapters.reduce((acc, c) => {
				acc.push(c)
				if (c?.chapters) {
					acc.push(...c?.chapters)
				}
				return acc
			}, [] as TChapterRecord[])

			setSelectedChapter(
				flattenChapters.find((c) => c.id === selectedChapter?.id) || null
			)
		}
	}, [isListFetching])

	const handleSave = (data: TreeData<TChapterRecord>) => {
		const from_tree = treeToParams(originTree, courseId)
		const to_tree = treeToParams(data, courseId)
		const isEqual = _isEqual(from_tree, to_tree)
		if (isEqual) return

		// 這個儲存只存新增，不存章節的細部資料
		message.loading({
			content: __('Saving sort order...', 'power-course'),
			key: 'chapter-sorting',
		})

		mutate(
			{
				url: `${apiUrl}/chapters/sort`,
				method: 'post',
				values: {
					from_tree,
					to_tree,
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __('Sort order saved successfully', 'power-course'),
						key: 'chapter-sorting',
					})
				},
				onError: () => {
					// 【Issue #216 Q4】排序失敗時顯示紅色錯誤通知，
					// 並把章節順序還原為拖曳前的 originTree，避免使用者誤以為已成功
					message.error({
						content: __('Failed to save sort order', 'power-course'),
						key: 'chapter-sorting',
					})
					setTreeData(originTree)
				},
				onSettled: () => {
					invalidate({
						resource: 'chapters',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
					// 【Issue #216 Bug #1a / #1c】強制 SortableTree unmount/remount，
					// 讓 @ant-design/pro-editor 的內部 state 從最新 React state 重新初始化。
					// 無論成功或失敗都遞增，避免 library 內部殘留拖曳中的暫態。
					setTreeVersion((v) => v + 1)
				},
			}
		)
	}

	const [selectedChapter, setSelectedChapter] = useState<TChapterRecord | null>(
		null
	)

	const [selectedIds, setSelectedIds] = useState<string[]>([]) // 批次刪除選中的 ids

	const { mutate: deleteMany, isLoading: isDeleteManyLoading } = useDeleteMany()

	return (
		<>
			<div className="mb-8 flex gap-x-4 justify-between items-center">
				<AddChapters records={chapters} />
				<Button
					type="default"
					className="relative top-1"
					disabled={!selectedIds.length}
					onClick={() => setSelectedIds([])}
				>
					{__('Clear selection', 'power-course')}
				</Button>
				<PopconfirmDelete
					popconfirmProps={{
						onConfirm: () =>
							deleteMany(
								{
									resource: 'chapters',
									dataProviderName: 'power-course',
									ids: selectedIds,
									mutationMode: 'optimistic',
								},
								{
									onSuccess: () => {
										setSelectedIds([])
									},
								}
							),
					}}
					buttonProps={{
						type: 'primary',
						danger: true,
						className: 'relative top-1',
						loading: isDeleteManyLoading,
						disabled: !selectedIds.length,
						children: selectedIds.length
							? sprintf(
									// translators: %s: 選取的章節數量
									__('Batch delete (%s)', 'power-course'),
									selectedIds.length
								)
							: __('Batch delete', 'power-course'),
					}}
				/>
			</div>
			<div
				className={cn(
					'grid grid-cols-1 xl:grid-cols-2 gap-6',
					isSorting || isListFetching ? 'pointer-events-none' : ''
				)}
			>
				{isListLoading && <LoadingChapters />}
				{!isListLoading && (
					<SortableTree
						// 【Issue #216 Bug #1a / #1c】key 變動時 React 強制 remount，
						// 讓 @ant-design/pro-editor 從最新 React state 重新初始化內部 state
						key={treeVersion}
						hideAdd
						hideRemove
						treeData={treeData}
						onTreeDataChange={(data: TreeData<TChapterRecord>) => {
							setTreeData(data)
							handleSave(data)
						}}
						renderContent={(node) => (
							<NodeRender
								node={node}
								selectedChapter={selectedChapter}
								setSelectedChapter={setSelectedChapter}
								selectedIds={selectedIds}
								setSelectedIds={setSelectedIds}
							/>
						)}
						indentationWidth={48}
						sortableRule={({ activeNode, projected }) => {
							const nodeDepth = getMaxDepth([activeNode])
							const maxDepth = projected?.depth + nodeDepth

							// activeNode - 被拖動的節點
							// projected - 拖動後的資訊

							const sortable = maxDepth <= MAX_DEPTH
							if (!sortable)
								message.error(
									__('Exceeded max depth, operation failed', 'power-course')
								)
							return sortable
						}}
					/>
				)}

				{selectedChapter && <ChapterEdit record={selectedChapter} />}
			</div>
		</>
	)
}

export const SortableChapters = memo(SortableChaptersComponent)

/**
 * 取得所有展開的 ids
 * 遞迴取得所有 collapsed = false 的 id
 * @param treeData 樹狀結構
 * @return 所有 collapsed = false 的 id
 */
function getOpenedNodeIds(treeData: TreeData<TChapterRecord>) {
	// 遞迴取得所有 collapsed = false 的 id
	const ids = treeData?.reduce((acc, c) => {
		if (!c.collapsed) acc.push(c.id as string)
		if (c?.children?.length) acc.push(...getOpenedNodeIds(c.children))
		return acc
	}, [] as string[])
	return ids
}

/**
 * 恢復原本的 collapsed 狀態
 * @param treeData      樹狀結構
 * @param openedNodeIds 展開的 ids
 * @return newTreeData 恢復原本的 collapsed 狀態
 */
function restoreOriginCollapsedState(
	treeData: TreeData<TChapterRecord>,
	openedNodeIds: string[]
) {
	// 遞迴恢復原本的 collapsed 狀態
	const newTreeData: TreeData<TChapterRecord> = treeData?.map((item) => {
		const newItem = item
		if (openedNodeIds.includes(item.id as string)) {
			newItem.collapsed = false
		}

		if (item?.children?.length) {
			newItem.children = restoreOriginCollapsedState(
				item.children,
				openedNodeIds
			)
		}
		return item
	})
	return newTreeData
}

/**
 * 取得樹狀結構的最大深度
 * @param treeData 樹狀結構
 * @param depth    當前深度
 * @return 最大深度
 */
function getMaxDepth(treeData: TreeData<TChapterRecord>, depth = 0) {
	// 如果沒有資料，回傳當前深度
	if (!treeData?.length) return depth

	// 遞迴取得所有子節點的深度
	const childrenDepths: number[] = treeData.map((item) => {
		if (item?.children?.length) {
			return getMaxDepth(item.children, depth + 1)
		}
		return depth
	})

	// 回傳最大深度
	return Math.max(...childrenDepths)
}
