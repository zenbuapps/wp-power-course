import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { Select, Button, Space, message, Form } from 'antd'
import React, { memo } from 'react'
import { __ } from '@wordpress/i18n'

import { useCourseSelect } from '@/hooks'
import { TCoursesLimit } from '@/pages/admin/Courses/List/types'

const BindCoursesComponent = ({
	product_ids,
	label,
}: {
	product_ids: string[]
	label?: string
}) => {
	const { selectProps, courseIds: course_ids } = useCourseSelect()

	const { mutate, isLoading } = useCustomMutation()
	const apiUrl = useApiUrl('power-course')
	const invalidate = useInvalidate()
	const form = Form.useFormInstance()

	const handleClick = () => {
		const values: TCoursesLimit = form.getFieldsValue()
		mutate(
			{
				url: `${apiUrl}/products/bind-courses`,
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
						content: __('Courses bound successfully', 'power-course'),
						key: 'bind-courses',
					})
					invalidate({
						resource: 'products',
						dataProviderName: 'power-course',
						invalidates: ['list'],
					})
				},
				onError: () => {
					message.error({
						content: __('Failed to bind courses', 'power-course'),
						key: 'bind-courses',
					})
				},
			}
		)
	}

	return (
		<>
			{label && <label className="tw-block mb-2">{label}</label>}
			<Space.Compact className="w-full">
				<Select {...selectProps} />
				<Button
					type="primary"
					loading={isLoading}
					disabled={!product_ids.length || !course_ids.length}
					onClick={handleClick}
				>
					{__('Bind other courses', 'power-course')}
				</Button>
			</Space.Compact>
		</>
	)
}

export const BindCourses = memo(BindCoursesComponent)
