import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { message } from 'antd'
import React, { memo } from 'react'
import { __ } from '@wordpress/i18n'

import { PopconfirmDelete } from '@/components/general'

const RemoveCourseAccessComponent = ({
	user_ids,
	course_ids,
	onSettled,
}: {
	user_ids: React.Key[]
	course_ids: string[]
	onSettled: () => void
}) => {
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()

	// remove student mutation
	const { mutate, isLoading } = useCustomMutation()

	const handleRemove = () => {
		mutate(
			{
				url: `${apiUrl}/courses/remove-students`,
				method: 'post',
				values: {
					user_ids,
					course_ids,
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
						content: __('Student removed successfully', 'power-course'),
						key: 'remove-students',
					})
					invalidate({
						resource: 'users',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to remove student', 'power-course'),
						key: 'remove-students',
					})
				},
				onSettled: () => {
					onSettled()
				},
			}
		)
	}

	return (
		<PopconfirmDelete
			type="button"
			popconfirmProps={{
				title: __(
					'Are you sure to remove course access from these users?',
					'power-course'
				),
				onConfirm: handleRemove,
			}}
			buttonProps={{
				children: __('Remove course', 'power-course'),
				disabled: !user_ids.length || !course_ids.length,
				loading: isLoading,
			}}
		/>
	)
}

export const RemoveCourseAccess = memo(RemoveCourseAccessComponent)
