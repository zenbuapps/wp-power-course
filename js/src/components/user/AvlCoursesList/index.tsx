import { FieldTimeOutlined } from '@ant-design/icons'
import { __, sprintf } from '@wordpress/i18n'
import { Button, Progress, Typography } from 'antd'
import { useSetAtom } from 'jotai'
import React, { FC } from 'react'

import { WatchStatusTag, getWatchStatusTagTooltip } from '@/components/general'
import { TUserRecord, TAVLCourse } from '@/components/user/types'
import { historyDrawerAtom } from '@/components/user/UserTable/atom'

const { Text } = Typography

/**
 * 已授權課程列表元件（純展示）
 *
 * Issue #226 改造：把「顯示全部」switch 上提到 useColumns 的表頭，
 * 本元件不再持有 state，改由外部以 showAllCourses prop 控制。
 *
 * 兩處使用：
 * 1. UserTable 的「Granted courses」欄位（在 Course Edit 頁時，由 useColumns 的 useState 控制 showAllCourses）
 * 2. Teacher Edit 頁的 Learning Tab（不傳 currentCourseId → 內部 fallback 永遠展全部）
 *
 * 渲染規則：
 * - 沒有 currentCourseId：永遠展全部已授權課程
 * - 有 currentCourseId 且 showAllCourses=true：展全部
 * - 有 currentCourseId 且 showAllCourses=false：只顯示該課程
 */
export const AvlCoursesList: FC<{
	record: TUserRecord
	/**
	 * 可選：當頁 Course edit 頁的課程 ID，用來預設只顯示這堂課
	 */
	currentCourseId?: string | number
	/**
	 * 是否展開全部已授權課程（由外部控制；不傳則預設 false）
	 * - 若 currentCourseId 不存在：永遠展全部（忽略此 prop）
	 * - 若 currentCourseId 存在且 showAllCourses=true：展全部
	 * - 若 currentCourseId 存在且 showAllCourses=false：只顯示該課程
	 */
	showAllCourses?: boolean
}> = ({ record, currentCourseId, showAllCourses = false }) => {
	const setHistoryDrawerProps = useSetAtom(historyDrawerAtom)

	const { id: user_id, formatted_name, display_name } = record
	const avl_courses = (record?.avl_courses ?? []) as TAVLCourse[]

	const filtered_avl_courses =
		showAllCourses || !currentCourseId
			? avl_courses
			: avl_courses.filter(
					(course) => String(course.id) === String(currentCourseId)
				)

	if (!filtered_avl_courses.length) {
		return (
			<p className="m-0 text-gray-300 text-xs">
				{__('No granted courses', 'power-course')}
			</p>
		)
	}

	return (
		<>
			{filtered_avl_courses.map(
				({
					id: course_id,
					name: course_name,
					expire_date,
					progress,
					total_chapters_count,
					finished_chapters_count,
				}) => (
					<div
						key={course_id}
						className="grid grid-cols-[minmax(10rem,_1fr)_6rem_4rem_12rem_6rem_10rem] gap-1 my-1"
					>
						<div>
							<Text
								className="cursor-pointer"
								ellipsis={{
									tooltip: (
										<>
											<span className="text-gray-400 text-xs">
												#{course_id}
											</span>{' '}
											{course_name || __('Unknown course name', 'power-course')}
										</>
									),
								}}
								onClick={() => {
									setHistoryDrawerProps({
										user_id,
										user_name: formatted_name || display_name,
										course_id,
										course_name,
										drawerProps: {
											open: true,
										},
									})
								}}
							>
								<span className="text-gray-400 text-xs">#{course_id}</span>{' '}
								{course_name || __('Unknown course name', 'power-course')}
							</Text>
						</div>

						<div className="text-center">
							<Button
								className="text-xs"
								color="primary"
								size="small"
								variant="filled"
								icon={<FieldTimeOutlined />}
								iconPosition="end"
								onClick={() => {
									setHistoryDrawerProps({
										user_id,
										user_name: formatted_name || display_name,
										course_id,
										course_name,
										drawerProps: {
											open: true,
										},
									})
								}}
							>
								{__('Learning history', 'power-course')}
							</Button>
						</div>

						<div className="text-center">
							<WatchStatusTag expireDate={expire_date} />
						</div>

						<div className="text-left">
							{getWatchStatusTagTooltip(expire_date)}
						</div>
						<div>
							<Progress percent={progress} size="small" showInfo={false} />
						</div>

						<div className="text-xs flex items-center justify-between">
							<span>{progress}%</span>
							<span>
								{sprintf(
									// translators: 1: 已完成單元數, 2: 總單元數
									__('%1$s completed / %2$s lessons', 'power-course'),
									finished_chapters_count,
									total_chapters_count
								)}
							</span>
						</div>
					</div>
				)
			)}
		</>
	)
}
