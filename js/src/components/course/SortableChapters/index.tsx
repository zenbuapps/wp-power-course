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
export const MAX_DEPTH = 2

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
	const invalidate = useInvalidate()

	const apiUrl = useApiUrl('power-course')
	const { mutate, isLoading: isSorting } = useCustomMutation()

	// 每次更新 List 狀態，會算出當次的展開節點 id
	const openedNodeIds = getOpenedNodeIds(treeData)

	useEffect(() => {
		if (!isListFetching) {
			const chapterTree = chapters?.map(chapterToTreeNode)

			setTreeData((prev) => {
				// 恢復原本的 collapsed 狀態
				const newChapterTree = restoreOriginCollapsedState(
					chapterTree,
					openedNodeIds
				)

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
			content: '排序儲存中...',
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
						content: '排序儲存成功',
						key: 'chapter-sorting',
					})
				},
				onError: () => {
					message.loading({
						content: '排序儲存失敗',
						key: 'chapter-sorting',
					})
				},
				onSettled: () => {
					invalidate({
						resource: 'chapters',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
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
					清空選取
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
						children: `批次刪除 ${selectedIds.length ? `(${selectedIds.length})` : ''}`,
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
							if (!sortable) message.error('超過最大深度，無法執行')
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
