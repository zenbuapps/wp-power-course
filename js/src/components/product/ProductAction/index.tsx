import { ExportOutlined } from '@ant-design/icons'
import { __ } from '@wordpress/i18n'
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
		<div className="flex gap-1 justify-center [&_.ant-btn]:!w-9 [&_.ant-btn]:!h-9 [&_.ant-btn]:!px-2">
			<DuplicateButton
				id={record?.id}
				invalidateProps={{ resource: 'courses' }}
				tooltipProps={{ title: __('Duplicate course', 'power-course') }}
			/>
			<Tooltip
				title={
					record?.classroom_link
						? __('Open course classroom', 'power-course')
						: __(
								'This course has no chapters and cannot enter the classroom',
								'power-course'
							)
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
			<Tooltip title={__('Open course sales page', 'power-course')}>
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
