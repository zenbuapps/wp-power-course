import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
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
						content: '批次修改觀看期限成功！',
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
						content: '批次修改觀看期限失敗！',
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
			修改觀看期限
		</Button>
	)
}

export const UpdateBoundCourses = memo(UpdateBoundCoursesComponent)
