/**
 * 課程分頁（Issue #131 P7）
 *
 * 「全部」+ 有繳交紀錄的課程，每個課程分頁標題帶該課程的繳交數。
 * 切換分頁同時切換列表與統計卡的範圍（`features/assignment/查詢有繳交紀錄的課程.feature`）。
 */
import { __ } from '@wordpress/i18n'
import { Tabs } from 'antd'
import { memo, useMemo } from 'react'

import { COURSE_TAB_ALL, type TCourseTabsProps } from '../types'

/**
 * 課程分頁元件
 */
const CourseTabsComponent = ({
	courses,
	activeKey,
	onChange,
}: TCourseTabsProps) => {
	const items = useMemo(
		() => [
			{ key: COURSE_TAB_ALL, label: __('All', 'power-course') },
			...courses.map((course) => ({
				key: String(course.id),
				label: `${course.title} (${course.count})`,
			})),
		],
		[courses]
	)

	return <Tabs activeKey={activeKey} onChange={onChange} items={items} />
}

export const CourseTabs = memo(CourseTabsComponent)
