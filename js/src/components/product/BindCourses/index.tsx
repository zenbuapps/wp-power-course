import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { Select, Button, Space, message, Form } from 'antd'
import React, { memo } from 'react'

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
						content: '綁定課程成功！',
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
						content: '綁定課程失敗！',
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
					綁定其他課程
				</Button>
			</Space.Compact>
		</>
	)
}

export const BindCourses = memo(BindCoursesComponent)
