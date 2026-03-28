import { Typography, Tag, Tooltip } from 'antd'
import { cn } from 'antd-toolkit'
import dayjs from 'dayjs'
import React, { memo, FC } from 'react'

import { TProductRecord } from '@/components/product/ProductTable/types'
import { TCoursesLimit } from '@/pages/admin/Courses/List/types'

const { Text } = Typography

const LIMIT_UNIT_LABEL = {
	day: '日',
	month: '月',
	year: '年',
}

const getLimitLabel = (
	limit_type: TCoursesLimit['limit_type'],
	limit_value: TCoursesLimit['limit_value'],
	limit_unit: TCoursesLimit['limit_unit']
) => {
	switch (limit_type) {
		case 'unlimited':
			return '無期限'
		case 'follow_subscription':
			return '跟隨訂閱'
		case 'fixed':
			return `訂單完成後 ${limit_value} ${LIMIT_UNIT_LABEL?.[limit_unit as keyof typeof LIMIT_UNIT_LABEL] || ''}`
		case 'assigned':
			return `至 ${dayjs.unix(limit_value as number).format('YYYY/MM/DD HH:mm')}`
	}
}

const ProductBoundCoursesComponent: FC<{
	record: TProductRecord
	className?: string
	hideName?: boolean
}> = ({ record, className, hideName = false }) => {
	const { bind_courses_data = [] } = record
	return bind_courses_data.map(
		({ id, name, limit_type, limit_value, limit_unit }) => {
			return (
				<div
					key={id}
					className={cn('grid grid-cols-[12rem_8rem] gap-1 my-1', className)}
				>
					<div>
						{hideName && (
							<Tooltip title={name || '未知的課程名稱'}>
								<span className="text-gray-400 text-xs">#{id}</span>
							</Tooltip>
						)}
						{!hideName && (
							<Text
								ellipsis={{
									tooltip: name || '未知的課程名稱',
								}}
							>
								<span className="text-gray-400 text-xs">#{id}</span>{' '}
								{name || '未知的課程名稱'}
							</Text>
						)}
					</div>

					<div>
						<Tag>{getLimitLabel(limit_type, limit_value, limit_unit)}</Tag>
					</div>
				</div>
			)
		}
	)
}

export const ProductBoundCourses = memo(ProductBoundCoursesComponent)
