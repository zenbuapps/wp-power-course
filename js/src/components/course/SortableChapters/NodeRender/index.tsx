import { FlattenNode, useSortableTree } from '@ant-design/pro-editor'
import { __, sprintf } from '@wordpress/i18n'
import { Checkbox, CheckboxProps } from 'antd'
import { flatMapDeep } from 'lodash-es'
import { FC } from 'react'

import { ChapterName } from '@/components/course'
import {
	SecondToStr,
	DuplicateButton,
	PopconfirmDelete,
} from '@/components/general'
import { TChapterRecord } from '@/pages/admin/Courses/List/types'
import { getPostStatus } from '@/utils'

const NodeRender: FC<{
	node: FlattenNode<TChapterRecord>
	selectedChapter: TChapterRecord | null
	setSelectedChapter: React.Dispatch<
		React.SetStateAction<TChapterRecord | null>
	>
	selectedIds: string[]
	setSelectedIds: React.Dispatch<React.SetStateAction<string[]>>
}> = ({
	node,
	selectedChapter,
	setSelectedChapter,
	selectedIds,
	setSelectedIds,
}) => {
	const { removeNode } = useSortableTree()
	const record = node.content
	if (!record) {
		return (
			<div>
				{sprintf(
					// translators: %s: 節點 ID
					__('ID: %s chapter data not found', 'power-course'),
					node.id
				)}
			</div>
		)
	}

	const handleDelete = () => {
		removeNode(node.id)
	}

	const getFlattenChildrenIds = (
		_node: FlattenNode<TChapterRecord>
	): string[] => {
		return flatMapDeep([_node], (__node: FlattenNode<TChapterRecord>) => [
			__node?.id as string,
			...__node?.children?.map((child) =>
				getFlattenChildrenIds(child as FlattenNode<TChapterRecord>)
			),
		])
	}

	const handleCheck: CheckboxProps['onChange'] = (e) => {
		const flattenChildrenIds = getFlattenChildrenIds(node)
		if (e.target.checked) {
			setSelectedIds((prev) => [...prev, ...flattenChildrenIds])
		} else {
			setSelectedIds((prev) =>
				prev.filter((id) => !flattenChildrenIds.includes(id))
			)
		}
	}
	const isChecked = selectedIds.includes(node.id as string)
	const isSelectedChapter = selectedChapter?.id === node.id

	const showPlaceholder = node?.children?.length === 0
	return (
		<div
			className={`grid grid-cols-[1fr_3rem_7rem_4rem] gap-4 justify-start items-center ${isSelectedChapter ? 'bg-[#e6f4ff]' : ''}`}
		>
			<div className="flex items-center overflow-hidden">
				{showPlaceholder && <div className="size-[28px]"></div>}
				<Checkbox className="mr-2" onChange={handleCheck} checked={isChecked} />
				<ChapterName record={record} setSelectedChapter={setSelectedChapter} />
			</div>
			<div className="text-xs text-gray-400">
				{getPostStatus(record?.status || '')?.label}
			</div>
			<div>
				<SecondToStr second={record?.chapter_length} />
			</div>

			<div className="flex gap-2">
				<DuplicateButton
					id={record?.id}
					invalidateProps={{
						resource: 'chapters',
					}}
					tooltipProps={{
						title: __('Duplicate chapter/lesson', 'power-course'),
					}}
				/>

				<PopconfirmDelete
					tooltipProps={{ title: __('Delete', 'power-course') }}
					popconfirmProps={{
						description: __(
							'Deleting will remove child lessons as well',
							'power-course'
						),
						onConfirm: handleDelete,
					}}
				/>
			</div>
		</div>
	)
}

export default NodeRender
