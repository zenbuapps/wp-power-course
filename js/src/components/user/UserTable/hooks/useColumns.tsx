import { FieldTimeOutlined } from '@ant-design/icons'
import { useParsed } from '@refinedev/core'
import { __, sprintf } from '@wordpress/i18n'
import { TableProps, Typography, Button, Progress, Switch, Tooltip } from 'antd'
import { useSetAtom } from 'jotai'
import React, { useState } from 'react'

import { WatchStatusTag, getWatchStatusTagTooltip } from '@/components/general'
import { UserName } from '@/components/user'
import { TUserRecord, TAVLCourse } from '@/components/user/types'

import { historyDrawerAtom } from '../atom'

type TUseColumnsParams = {
	onClick?: (_record: TUserRecord | undefined) => () => void
}

const { Text } = Typography

const useColumns = (params?: TUseColumnsParams) => {
	const setHistoryDrawerProps = useSetAtom(historyDrawerAtom)
	const handleClick = params?.onClick
	const [showAllCourses, setShowAllCourses] = useState(false)
	const { id: currentCourseId } = useParsed()
	const columns: TableProps<TUserRecord>['columns'] = [
		{
			title: __('Student', 'power-course'),
			dataIndex: 'id',
			width: 180,
			render: (_, record) => <UserName record={record} onClick={handleClick} />,
		},
		{
			title: (
				<>
					{__('Granted courses', 'power-course')}{' '}
					{currentCourseId && (
						<Tooltip
							title={__('Show all courses granted to the user', 'power-course')}
						>
							<Switch
								checked={showAllCourses}
								onChange={(checked) => setShowAllCourses(checked)}
								size="small"
							/>
						</Tooltip>
					)}
				</>
			),
			dataIndex: 'avl_courses',
			width: 240,
			render: (
				avl_courses: TAVLCourse[],
				{ id: user_id, formatted_name, display_name }
			) => {
				const filtered_avl_courses =
					showAllCourses || !currentCourseId
						? avl_courses
						: avl_courses.filter(
								(course) => String(course.id) === String(currentCourseId)
							)

				return filtered_avl_courses.map(
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
												{course_name ||
													__('Unknown course name', 'power-course')}
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
				)
			},
		},
		{
			title: __('Registered at', 'power-course'),
			dataIndex: 'user_registered',
			width: 180,
			render: (user_registered, record) => (
				<>
					<p className="m-0">
						{sprintf(
							// translators: %s: 相對時間，如「3天前」
							__('Registered %s', 'power-course'),
							record?.user_registered_human
						)}
					</p>
					<p className="m-0 text-gray-400 text-xs">{user_registered}</p>
				</>
			),
		},
	]

	return columns
}

export default useColumns
