import { __, sprintf } from '@wordpress/i18n'
import { Typography, Tag, Tooltip } from 'antd'
import { cn } from 'antd-toolkit'
import dayjs from 'dayjs'
import React, { memo, FC } from 'react'

import { TProductRecord } from '@/components/product/ProductTable/types'
import { TCoursesLimit } from '@/pages/admin/Courses/List/types'

const { Text } = Typography

const getLimitUnitLabel = (unit: string): string => {
	switch (unit) {
		case 'day':
			return __('day', 'power-course')
		case 'month':
			return __('month', 'power-course')
		case 'year':
			return __('year', 'power-course')
		default:
			return ''
	}
}

const getLimitLabel = (
	limit_type: TCoursesLimit['limit_type'],
	limit_value: TCoursesLimit['limit_value'],
	limit_unit: TCoursesLimit['limit_unit']
) => {
	switch (limit_type) {
		case 'unlimited':
			return __('Unlimited', 'power-course')
		case 'follow_subscription':
			return __('Follow subscription', 'power-course')
		case 'fixed':
			return sprintf(
				// translators: 1: 期限值, 2: 期限單位（日/月/年）
				__('%1$s %2$s after order completion', 'power-course'),
				limit_value,
				getLimitUnitLabel(limit_unit as string)
			)
		case 'assigned':
			return sprintf(
				// translators: %s: 到期時間（YYYY/MM/DD HH:mm）
				__('Until %s', 'power-course'),
				dayjs.unix(limit_value as number).format('YYYY/MM/DD HH:mm')
			)
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
							<Tooltip
								title={name || __('Unknown course name', 'power-course')}
							>
								<span className="text-gray-400 text-xs">#{id}</span>
							</Tooltip>
						)}
						{!hideName && (
							<Text
								ellipsis={{
									tooltip: name || __('Unknown course name', 'power-course'),
								}}
							>
								<span className="text-gray-400 text-xs">#{id}</span>{' '}
								{name || __('Unknown course name', 'power-course')}
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
