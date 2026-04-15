import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Space, DatePicker, Button, message } from 'antd'
import { Dayjs } from 'dayjs'
import React, { useState, memo } from 'react'

const ModifyCourseExpireDateComponent = ({
	user_ids,
	course_ids,
	onSettled,
}: {
	user_ids: string[]
	course_ids: string[]
	onSettled: () => void
}) => {
	const [time, setTime] = useState<Dayjs | undefined>(undefined)
	const { mutate, isLoading } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	const handleUpdate = () => () => {
		mutate(
			{
				url: `${apiUrl}/courses/update-students`,
				method: 'post',
				values: {
					user_ids,
					course_ids,
					timestamp: time ? time?.unix() : 0,
				},
				config: {
					headers: {
						'Content-Type': 'multipart/form-data;',
					},
				},
			},
			{
				onSuccess: () => {
					message.success({
						content: __(
							'Batch modify expire date successfully',
							'power-course'
						),
						key: 'update-students',
					})
					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
					setTime(undefined)
				},
				onError: () => {
					message.error({
						content: __('Failed to batch modify expire date', 'power-course'),
						key: 'update-students',
					})
				},
				onSettled: () => {
					onSettled()
				},
			}
		)
	}

	return (
		<Space.Compact>
			<DatePicker
				value={time}
				showTime
				placeholder={__('Leave empty for unlimited', 'power-course')}
				format="YYYY-MM-DD HH:mm"
				onChange={(value: Dayjs) => {
					setTime(value)
				}}
				disabled={isLoading}
			/>
			<Button
				type="primary"
				disabled={!user_ids.length || !course_ids.length}
				onClick={handleUpdate()}
				ghost
				loading={isLoading}
			>
				{__('Modify expire date', 'power-course')}
			</Button>
		</Space.Compact>
	)
}

export const ModifyCourseExpireDate = memo(ModifyCourseExpireDateComponent)
