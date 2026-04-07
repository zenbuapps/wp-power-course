import { useCustomMutation, useApiUrl, useInvalidate } from '@refinedev/core'
import { message } from 'antd'
import React, { memo } from 'react'

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
						content: '解除綁定成功！',
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
						content: '解除綁定失敗！',
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
				title: '確認解除這些商品的課程綁定嗎?',
				onConfirm: handleRemove,
			}}
			buttonProps={{
				children: '解除綁定',
				disabled: !product_ids.length || !course_ids.length,
				loading: isLoading,
			}}
		/>
	)
}

export const UnbindCourses = memo(UnbindCoursesComponent)
