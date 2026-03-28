import { renderHTML } from 'antd-toolkit'
import { FC } from 'react'

import { TChapterRecord } from '@/pages/admin/Courses/List/types'

export const ChapterName: FC<{
	record: TChapterRecord
	setSelectedChapter: React.Dispatch<
		React.SetStateAction<TChapterRecord | null>
	>
}> = ({ record, setSelectedChapter }) => {
	const { id, name } = record

	const handleClick = () => {
		setSelectedChapter(record)
	}

	return (
		<>
			<p
				className="text-primary hover:text-primary/70 cursor-pointer !mb-0"
				onClick={handleClick}
			>
				{renderHTML(name)}
			</p>
			<div className="flex ml-2 text-[0.675rem] text-gray-400">
				<span className="pr-3">{`ID: ${id}`}</span>
			</div>
		</>
	)
}
