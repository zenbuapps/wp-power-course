import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { message } from 'antd'
import React, { memo } from 'react'
import { __ } from '@wordpress/i18n'

import { PopconfirmDelete } from '@/components/general'

const UnbindCoursesComponent = ({
	product_ids,
	course_ids,
	onSettled,
}: {
	product_ids: string[]
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
				url: `${apiUrl}/products/unbind-courses`,
				method: 'post',
				values: {
					product_ids,
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
						content: __('Unbound successfully', 'power-course'),
						key: 'unbind-courses',
					})
					invalidate({
						resource: 'products',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to unbind', 'power-course'),
						key: 'unbind-courses',
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
					'Are you sure to unbind courses from these products?',
					'power-course'
				),
				onConfirm: handleRemove,
			}}
			buttonProps={{
				children: __('Unbind', 'power-course'),
				disabled: !product_ids.length || !course_ids.length,
				loading: isLoading,
			}}
		/>
	)
}

export const UnbindCourses = memo(UnbindCoursesComponent)
