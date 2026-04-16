import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { __ } from '@wordpress/i18n'
import { Button, message, Form } from 'antd'
import React, { memo } from 'react'

import { TCoursesLimit } from '@/pages/admin/Courses/List/types'

const UpdateBoundCoursesComponent = ({
	product_ids,
	course_ids,
	onSettled,
}: {
	product_ids: string[]
	course_ids: string[]
	onSettled: () => void
}) => {
	const { mutate, isLoading } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const form = Form.useFormInstance()

	const handleUpdate = () => () => {
		const values: TCoursesLimit = form.getFieldsValue()
		mutate(
			{
				url: `${apiUrl}/products/update-bound-courses`,
				method: 'post',
				values: {
					product_ids,
					course_ids,
					...values,
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
						key: 'update-bound-courses',
					})
					invalidate({
						resource: 'products',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to batch modify expire date', 'power-course'),
						key: 'update-bound-courses',
					})
				},
				onSettled: () => {
					onSettled()
				},
			}
		)
	}

	return (
		<Button
			type="primary"
			disabled={!product_ids.length || !course_ids.length}
			onClick={handleUpdate()}
			ghost
			loading={isLoading}
		>
			{__('Modify expire date', 'power-course')}
		</Button>
	)
}

export const UpdateBoundCourses = memo(UpdateBoundCoursesComponent)
