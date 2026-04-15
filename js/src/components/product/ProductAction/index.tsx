import { ExportOutlined } from '@ant-design/icons'
import { Tooltip, Button } from 'antd'
import { FC } from 'react'
import { SiGoogleclassroom } from 'react-icons/si'

import { DuplicateButton } from '@/components/general'
import { useEnv } from '@/hooks'
import { TCourseBaseRecord } from '@/pages/admin/Courses/List/types'

import ToggleVisibility from './ToggleVisibility'

export const ProductAction: FC<{
	record: TCourseBaseRecord
}> = ({ record }) => {
	const { SITE_URL, COURSE_PERMALINK_STRUCTURE } = useEnv()
	return (
		<div className="flex gap-1">
			<DuplicateButton
				id={record?.id}
				invalidateProps={{ resource: 'courses' }}
				tooltipProps={{ title: '複製課程' }}
			/>
			<Tooltip
				title={
					record?.classroom_link
						? '開啟課程教室'
						: '此課程還沒有章節，無法前往教室'
				}
			>
				<Button
					type="text"
					icon={
						<SiGoogleclassroom className="relative top-0.5 text-gray-400" />
					}
					href={record?.classroom_link}
					target="_blank"
					rel="noreferrer"
					disabled={!record?.classroom_link}
				/>
			</Tooltip>
			<Tooltip title="開啟課程銷售頁">
				<Button
					type="text"
					href={`${SITE_URL}/${COURSE_PERMALINK_STRUCTURE}/${record?.slug}`}
					target="_blank"
					rel="noreferrer"
					icon={<ExportOutlined className="text-gray-400" />}
				/>
			</Tooltip>
			<ToggleVisibility record={record} />
		</div>
	)
}
